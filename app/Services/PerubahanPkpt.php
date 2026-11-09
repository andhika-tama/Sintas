<?php

namespace App\Services;

use App\Actions\Pkpt\SavePkptItem;
use App\Enums\AksiPerubahan;
use App\Enums\StatusItemPkpt;
use App\Enums\StatusPengajuan;
use App\Enums\StatusPerubahan;
use App\Enums\StatusPkpt;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\PkptPerubahanItem;
use App\Models\Spt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Rules of a Perubahan PKPT, shared by the actions (throwing) and the screens (non-throwing `cek`). */
class PerubahanPkpt
{
    public const K7_UBAH = 'Irban dan jenis penugasan item yang sudah dipakai SPT tidak boleh diubah.';

    public const K7_HAPUS = 'Item yang sudah dipakai SPT tidak boleh dihapus.';

    public const TIDAK_BERUBAH = 'Tidak ada yang berubah.';

    public const ITEM_TIDAK_ADA = 'Item tidak ditemukan di PKPT ini atau sudah dihapus.';

    public const ITEM_GANDA = 'Item ini sudah ada di operasi lain pada perubahan ini.';

    public const BELUM_DISAHKAN = 'Perubahan hanya untuk PKPT yang sudah disahkan.';

    public const KOSONG = 'Tambahkan minimal satu operasi.';

    public const KOLOM = ['irban_id', 'jenis_penugasan_id', 'tanggal_mulai', 'tanggal_selesai', 'keterangan', 'target_spt'];

    /** 1.2 K10: an approved change of any of these columns clears the item's Perubahan Penugasan mark (PutuskanPerubahan and dampak()). */
    public const KOLOM_HAPUS_TANDA = ['tanggal_mulai', 'tanggal_selesai', 'irban_id'];

    public function __construct(private KesesuaianPkpt $kesesuaian) {}

    /** @return list<string> Reasons the package cannot be submitted/applied now; empty = valid. */
    public function cek(PkptPerubahan $p): array
    {
        $pkpt = $p->pkpt()->first();
        $ops = $p->operasi()->get();
        $alasan = $pkpt->status === StatusPkpt::Disahkan ? [] : [self::BELUM_DISAHKAN];
        if ($ops->isEmpty()) {
            $alasan[] = self::KOSONG;
        }
        $items = PkptItem::whereIn('id', $ops->pluck('pkpt_item_id')->filter()->unique())->get()->keyBy('id');
        $spt = $this->punyaSpt($items->keys()->all());
        $irban = Irban::pluck('nama', 'id');
        $jenis = JenisPenugasan::pluck('nama', 'id');
        $dipakai = [];

        foreach ($ops as $op) {
            $item = $items->get($op->pkpt_item_id);
            $sumber = $op->aksi === AksiPerubahan::Tambah ? ($op->data_baru ?? []) : ($item?->only(self::KOLOM) ?? $op->data_baru ?? []);
            $label = 'Operasi '.$op->urutan.' ('.collect([$op->aksi->label(), $jenis[$sumber['jenis_penugasan_id'] ?? 0] ?? null, $irban[$sumber['irban_id'] ?? 0] ?? null])->filter()->implode(' · ').')';

            $galat = $this->galat($pkpt, $op->aksi, $item, $op->data_baru ?? [], (bool) ($spt[$op->pkpt_item_id ?? 0] ?? false));
            if ($op->pkpt_item_id && in_array($op->pkpt_item_id, $dipakai, true)) {
                $galat[] = self::ITEM_GANDA;
            }
            $dipakai[] = $op->pkpt_item_id;
            foreach ($galat as $m) {
                $alasan[] = "{$label}: {$m}";
            }
        }

        return array_values($alasan);
    }

    /**
     * Rules of one operation (shared by `cek` and `SimpanOperasi`): item of the PKPT and aktif, SavePkptItem rules, K7, "nothing changed".
     *
     * @return array<string, string> field => message
     */
    public function galat(Pkpt $pkpt, AksiPerubahan $aksi, ?PkptItem $item, array $dataBaru, bool $punyaSpt): array
    {
        if ($aksi !== AksiPerubahan::Tambah && ($item === null || $item->pkpt_id !== $pkpt->id || $item->status !== StatusItemPkpt::Aktif)) {
            return ['pkpt_item_id' => self::ITEM_TIDAK_ADA];
        }
        if ($aksi === AksiPerubahan::Hapus) {
            return $punyaSpt ? ['pkpt_item_id' => self::K7_HAPUS] : [];
        }
        try {
            $baru = self::bentuk(SavePkptItem::validated($pkpt, $dataBaru));
        } catch (ValidationException $e) {
            return array_map(fn (array $m) => $m[0], $e->errors());
        }
        if ($aksi === AksiPerubahan::Ubah) {
            $lama = self::data($item);
            if ($punyaSpt && ($baru['irban_id'] !== $lama['irban_id'] || $baru['jenis_penugasan_id'] !== $lama['jenis_penugasan_id'])) {
                return ['irban_id' => self::K7_UBAH];
            }
            if ($baru === $lama) {
                return ['operasi' => self::TIDAK_BERUBAH]; // own key: the page keeps it in the operasi modal
            }
        }

        return [];
    }

