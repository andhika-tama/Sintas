<?php

namespace App\Actions\Spt;

use App\Actions\Spt\Concerns\MelaksanakanSpt;
use App\Enums\KejadianSpt;
use App\Enums\SyaratSelesai;
use App\Models\Spt;
use App\Models\User;
use App\Services\PenandaSpt;
use App\Services\Settings;
use App\Support\BerkasSpt;
use App\Support\DiinputOleh;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Irban marks an SPT Selesai; what is required follows the klasifikasi's `syarat_selesai` at that moment (PRD §9.4).
 *
 * handle(User, Spt, array{diinput_oleh, konfirmasi?, nomor_lhp?, tanggal_lhp?, realisasi_dana?}, ?UploadedFile $berkas = null): Spt
 * A filled `realisasi_dana` that differs from the stored one is saved (with its own riwayat) in the same transaction; empty leaves it as is.
 * The UI reads syarat() and bisa() instead of recomputing the rules.
 * Errors: `spt` (blocked or already Selesai), `berkas`, `konfirmasi`, `nomor_lhp`, `tanggal_lhp`, `realisasi_dana`, `diinput_oleh`.
 */
class TandaiSelesai
{
    use MelaksanakanSpt;

    public function __construct(private PenandaSpt $penanda, private Settings $settings, private UbahRealisasiDana $dana) {}

    public function syarat(Spt $spt): SyaratSelesai
    {
        return $spt->klasifikasi()->firstOrFail()->syarat_selesai;
    }

    /** Non-throwing: null = allowed, else the message that blocks it (date and kendala rules). */
    public function bisa(Spt $spt): ?string
    {
        if ($spt->tanggal_mulai === null || now('Asia/Jakarta')->toDateString() < $spt->tanggal_mulai->toDateString()) {
            return 'SPT belum dilaksanakan.';
        }

        return $this->penanda->perluKendala($spt) ? 'Isi kendala terlebih dahulu karena SPT Tidak Sesuai PKPT.' : null;
    }

    public function handle(User $user, Spt $spt, array $data, ?UploadedFile $berkas = null): Spt
    {
        $this->izinkan($user, $spt);

        $path = null;
        try {
            [$spt, $diinput] = DB::transaction(function () use ($user, $spt, $data, $berkas, &$path) {
                $spt = $this->kunci($user, $spt);
                if ($pesan = $this->bisa($spt)) {
                    throw ValidationException::withMessages(['spt' => $pesan]);
                }
                // Read under the lock, so an Admin edit of the syarat applies to this very call.
                $syarat = $this->syarat($spt);
                $e = [];
                $diinput = $this->penginput($user, $data, $e);
                $kolom = $this->periksa($syarat, $spt, $data, $berkas, $e);
                try {
                    $danaBaru = UbahRealisasiDana::baca($data['realisasi_dana'] ?? null);
                } catch (ValidationException $ex) {
                    $e += array_map(fn ($m) => $m[0], $ex->errors());
                }
                if ($e) {
                    throw ValidationException::withMessages($e);
                }
                if ($berkas && $syarat !== SyaratSelesai::Konfirmasi) {
                    $path = BerkasSpt::simpan($spt, $berkas, $syarat === SyaratSelesai::LhpLengkap ? 'cover_lhp' : 'laporan', $user, $diinput)->path;
                }
                $spt->forceFill([
                    'nomor_lhp' => $kolom['nomor_lhp'] ?? $spt->nomor_lhp, 'tanggal_lhp' => $kolom['tanggal_lhp'] ?? $spt->tanggal_lhp,
                    'ditandai_selesai_pada' => now(), 'ditandai_selesai_oleh' => $user->id,
                ])->save();
                $spt->riwayat()->create([
                    'kejadian' => KejadianSpt::DITANDAI_SELESAI, 'oleh' => $user->id, 'diinput_oleh' => $diinput,
                    'nilai_baru' => ['syarat' => $syarat->value, 'nomor_lhp' => $kolom['nomor_lhp'] ?? null, 'tanggal_lhp' => $kolom['tanggal_lhp'] ?? null],
                ]);
                if ($danaBaru !== null) {
                    $this->dana->terapkan($user, $spt, $danaBaru, $diinput);
                }

                return [$spt, $diinput];
            });
        } catch (\Throwable $ex) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $ex;
        }
        DiinputOleh::ingat($user, $diinput);

        return $spt;
    }

    /**
     * @param  array<string, string>  $e
     * @return array{nomor_lhp?: string, tanggal_lhp?: string} validated optional columns
     */
    private function periksa(SyaratSelesai $syarat, Spt $spt, array $d, ?UploadedFile $berkas, array &$e): array
    {
        if ($syarat === SyaratSelesai::Konfirmasi) {
            if (! filter_var($d['konfirmasi'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $e['konfirmasi'] = 'Centang konfirmasi bahwa penugasan telah selesai.';
            }

            return [];
        }

        $wajib = $syarat === SyaratSelesai::LhpLengkap;
        $maks = (int) $this->settings->get('upload.maks_mb');
        if ($berkas && ! BerkasSpt::pdf($berkas, $maks * 1024 * 1024)) {
            $e['berkas'] = "Dokumen harus PDF dan maksimal {$maks} MB.";
        } elseif (! $berkas && $wajib) {
            $e['berkas'] = 'Unggah cover LHP (PDF).';
        }

        $kolom = [];
        $nomor = trim((string) ($d['nomor_lhp'] ?? ''));
        if ($nomor === '' && $wajib) {
            $e['nomor_lhp'] = 'Isi nomor LHP.';
        } elseif (mb_strlen($nomor) > 100) {
            $e['nomor_lhp'] = 'Nomor LHP maksimal 100 karakter.';
        } elseif ($nomor !== '') {
            $kolom['nomor_lhp'] = $nomor;
        }

        $tanggal = is_string($d['tanggal_lhp'] ?? null) ? $d['tanggal_lhp'] : '';
        if ($tanggal === '') {
            if ($wajib) {
                $e['tanggal_lhp'] = 'Isi tanggal LHP.';
            }
        } elseif (rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $tanggal), null, false)?->toDateString() !== $tanggal) {
            // Parsed once; the round trip refuses non-canonical input and roll-overs (2026-02-31).
            $e['tanggal_lhp'] = 'Isi tanggal LHP.';
        } elseif ($tanggal < $spt->tanggal_mulai->toDateString()) {
            $e['tanggal_lhp'] = 'Tanggal LHP tidak boleh sebelum tanggal mulai SPT.';
        } elseif ($tanggal > now('Asia/Jakarta')->toDateString()) {
            $e['tanggal_lhp'] = 'Tanggal LHP tidak boleh di masa depan.';
        } else {
            $kolom['tanggal_lhp'] = $tanggal;
        }

        return $kolom;
    }
}
