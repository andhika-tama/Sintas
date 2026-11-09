<?php

namespace App\Actions\Spt;

use App\Enums\KejadianSpt;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Enums\SumberSpt;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use App\Notifications\PengajuanBaru;
use App\Services\KesesuaianPkpt;
use App\Services\PenerimaNotifikasi;
use App\Services\Settings;
use App\Support\BerkasSpt;
use App\Support\CachePublik;
use App\Support\DiinputOleh;
use App\Support\SinkronBaris;
use App\Support\Tanggal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Irban saves a pengajuan SPT as draf or submits it (PRD §5, §7, §9.2).
 *
 * handle(User $user, ?Spt $spt, array $data, 'draf'|'ajukan', ?UploadedFile $lampiran = null, bool $hapusLampiran = false): Spt
 *  $data: klasifikasi_id, kode_arsip_id, pkpt_item_id, non_pkpt (bool, informational: no item is the same thing), jenis_penugasan_id, objek_ids (int[]),
 *         jenis_penugasan_lainnya, objek_lainnya (free text, non-PKPT only, see bolehLainnya; a master jenis id wins over the jenis text),
 *         periode_diperiksa, uraian_untuk, tanggal_mulai, tanggal_selesai (Y-m-d), ppj_pegawai_id, catatan_bagian_umum,
 *         diinput_oleh (pegawai id), personel: ordered list of [pegawai_id, peran, sumber 'tim'|'tambahan', tim_id|null, ikut bool]
 *         (ikut=false rows are dropped before validation and storage).
 * Failures throw ValidationException keyed by field ('personel', 'lampiran', and 'spt' for a locked SPT included).
 * After handle(), $peringatan holds the non-blocking warnings (also available live through peringatan($data, $irbanId)).
 */
class SimpanSpt
{
    public const MAKS_LAMPIRAN = 5 * 1024 * 1024;

    public const ITEM_TIDAK_VALID = 'Item PKPT tidak valid untuk Irban ini.';

    /** @var list<string> */
    public array $peringatan = [];

    public function __construct(private KesesuaianPkpt $kesesuaian, private ValidatePersonel $validatePersonel, private Settings $settings) {}