    /** The open package (draf, diajukan or ditolak) of a PKPT; at most one exists. */
    public function terbuka(Pkpt $pkpt): ?PkptPerubahan
    {
        return PkptPerubahan::where('pkpt_id', $pkpt->id)
            ->whereIn('status', [StatusPerubahan::Draf, StatusPerubahan::Diajukan, StatusPerubahan::Ditolak])->first();
    }

    /** @param list<int> $itemIds @return array<int, bool> item id => used by any SPT (all statuses) */
    public function punyaSpt(array $itemIds): array
    {
        $ada = $itemIds ? Spt::whereIn('pkpt_item_id', $itemIds)->distinct()->pluck('pkpt_item_id')->map(fn ($id) => (int) $id)->all() : [];

        return collect($itemIds)->mapWithKeys(fn ($id) => [(int) $id => in_array((int) $id, $ada, true)])->all();
    }

    /** @return list<int> Irban touched by any operation (new, old or the item's own irban), ascending. */
    public function irbanTerdampak(PkptPerubahan $p): array
    {
        return $p->operasi()->with('item:id,irban_id')->get()
            ->flatMap(fn (PkptPerubahanItem $op) => $this->irbanOperasi($op))->unique()->sort()->values()->all();
    }

    /**
     * Impact shown before deciding: items changed per irban (a move counts for both), and accepted SPT of `ubah` items whose kesesuaian flips.
     *
     * @return array{irban: list<array{nama: string, jumlah: int}>, spt: list<array{id: int, nomor_lengkap: ?string, irban: string, sebelum: bool, sesudah: bool}>} sebelum/sesudah = sesuai PKPT
     */
    public function dampak(PkptPerubahan $p): array
    {
        $ops = $p->operasi()->with('item')->get();
        $jumlah = [];
        foreach ($ops as $op) {
            foreach ($this->irbanOperasi($op) as $id) {
                $jumlah[$id] = ($jumlah[$id] ?? 0) + 1;
            }
        }
        ksort($jumlah);
        $nama = Irban::whereIn('id', array_keys($jumlah))->pluck('nama', 'id');

        $ubah = $ops->filter(fn ($op) => $op->aksi === AksiPerubahan::Ubah && $op->item && filled($op->data_baru['tanggal_mulai'] ?? null)
            && filled($op->data_baru['tanggal_selesai'] ?? null))->keyBy('pkpt_item_id');
        $spt = [];
        $daftar = $ubah->isEmpty() ? collect() : Spt::with('irban:id,nama')->where('status_pengajuan', StatusPengajuan::Diterima)
            ->whereIn('pkpt_item_id', $ubah->keys())->whereNotNull('tanggal_mulai')->whereNotNull('tanggal_selesai')->orderBy('id')->get();
        foreach ($daftar as $s) {
            $op = $ubah[$s->pkpt_item_id];
            // After approval the item already holds the new values and no mark: read data_lama instead (rows approved before 1.2 have no mark key).
            $lama = $op->data_lama ?? self::data($op->item);
            $tanda = $op->data_lama ? ($op->data_lama['perubahan_penugasan_id'] ?? null) : $op->item->perubahan_penugasan_id;
            $tetapBertanda = $tanda !== null && collect(self::KOLOM_HAPUS_TANDA)->every(fn ($k) => ($lama[$k] ?? null) === ($op->data_baru[$k] ?? null));
            $sebelum = $tanda === null && $this->kesesuaian->sesuaiRentang([$lama['tanggal_mulai'], $lama['tanggal_selesai']], $s->tanggal_mulai, $s->tanggal_selesai);
            $sesudah = ! $tetapBertanda && $this->kesesuaian->sesuaiRentang([$op->data_baru['tanggal_mulai'], $op->data_baru['tanggal_selesai']], $s->tanggal_mulai, $s->tanggal_selesai);
            if ($sebelum !== $sesudah) {
                $spt[] = ['id' => $s->id, 'nomor_lengkap' => $s->nomor_lengkap, 'irban' => (string) $s->irban?->nama, 'sebelum' => $sebelum, 'sesudah' => $sesudah];
            }
        }

        return [
            'irban' => collect($jumlah)->map(fn ($n, $id) => ['nama' => (string) ($nama[$id] ?? ''), 'jumlah' => $n])->values()->all(),
            'spt' => $spt,
        ];
    }

