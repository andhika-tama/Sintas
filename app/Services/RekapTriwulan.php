<?php

namespace App\Services;

use App\Enums\StatusPengajuan;
use App\Enums\StatusPkpt;
use App\Enums\StatusSpt;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;

/** Quarterly recap (PRD §7.9): rencana vs realisasi per irban, per klasifikasi and per jenis penugasan. */
class RekapTriwulan
{
    /** C1 (1.3 Task 1 §1.2): Rencana and % are "–" because items have no klasifikasi or kode arsip. */
    public const CATATAN_TANPA_RENCANA = 'Rencana PKPT tidak dapat difilter berdasarkan klasifikasi atau kode arsip, sehingga tidak ditampilkan.';

    /** C2: filter on a wajib klasifikasi while more than one klasifikasi is wajib; the rencana cannot be split between them. */
    public const CATATAN_RENCANA_GABUNG = 'Rencana mencakup semua klasifikasi yang wajib item PKPT.';

    public function __construct(private Triwulan $triwulan) {}

    /**
     * Year and quarter by basis date (Triwulan), never by `spt.tahun`. Only `diterima` SPT with a basis date count.
     * Rencana = sum of target_spt (null = 1) of the items of the year's Disahkan PKPT; realisasi = Selesai SPT with an item.
     *
     * Shape (plain arrays; `tw` is keyed 1–4, `persen` is round(realisasi / rencana * 100) or null when rencana is 0):
     *   tahun: int, pkpt_disahkan: bool,
     *   irban:       {baris: list<{id, nama, kode_nomor, tw, total}>, total: {tw, total}}  cells {rencana, realisasi, persen}
     *   klasifikasi: {baris: list<{id, nama, tw, total}>, total: {tw, total}}              cells {jumlah, berjalan, selesai}
     *   jenis:       {baris: list<{id: ?int, nama, tw, total}>, total: {tw, total}}        cells {rencana, realisasi, persen, jumlah}
     * Totals rows are sums over the rows with `persen` recomputed from the summed numbers.
     *   dana:        {baris: list<{id, nama, kode_nomor, tw, total}>, total: {tw, total}}  cells {dana, terisi, jumlah}: Σ realisasi_dana, SPT with a value, SPT in the quarter
     *   rencana: {tampil: bool, catatan: ?string} (aturanRencana); when tampil is false every `rencana` and `persen` cell is null.
     * $saring (Klasifikasi / Kode Arsip / Jenis, re-validated here by SaringSpt::dari) filters the SPT of every section;
     * the items behind Rencana are filtered by jenis only (PkptItem::scopeSaring).
     */
    public function hitung(int $tahun, ?int $irbanId = null, array $saring = []): array
    {
        $saring = app(SaringSpt::class)->dari($saring);
        $aturan = $this->aturanRencana($saring);
        $irban = Irban::orderBy('id')->when($irbanId, fn ($q) => $q->whereKey($irbanId))->get(['id', 'nama', 'kode_nomor']);
        $klasifikasi = Klasifikasi::urut()->get(['id', 'nama']);
        $jenis = JenisPenugasan::orderBy('nama')->get(['id', 'nama']);

        $rr = ['rencana' => 0, 'realisasi' => 0];
        $kl = ['jumlah' => 0, 'berjalan' => 0, 'selesai' => 0];
        $jn = ['rencana' => 0, 'realisasi' => 0, 'jumlah' => 0];
        $kosong = fn (iterable $rows, array $sel) => collect($rows)->mapWithKeys(fn ($r) => [$r->id => array_fill(1, 4, $sel)])->all();
        $perIrban = $kosong($irban, $rr);
        $perKlasifikasi = $kosong($klasifikasi, $kl);
        $perJenis = $kosong($jenis, $jn);
        $dn = ['dana' => 0, 'terisi' => 0, 'jumlah' => 0];
        $perDana = $kosong($irban, $dn);
        // Key 0 = "Tanpa jenis penugasan": SPT without a jenis, and any jenis id missing from the list above (created meanwhile).
        $perJenis[0] = array_fill(1, 4, $jn);
        $perJenis[-1] = array_fill(1, 4, $jn); // SPT with a free-text jenis (Lainnya): one group for all values
        $kunciJenis = fn (?int $id) => isset($perJenis[$id]) ? $id : 0;

        $items = ! $aturan['tampil'] ? [] : PkptItem::aktif()->saring($saring)
            ->whereHas('pkpt', fn ($q) => $q->where('tahun', $tahun)->where('status', StatusPkpt::Disahkan))
            ->when($irbanId, fn ($q) => $q->where('irban_id', $irbanId))
            ->get();
        foreach ($items as $item) {
            $rencana = $this->rencanaItem($item, $tahun);
            if ($rencana === null) {
                continue;
            }
            [$tw, $target] = $rencana;
            if (isset($perIrban[$item->irban_id])) {
                $perIrban[$item->irban_id][$tw]['rencana'] += $target;
            }
            $perJenis[$kunciJenis($item->jenis_penugasan_id)][$tw]['rencana'] += $target;
        }

        $q = Spt::where('status_pengajuan', StatusPengajuan::Diterima)->when($irbanId, fn ($q) => $q->where('irban_id', $irbanId))->saring($saring);
        $this->triwulan->batasi($q, $tahun);
        foreach ($q->get() as $spt) {
            $dasar = $this->triwulan->tanggalDasar($spt);
            if ($dasar === null || $dasar->year !== $tahun) {
                continue;
            }
            $tw = $this->triwulan->dari($dasar);
            $selesai = $spt->status() === StatusSpt::Selesai;
            $realisasi = (int) ($selesai && $spt->pkpt_item_id !== null);

            if (isset($perIrban[$spt->irban_id])) {
                $perIrban[$spt->irban_id][$tw]['realisasi'] += $realisasi;
            }
            if (isset($perKlasifikasi[$spt->klasifikasi_id])) {
                $perKlasifikasi[$spt->klasifikasi_id][$tw]['jumlah']++;
                $perKlasifikasi[$spt->klasifikasi_id][$tw][$selesai ? 'selesai' : 'berjalan']++;
            }
            $k = $spt->jenis_penugasan_id === null && $spt->jenis_penugasan_lainnya !== null ? -1 : $kunciJenis($spt->jenis_penugasan_id);
            $perJenis[$k][$tw]['realisasi'] += $realisasi;
            $perJenis[$k][$tw]['jumlah']++;
            if (isset($perDana[$spt->irban_id])) {
                $perDana[$spt->irban_id][$tw]['jumlah']++;
                if ($spt->realisasi_dana !== null) {
                    $perDana[$spt->irban_id][$tw]['terisi']++;
                    $perDana[$spt->irban_id][$tw]['dana'] += $spt->realisasi_dana;
                }
            }
        }

        $barisJenis = $jenis->map(fn ($j) => ['id' => $j->id, 'nama' => $j->nama, ...$this->tabel($perJenis[$j->id])])->all();
        if (array_sum(array_column($perJenis[-1], 'jumlah')) > 0) {
            $barisJenis[] = ['id' => null, 'nama' => Spt::LABEL_LAINNYA, ...$this->tabel($perJenis[-1])];
        }
        if (array_sum(array_column($perJenis[0], 'jumlah')) + array_sum(array_column($perJenis[0], 'rencana')) > 0) {
            $barisJenis[] = ['id' => null, 'nama' => 'Tanpa jenis penugasan', ...$this->tabel($perJenis[0])];
        }

        $hasil = [
            'tahun' => $tahun,
            'pkpt_disahkan' => Pkpt::where('tahun', $tahun)->where('status', StatusPkpt::Disahkan)->exists(),
            'irban' => $this->denganTotal($irban->map(fn ($i) => ['id' => $i->id, 'nama' => $i->nama, 'kode_nomor' => $i->kode_nomor, ...$this->tabel($perIrban[$i->id])])->all(), $rr),
            'klasifikasi' => $this->denganTotal($klasifikasi->map(fn ($k) => ['id' => $k->id, 'nama' => $k->nama, ...$this->tabel($perKlasifikasi[$k->id])])->all(), $kl),
            'jenis' => $this->denganTotal($barisJenis, $jn),
            'dana' => $this->denganTotal($irban->map(fn ($i) => ['id' => $i->id, 'nama' => $i->nama, 'kode_nomor' => $i->kode_nomor, ...$this->tabel($perDana[$i->id])])->all(), $dn),
            'rencana' => $aturan,
        ];
        if (! $aturan['tampil']) {
            // Hidden is null ("–"), never 0: only the cells' rencana and persen keys are touched.
            array_walk_recursive($hasil['irban'], fn (&$v, $k) => $v = in_array($k, ['rencana', 'persen'], true) ? null : $v);
            array_walk_recursive($hasil['jenis'], fn (&$v, $k) => $v = in_array($k, ['rencana', 'persen'], true) ? null : $v);
        }

        return $hasil;
    }

