<?php

namespace App\Actions\Spt;

use App\Enums\KejadianSpt;
use App\Enums\StatusPengajuan;
use App\Enums\StatusSpt;
use App\Models\Spt;
use App\Models\User;
use App\Support\DiinputOleh;
use App\Support\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Realisasi dana per SPT (versi 1.3 K4-K6): the owning Irban fills it once the implementation period has ended (Penyusunan Laporan or Selesai).
 * cegah() is the state rule shared by the detail page and handle(); terapkan() is the write + riwayat, also used inside TandaiSelesai's transaction.
 * Errors: `realisasi_dana` (format, limit, unchanged), `diinput_oleh`, `spt` (state; toast).
 */
class UbahRealisasiDana
{
    public const DIBATALKAN = 'SPT yang sudah Dibatalkan tidak dapat diubah realisasi dananya.';

    public const BELUM_TERBIT = 'Realisasi dana hanya dapat diisi untuk SPT yang nomornya sudah terbit.';

    public const BELUM_BERAKHIR = 'Realisasi dana dapat diisi setelah pelaksanaan berakhir.';

    public const TIDAK_BERUBAH = 'Realisasi dana tidak berubah.';

    /** Non-throwing state rule for the UI and handle(); null = may be edited. */
    public function cegah(Spt $spt): ?string
    {
        if ($spt->status_pengajuan === StatusPengajuan::Dibatalkan) {
            return self::DIBATALKAN;
        }
        if ($spt->status_pengajuan !== StatusPengajuan::Diterima) {
            return self::BELUM_TERBIT;
        }

        return in_array($spt->status(), [StatusSpt::PenyusunanLaporan, StatusSpt::Selesai], true) ? null : self::BELUM_BERAKHIR;
    }

    /** Parsed value or a `realisasi_dana` ValidationException carrying the exact message. */
    public static function baca(mixed $nilai): ?int
    {
        try {
            return Rupiah::baca($nilai);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['realisasi_dana' => $e->getMessage()]);
        }
    }

    /** @param  array{realisasi_dana?: mixed, diinput_oleh?: mixed}  $data */
    public function handle(User $user, Spt $spt, array $data): Spt
    {
        Gate::forUser($user)->authorize('ubahDana', $spt);
        $this->periksa($spt); // a stale tab gets the state message before any field error
        $e = [];
        try {
            $dana = self::baca($data['realisasi_dana'] ?? null);
        } catch (ValidationException $ex) {
            $e = $ex->errors();
        }
        $diinput = filter_var($data['diinput_oleh'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($galat = DiinputOleh::galat($user, $diinput)) {
            $e['diinput_oleh'] = [$galat];
        }
        if ($e) {
            throw ValidationException::withMessages($e);
        }
        DiinputOleh::catat($diinput);

        $spt = DB::transaction(function () use ($user, $spt, $dana, $diinput) {
            $spt = Spt::whereKey($spt->id)->lockForUpdate()->firstOrFail();
            $this->periksa($spt);
            if (! $this->terapkan($user, $spt, $dana, $diinput)) {
                throw ValidationException::withMessages(['realisasi_dana' => self::TIDAK_BERUBAH]);
            }

            return $spt;
        });
        DiinputOleh::ingat($user, $diinput);

        return $spt;
    }

    /** Writes the value on an SPT the caller has locked; no change = no riwayat. Returns whether it changed. */
    public function terapkan(User $user, Spt $spt, ?int $dana, ?int $diinput): bool
    {
        $lama = $spt->realisasi_dana;
        if ($lama === $dana) {
            return false;
        }
        $spt->forceFill(['realisasi_dana' => $dana])->save();
        $spt->riwayat()->create([
            'kejadian' => KejadianSpt::DANA_DIUBAH, 'nilai_lama' => ['realisasi_dana' => $lama], 'nilai_baru' => ['realisasi_dana' => $dana],
            'oleh' => $user->id, 'diinput_oleh' => $diinput,
        ]);

        return true;
    }

    private function periksa(Spt $spt): void
    {
        if ($pesan = $this->cegah($spt)) {
            throw ValidationException::withMessages(['spt' => $pesan]);
        }
    }
}