    /**
     * Approved changes per item, oldest first.
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<array{perubahan_id: int, nomor_urut: ?int, aksi: AksiPerubahan, data_lama: ?array, data_baru: array, disetujui_pada: ?CarbonImmutable, nomor_sk: ?string, tanggal_sk: ?CarbonImmutable, ada_sk: bool}>>
     */
    public function riwayatItem(array $itemIds): array
    {
        if (! $itemIds) {
            return [];
        }
        $baris = PkptPerubahanItem::query()->join('pkpt_perubahan as p', 'p.id', '=', 'pkpt_perubahan_item.perubahan_id')
            ->where('p.status', StatusPerubahan::Disetujui->value)->whereIn('pkpt_perubahan_item.pkpt_item_id', $itemIds)
            ->orderBy('p.nomor_urut')->orderBy('p.diputuskan_pada')->orderBy('pkpt_perubahan_item.urutan')->orderBy('pkpt_perubahan_item.id')
            ->get(['pkpt_perubahan_item.*', 'p.nomor_urut', 'p.diputuskan_pada', 'p.nomor_sk', 'p.tanggal_sk', 'p.berkas_sk']);

        $hasil = [];
        foreach ($baris as $b) {
            $hasil[(int) $b->pkpt_item_id][] = [
                'perubahan_id' => (int) $b->perubahan_id,
                'nomor_urut' => $b->nomor_urut === null ? null : (int) $b->nomor_urut,
                'aksi' => $b->aksi,
                'data_lama' => $b->data_lama,
                'data_baru' => $b->data_baru ?? [],
                'disetujui_pada' => $b->diputuskan_pada ? CarbonImmutable::parse($b->diputuskan_pada) : null,
                'nomor_sk' => $b->nomor_sk,
                'tanggal_sk' => $b->tanggal_sk ? CarbonImmutable::parse($b->tanggal_sk)->startOfDay() : null,
                'ada_sk' => filled($b->berkas_sk),
            ];
        }

        return $hasil;
    }

    /**
     * riwayatItem() plus `boleh_unduh` per entry: the `unduhSk` gate of that package for $user, checked once per package.
     *
     * @param  list<int>  $itemIds
     */
    public function riwayatItemUntuk(User $user, array $itemIds): array
    {
        $riwayat = $this->riwayatItem($itemIds);
        $paket = collect($riwayat)->flatten(1)->where('ada_sk', true)->pluck('perubahan_id')->unique()->values();
        $boleh = PkptPerubahan::whereIn('id', $paket)->get()->mapWithKeys(fn (PkptPerubahan $p) => [$p->id => Gate::forUser($user)->allows('unduhSk', $p)]);

        return array_map(fn (array $baris) => array_map(fn (array $r) => $r + ['boleh_unduh' => (bool) ($boleh[$r['perubahan_id']] ?? false)], $baris), $riwayat);
    }

    /** The E4 json shape of an item's current values. */
    public static function data(PkptItem $item): array
    {
        return self::bentuk($item->only(self::KOLOM));
    }

    /** Normalizes attributes (e.g. `SavePkptItem::validated` output) to the E4 json shape. */
    public static function bentuk(array $a): array
    {
        $tgl = fn ($d) => $d === null ? null : CarbonImmutable::parse($d)->format('Y-m-d');
        $int = fn ($v) => $v === null || $v === '' ? null : (int) $v;

        return [
            'irban_id' => $int($a['irban_id'] ?? null),
            'jenis_penugasan_id' => $int($a['jenis_penugasan_id'] ?? null),
            'tanggal_mulai' => $tgl($a['tanggal_mulai'] ?? null),
            'tanggal_selesai' => $tgl($a['tanggal_selesai'] ?? null),
            'keterangan' => ($a['keterangan'] ?? null) === '' ? null : ($a['keterangan'] ?? null),
            'target_spt' => $int($a['target_spt'] ?? null),
        ];
    }

    /** @return list<int> */
    private function irbanOperasi(PkptPerubahanItem $op): array
    {
        return array_values(array_unique(array_filter(array_map('intval', [
            $op->data_baru['irban_id'] ?? 0, $op->data_lama['irban_id'] ?? 0, $op->item?->irban_id ?? 0,
        ]))));
    }
}