    /**
     * The one place of the spec 1.2 rule (input: SaringSpt::dari output), in priority order: kode arsip → hidden + C1; klasifikasi not
     * wajib item PKPT → hidden + C1; wajib while more than one klasifikasi (active or not) is wajib → shown + C2; else shown, no note.
     * Jenis never hides the rencana (items have a jenis).
     *
     * @return array{tampil: bool, catatan: ?string}
     */
    public function aturanRencana(array $saring): array
    {
        $klasifikasi = $saring['klasifikasi'] ?? null;
        $wajib = fn () => Klasifikasi::where('wajib_item_pkpt', true);

        return match (true) {
            ($saring['kode_arsip'] ?? null) !== null,
            $klasifikasi !== null && ! $wajib()->whereKey($klasifikasi)->exists() => ['tampil' => false, 'catatan' => self::CATATAN_TANPA_RENCANA],
            $klasifikasi !== null && $wajib()->count() > 1 => ['tampil' => true, 'catatan' => self::CATATAN_RENCANA_GABUNG],
            default => ['tampil' => true, 'catatan' => null],
        };
    }

    /**
     * The rencana rule for one item (shared with the public pages): [tw, target_spt with null = 1] by the item's basis
     * date (Triwulan::tanggalDasarItem), or null when that date falls in another year.
     *
     * @return array{0: int, 1: int}|null
     */
    public function rencanaItem(PkptItem $item, int $tahun): ?array
    {
        $dasar = $this->triwulan->tanggalDasarItem($item);
        if ($dasar->year !== $tahun) {
            return null;
        }

        return [$this->triwulan->dari($dasar), $item->target_spt ?? 1]; // one item plans at least one SPT
    }