    public function handle(User $user, ?Spt $spt, array $data, string $mode, ?UploadedFile $lampiran = null, bool $hapusLampiran = false): Spt
    {
        $ajukan = $mode === 'ajukan';
        if ($spt) {
            Gate::forUser($user)->authorize('view', $spt);
            $this->pastikanBisaDiubah($spt);
            Gate::forUser($user)->authorize('update', $spt);
        } else {
            Gate::forUser($user)->authorize('create', Spt::class);
        }

        [$kolom, $objekIds, $personel] = $this->validasi($user, $user->irban_id, $spt, $data, $ajukan);
        $this->cekLampiran($lampiran);
        $this->peringatan = $this->peringatan($data, $user->irban_id);

        $path = null;
        // PKPT lock only when the save links an item the SPT row does not hold yet (see kunciItem); the caller's copy is the first guess.
        $itemBaru = $kolom['pkpt_item_id'] ?? null;
        $kunci = $itemBaru !== null && $itemBaru !== $spt?->pkpt_item_id;
        try {
            $simpan = function () use ($user, $spt, $kolom, $objekIds, $personel, $ajukan, $lampiran, $hapusLampiran, $itemBaru, &$kunci, &$path) {
                if ($kunci) {
                    $this->kunciItem($user->irban_id, $kolom);
                }
                if ($spt) {
                    $spt = Spt::whereKey($spt->id)->lockForUpdate()->firstOrFail();
                    $this->pastikanBisaDiubah($spt);
                    if (! $kunci && $itemBaru !== null && $spt->pkpt_item_id !== $itemBaru) {
                        return null; // another tab changed the item meanwhile: nothing written yet, retry with the PKPT lock first
                    }
                }
                $baru = ! $spt;
                $spt ??= (new Spt)->forceFill(['irban_id' => $user->irban_id, 'dibuat_oleh' => $user->id, 'status_pengajuan' => StatusPengajuan::Draf, 'sumber' => SumberSpt::Sistem]);
                $lama = $spt->status_pengajuan;
                $spt->fill($kolom);
                if ($ajukan) {
                    $spt->forceFill(['status_pengajuan' => StatusPengajuan::MenungguNomor, 'diajukan_pada' => now()]);
                }
                $spt->save();

                $objekLama = $baru ? collect() : $spt->objek()->pluck('objek.nama', 'objek.id');
                $this->catatObjek($user, $spt, $objekLama, $spt->objek()->sync($objekIds));
                DB::afterCommit(fn () => CachePublik::segarkan()); // spt_objek is a pivot: no model event
                SinkronBaris::handle($spt->personel(), $personel);

                $dihapus = collect();
                if ($lampiran || $hapusLampiran) {
                    $dihapus = $spt->dokumen()->where('jenis', 'draf')->get();
                    $dihapus->each->delete();
                }
                if ($lampiran) {
                    $path = BerkasSpt::simpan($spt, $lampiran, 'draf', $user, $kolom['diinput_oleh'])->path;
                }

                $kejadian = $baru ? [KejadianSpt::DIBUAT] : [];
                if ($ajukan) {
                    $kejadian[] = KejadianSpt::DIAJUKAN;
                } elseif (! $baru) {
                    $kejadian[] = KejadianSpt::DIUBAH;
                }
                foreach ($kejadian as $k) {
                    $spt->riwayat()->create([
                        'kejadian' => $k, 'nilai_lama' => $baru || $k === KejadianSpt::DIBUAT ? null : ['status_pengajuan' => $lama->value],
                        'nilai_baru' => ['status_pengajuan' => $spt->status_pengajuan->value], 'oleh' => $user->id, 'diinput_oleh' => $kolom['diinput_oleh'],
                    ]);
                }

                return [$spt, $dihapus];
            };
            $hasil = DB::transaction($simpan);
            if ($hasil === null) {
                $kunci = true;
                $hasil = DB::transaction($simpan);
            }
            [$spt, $dihapus] = $hasil;
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        foreach ($dihapus as $d) {
            Storage::disk('local')->delete($d->path);
        }
        DiinputOleh::ingat($user, $kolom['diinput_oleh']);

        $spt->refresh();
        // Only draf / perlu revisi can be saved, so every submit is the transition into menunggu_nomor.
        if ($ajukan) {
            Notification::send(app(PenerimaNotifikasi::class)->bagianUmum(), new PengajuanBaru($spt));
        }

        return $spt;
    }

    /**
     * Non-blocking warnings for the form (live) and for the save result.
     *
     * @return list<string>
     */
    public function peringatan(array $data, int $irbanId): array
    {
        $item = PkptItem::aktif()->with('pkpt')->where('irban_id', $irbanId)->find($this->int($data['pkpt_item_id'] ?? null));
        if (! $item) {
            return [];
        }
        $w = [];
        if ($item->pkpt->isDraf()) {
            $w[] = 'PKPT belum disahkan.';
        }
        $mulai = $this->tanggal($data['tanggal_mulai'] ?? null);
        $selesai = $this->tanggal($data['tanggal_selesai'] ?? null);
        if ($mulai && $selesai && ! $this->kesesuaian->sesuai($item, $mulai, $selesai)) {
            [$m, $s] = $this->kesesuaian->rentang($item);
            $w[] = 'Di luar jadwal PKPT ('.Tanggal::rentang($m, $s).'). SPT akan ditandai Tidak Sesuai PKPT.';
        }

        return $w;
    }

    /**
     * K7 against a concurrent Perubahan PKPT approval: lock the PKPT row (approval locks it too, always first) and re-read the item,
     * so an approval that removed the item or changed its irban/jenis cannot commit in the same window as this SPT.
     * Skipped when the SPT row (read under its lock) already holds this item: that committed reference makes the approval's cek() refuse
     * K7 by itself, and the first save that linked the item took this lock. jenis_penugasan_id always follows the item (validasi).
     */
    private function kunciItem(int $irbanId, array $kolom): void
    {
        $pkptId = PkptItem::whereKey($kolom['pkpt_item_id'])->value('pkpt_id');
        Pkpt::whereKey($pkptId)->lockForUpdate()->first();
        $ada = PkptItem::aktif()->whereKey($kolom['pkpt_item_id'])->where('irban_id', $irbanId)
            ->where('jenis_penugasan_id', $kolom['jenis_penugasan_id'] ?? null)->exists();
        if (! $ada) {
            throw ValidationException::withMessages(['pkpt_item_id' => self::ITEM_TIDAK_VALID]);
        }
    }

    /** 1.2 K3: free-text "Lainnya" jenis/objek only on a non-PKPT SPT: no item, under a klasifikasi that does not require one. Used by the form too. */
    public static function bolehLainnya(?Klasifikasi $klasifikasi, bool $adaItem): bool
    {
        return $klasifikasi !== null && ! $adaItem && ! $klasifikasi->wajib_item_pkpt;
    }

    private function pastikanBisaDiubah(Spt $spt): void
    {
        if (! in_array($spt->status_pengajuan, [StatusPengajuan::Draf, StatusPengajuan::PerluRevisi], true)) {
            throw ValidationException::withMessages(['spt' => 'SPT ini sudah dikirim dan tidak dapat diubah.']);
        }
    }

    private function int(mixed $v): ?int
    {
        return filter_var($v, FILTER_VALIDATE_INT) ?: null;
    }

    /** Trimmed text, '' as null. */
    private function teks(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : $v;
    }

    private function tanggal(mixed $v): ?CarbonImmutable
    {
        return is_string($v) && CarbonImmutable::canBeCreatedFromFormat($v, 'Y-m-d') ? CarbonImmutable::createFromFormat('Y-m-d', $v)->startOfDay() : null;
    }

    private function cekLampiran(?UploadedFile $f): void
    {
        if ($f && ! BerkasSpt::pdf($f, self::MAKS_LAMPIRAN)) {
            throw ValidationException::withMessages(['lampiran' => 'Lampiran harus PDF dan maksimal 5 MB.']);
        }
    }

    /**
     * spt_objek is a pivot (no model event): write the diff row ourselves, only when the set changed (E7). Runs after DiinputOleh::catat in validasi().
     * The sync result says what changed, so the new names come from $lama and the attached ids without re-reading the pivot.
     *
     * @param  Collection<int, string>  $lama  nama by objek id before the sync
     */
    private function catatObjek(User $user, Spt $spt, Collection $lama, array $sync): void
    {
        if (! $sync['attached'] && ! $sync['detached']) {
            return;
        }
        $baru = $lama->except($sync['detached'])->union($sync['attached'] ? Objek::whereIn('id', $sync['attached'])->pluck('nama', 'id') : [])->values()->all();
        $lama = $lama->values()->all();
        sort($lama, SORT_NATURAL | SORT_FLAG_CASE);
        sort($baru, SORT_NATURAL | SORT_FLAG_CASE);
        activity()->performedOn($spt)->causedBy($user)->event('updated')->withProperties(['old' => ['objek' => $lama], 'attributes' => ['objek' => $baru]])->log('updated');
    }

    /** @return array{0: array<string, mixed>, 1: list<int>, 2: list<array>} columns for the spt row, objek ids, normalized personel */
    private function validasi(User $user, int $irbanId, ?Spt $spt, array $d, bool $ajukan): array
    {
        $e = [];
        $diinput = $this->int($d['diinput_oleh'] ?? null);
        if ($user->role === Role::Irban) {
            if ($galat = DiinputOleh::galat($user, $diinput)) {
                $e['diinput_oleh'] = $galat;
            } else {
                DiinputOleh::catat($diinput);
            }
        }

        $klasifikasi = Klasifikasi::find($this->int($d['klasifikasi_id'] ?? null));
        if (! $klasifikasi) {
            $e['klasifikasi_id'] = 'Pilih klasifikasi.';
        }
        // K4: the Irban picks the kode arsip; required on submit, an unknown id counts as none.
        $kodeArsip = KodeArsip::find($this->int($d['kode_arsip_id'] ?? null))?->id;
        if ($ajukan && ! $kodeArsip) {
            $e['kode_arsip_id'] = 'Pilih kode arsip.';
        }

        // Non-PKPT is "no item chosen"; it is only accepted when the klasifikasi does not require an item.
        $item = null;
        if ($itemId = $this->int($d['pkpt_item_id'] ?? null)) {
            $item = PkptItem::aktif()->with('pkpt')->where('irban_id', $irbanId)->find($itemId);
            if (! $item) {
                $e['pkpt_item_id'] = self::ITEM_TIDAK_VALID;
            }
        }
        if ($klasifikasi && $ajukan && ! $item && ! isset($e['pkpt_item_id']) && $klasifikasi->wajib_item_pkpt) {
            $e['pkpt_item_id'] = 'Pilih item PKPT untuk SPT Reguler.';
        }
        if ($ajukan && $item && $item->pkpt->isDraf() && $this->settings->get('pkpt.draf_memblokir')) {
            $e['pkpt_item_id'] = 'PKPT belum disahkan.';
        }

        $lainnya = self::bolehLainnya($klasifikasi, $item !== null);
        $jenisId = $item?->jenis_penugasan_id ?? $this->int($d['jenis_penugasan_id'] ?? null);
        if ($jenisId && ! JenisPenugasan::whereKey($jenisId)->exists()) {
            $jenisId = null;
        }
        // R3: a master jenis wins and the Lainnya text is dropped; outside non-PKPT both texts are always null.
        $jenisLainnya = $lainnya && ! $jenisId ? $this->teks($d['jenis_penugasan_lainnya'] ?? null) : null;
        if ($ajukan && ! $jenisId && $jenisLainnya === null) {
            $e['jenis_penugasan_id'] = $lainnya ? 'Pilih jenis penugasan atau isi Lainnya.' : 'Pilih jenis penugasan.';
        } elseif ($jenisLainnya !== null && mb_strlen($jenisLainnya) > 150) {
            $e['jenis_penugasan_lainnya'] = 'Jenis penugasan lainnya maksimal 150 karakter.';
        }

        $objekIds = array_values(array_unique(array_filter(array_map(fn ($v) => $this->int($v), (array) ($d['objek_ids'] ?? [])))));
        $objekIds = Objek::whereIn('id', $objekIds)->pluck('id')->all();
        $objekLainnya = $lainnya ? $this->teks($d['objek_lainnya'] ?? null) : null;
        if ($ajukan && ! $objekIds && $objekLainnya === null) {
            $e['objek_ids'] = $lainnya ? 'Pilih minimal satu objek atau isi objek lainnya.' : 'Pilih minimal satu objek.';
        } elseif ($objekLainnya !== null && mb_strlen($objekLainnya) > 255) {
            $e['objek_lainnya'] = 'Objek lainnya maksimal 255 karakter.';
        }

        $periode = trim((string) ($d['periode_diperiksa'] ?? ''));
        if ($ajukan && $periode === '') {
            $e['periode_diperiksa'] = 'Isi periode yang diperiksa.';
        } elseif (mb_strlen($periode) > 255) {
            $e['periode_diperiksa'] = 'Periode maksimal 255 karakter.';
        }
        $uraian = trim((string) ($d['uraian_untuk'] ?? ''));
        if ($ajukan && $uraian === '') {
            $e['uraian_untuk'] = 'Isi uraian.';
        } elseif (mb_strlen($uraian) > 1000) {
            $e['uraian_untuk'] = 'Uraian maksimal 1000 karakter.';
        }
        $catatan = trim((string) ($d['catatan_bagian_umum'] ?? ''));
        if (mb_strlen($catatan) > 1000) {
            $e['catatan_bagian_umum'] = 'Catatan maksimal 1000 karakter.';
        }

        $mulai = $this->tanggal($d['tanggal_mulai'] ?? null);
        $selesai = $this->tanggal($d['tanggal_selesai'] ?? null);
        if (! $mulai && ($ajukan || ($d['tanggal_mulai'] ?? null))) {
            $e['tanggal_mulai'] = 'Isi tanggal mulai.';
        }
        if (! $selesai && ($ajukan || ($d['tanggal_selesai'] ?? null))) {
            $e['tanggal_selesai'] = 'Isi tanggal selesai.';
        }
        if ($mulai && $selesai && $selesai->lt($mulai)) {
            $e['tanggal_selesai'] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
        }

        $ppj = $this->int($d['ppj_pegawai_id'] ?? null);
        if ($ppj && $ppj !== $spt?->ppj_pegawai_id && ! Pegawai::whereKey($ppj)->where('aktif', true)->exists()) {
            $ppj = null;
        }
        if ($ajukan && ! $ppj) {
            $e['ppj_pegawai_id'] = 'Pilih PPJ.';
        }

        $personel = [];
        try {
            $personel = $this->validatePersonel->handle((array) ($d['personel'] ?? []), $irbanId, $spt ? $spt->personel()->pluck('pegawai_id')->all() : [], $ajukan);
        } catch (ValidationException $ex) {
            $e += $ex->errors();
        }

        if ($e) {
            throw ValidationException::withMessages($e);
        }

        return [[
            'klasifikasi_id' => $klasifikasi->id, 'kode_arsip_id' => $kodeArsip, 'pkpt_item_id' => $item?->id, 'jenis_penugasan_id' => $jenisId,
            'jenis_penugasan_lainnya' => $jenisLainnya, 'objek_lainnya' => $objekLainnya, 'ppj_pegawai_id' => $ppj,
            'periode_diperiksa' => $periode ?: null, 'uraian_untuk' => $uraian ?: null, 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai,
            'catatan_bagian_umum' => $catatan ?: null, 'diinput_oleh' => $diinput,
        ], $objekIds, $personel];
    }
}