    /** @param array<int, array<string, int>> $tw raw counts per quarter → {tw, total} with persen where the cell has rencana */
    private function tabel(array $tw): array
    {
        $total = [];
        foreach ($tw as $sel) {
            foreach ($sel as $k => $v) {
                $total[$k] = ($total[$k] ?? 0) + $v;
            }
        }

        return ['tw' => array_map($this->persen(...), $tw), 'total' => $this->persen($total)];
    }

    /** @param array<string, int> $nol zero cell, so an empty table still has a full totals row */
    private function denganTotal(array $baris, array $nol): array
    {
        $tw = array_fill(1, 4, $nol);
        foreach ($baris as $b) {
            foreach ($b['tw'] as $n => $sel) {
                foreach ($sel as $k => $v) {
                    if ($k !== 'persen') {
                        $tw[$n][$k] += $v;
                    }
                }
            }
        }

        return ['baris' => $baris, 'total' => $this->tabel($tw)];
    }

    /** Adds persen right after realisasi when the cell has a rencana; other cells pass through. */
    private function persen(array $sel): array
    {
        if (! array_key_exists('rencana', $sel)) {
            return $sel;
        }
        $persen = $sel['rencana'] > 0 ? (int) round($sel['realisasi'] / $sel['rencana'] * 100) : null;
        $jumlah = array_key_exists('jumlah', $sel) ? ['jumlah' => $sel['jumlah']] : [];

        return ['rencana' => $sel['rencana'], 'realisasi' => $sel['realisasi'], 'persen' => $persen, ...$jumlah];
    }
}
