<?php

namespace App\Services\Ekspor;

use App\Enums\Role;
use App\Enums\StatusPkpt;
use App\Exports\RekapSheet;
use App\Exports\RekapWorkbook;
use App\Models\Irban;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use App\Services\RealisasiItemPkpt;
use App\Services\RekapTriwulan;
use App\Services\SaringSpt;
use App\Support\PaletGrafik;
use App\Support\Tanggal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Rekap Triwulanan export. The sections come from bagian(), the same method the page renders, so the file equals the screen.
 * The user picks the sections (filter `bagian`, codes BAGIAN). xlsx: sheet Gabungan + one sheet per irban (RekapWorkbook, spec 9.2 §2.2).
 */
class PembangunRekap implements Pembangun
{
    public const TW = [1 => 'TW I', 2 => 'TW II', 3 => 'TW III', 4 => 'TW IV'];

    /** Section codes in canonical order: Rencana vs Realisasi, Per Klasifikasi, Per Jenis Penugasan, Realisasi Dana, Detail Realisasi. */
    public const BAGIAN = ['rr', 'klasifikasi', 'jenis', 'dana', 'detail'];

    /** Preselected sections per Rekap tab. */
    public const BAGIAN_DEFAULT = ['irban' => ['rr', 'klasifikasi', 'jenis', 'dana'], 'gabungan' => ['rr', 'klasifikasi', 'jenis', 'dana'], 'detail' => ['detail']];

    /** First column header of each summary section in the workbook. */
    private const KEPALA = ['rr' => 'Irban', 'klasifikasi' => 'Klasifikasi', 'jenis' => 'Jenis Penugasan', 'dana' => 'Irban'];

    private const RR = [['Rencana', 'Realisasi', '%'], ['rencana', 'realisasi', 'persen']];

    private const KL = [['Jumlah', 'Berjalan', 'Selesai'], ['jumlah', 'berjalan', 'selesai']];

    private const JN = [['Rencana', 'Realisasi', '%', 'Jumlah SPT'], ['rencana', 'realisasi', 'persen', 'jumlah']];

    /** Realisasi Dana (1.3): Dana in Rupiah (a number in the files), Keterangan "x dari y SPT terisi" so the total is not read as the full cost. */
    private const DN = [['Dana', 'Keterangan'], ['dana', 'keterangan']];

    private const DETAIL = 'Detail Realisasi per Item PKPT';

    /** Task 6 1.1.4 (K9): shown on the page and in both files only when relevant. */
    public const CATATAN_TARGET = 'Sebagian item PKPT belum punya target SPT dan dihitung 1.';

    public const CATATAN_LEBIH = '* Di atas 100%: realisasi melebihi rencana, biasanya karena target SPT item belum diisi (dihitung 1) atau lebih kecil dari jumlah SPT yang terbit.';

    private const KOLOM_DETAIL = ['Jenis Penugasan', 'Periode Penugasan (PKPT)', 'Nomor SPT', 'Realisasi Periode', 'Kesesuaian dengan Jadwal PKPT', 'Status', 'Permasalahan/Kendala'];

    /** hitung() results of this instance (one request or one job), so jumlah() and export() do not recompute: "tahun|irban|saring" => result. */
    private array $hasilHitung = [];

    public function __construct(private RekapTriwulan $rekap, private RealisasiItemPkpt $detailRealisasi) {}

    private function hitung(int $tahun, ?int $irban, array $saring): array
    {
        return $this->hasilHitung["{$tahun}|{$irban}|".json_encode($saring)] ??= $this->rekap->hitung($tahun, $irban, $saring);
    }

    /**
     * Items of the year's Disahkan PKPT without target_spt whose rencana falls in the period (tw 0 = the year), scoped like
     * RekapTriwulan::hitung and placed by the same RekapTriwulan::rencanaItem (read-only reuse). 0 when $saring hides the rencana.
     */
    public function tanpaTarget(int $tahun, int $tw, ?int $irban, array $saring = []): int
    {
        if (! $this->rekap->aturanRencana($saring)['tampil']) {
            return 0;
        }

        return PkptItem::aktif()->tanpaTarget()->saring($saring)
            ->whereHas('pkpt', fn ($q) => $q->where('tahun', $tahun)->where('status', StatusPkpt::Disahkan))
            ->when($irban, fn ($q) => $q->where('irban_id', $irban))->get()
            ->filter(fn (PkptItem $item) => ($r = $this->rekap->rencanaItem($item, $tahun)) && (! $tw || $r[0] === $tw))->count();
    }

    /** A realisasi percentage above 100 gets the "*" marker and CATATAN_LEBIH. */
    public static function diAtas100(mixed $persen): bool
    {
        return is_int($persen) && $persen > 100;
    }

    /** Known codes only, unique, in canonical order; nothing left → the default of tab `irban`. */
    public static function bagianTerpilih(mixed $nilai): array
    {
        return array_values(array_intersect(self::BAGIAN, array_filter((array) $nilai, 'is_string'))) ?: self::BAGIAN_DEFAULT['irban'];
    }

    public function otorisasi(User $user): void
    {
        Gate::forUser($user)->authorize('rekap', Spt::class);
    }

    /**
     * Irban accounts are always scoped to their own irban; a tampered `irban` is ignored. `saring` (SaringSpt::dari) is read from the
     * flat page keys or, for a queued export, from the stored `saring`, and re-validated either way.
     */
    public function filter(User $user, array $filter): array
    {
        $tahun = (int) ($filter['tahun'] ?? 0);
        $tw = (int) ($filter['tw'] ?? 0);
        $irban = $user->role === Role::Irban ? $user->irban_id : Irban::whereKey((int) ($filter['irban'] ?? 0))->value('id');

        return [
            'tahun' => $tahun >= 2000 && $tahun <= 2100 ? $tahun : (int) now('Asia/Jakarta')->year,
            'tw' => $tw >= 1 && $tw <= 4 ? $tw : 0,
            'irban' => $irban ? (int) $irban : null,
            'bagian' => self::bagianTerpilih($filter['bagian'] ?? null),
            'saring' => app(SaringSpt::class)->dari(is_array($filter['saring'] ?? null) ? $filter['saring'] : $filter),
        ];
    }

    /**
     * Upper bound of the rows: selected summary sections (title + header + data rows of the scope's tables) × number of sheets,
     * plus the Detail Realisasi rows when selected (each detail row lands on one irban sheet only).
     */
    public function jumlah(User $user, array $filter): int
    {
        $ringkas = 0;
        if (array_diff($filter['bagian'], ['detail'])) {
            $data = $this->hitung($filter['tahun'], $filter['irban'], $filter['saring']);
            foreach ($this->bagian($data, $filter['tw'], $filter['irban'] ? 'irban' : 'gabungan') as $s) {
                $ringkas += in_array($s['kode'], $filter['bagian'], true) ? 2 + count($s['tabel'][0]['baris']) : 0;
            }
            $ringkas *= $filter['irban'] ? 1 : Irban::count() + 1;
        }

        return $ringkas + (in_array('detail', $filter['bagian'], true) ? array_sum(array_map(fn ($g) => count($g['baris']), $this->detail($filter))) : 0);
    }

    /** e.g. rekap-triwulan-2026-irban-II-klasifikasi-khusus-kode-arsip-700-jenis-lainnya-rr: each active filter, slugged. */
    public function namaFile(User $user, array $filter): string
    {
        $kode = $filter['irban'] ? Irban::whereKey($filter['irban'])->value('kode_nomor') : null;
        // ekspor.nama_file is varchar(255): master names run to 255 chars, so cap the filter part (name stays under ~190 with year, irban, bagian and extension).
        $saring = rtrim(Str::limit(collect(app(SaringSpt::class)->ringkasan($filter['saring']))->map(fn ($r) => '-'.Str::slug($r))->implode(''), 120, ''), '-');

        return "rekap-triwulan-{$filter['tahun']}".($kode ? "-irban-{$kode}" : '').$saring.($filter['bagian'] === self::BAGIAN ? '' : '-'.implode('-', $filter['bagian']));
    }

    /** Title row of the sheets: year, TW and the active Klasifikasi / Kode Arsip / Jenis (SaringSpt::ringkasan). */
    private function judul(array $filter): string
    {
        return implode(' · ', ['Rekap Triwulanan '.$filter['tahun'], ...($filter['tw'] ? [self::TW[$filter['tw']]] : []), ...app(SaringSpt::class)->ringkasan($filter['saring'])]);
    }

    /** Sheets: Gabungan (summary sections only; left out when only `detail` is picked) then one per irban, or only the scoped irban. */
    public function export(User $user, array $filter): RekapWorkbook
    {
        $judul = $this->judul($filter);
        $dibuat = now('Asia/Jakarta')->translatedFormat('d F Y H.i').' WIB';
        $sheets = [];
        if (! $filter['irban'] && array_diff($filter['bagian'], ['detail'])) {
            $sheets[] = new RekapSheet($judul, 'Gabungan', $dibuat, $this->bagianSheet($this->hitung($filter['tahun'], null, $filter['saring']), $filter, null));
        }
        $detail = in_array('detail', $filter['bagian'], true) ? array_column($this->detail($filter), null, 'id') : [];
        foreach (Irban::orderBy('id')->when($filter['irban'], fn ($q, $id) => $q->whereKey($id))->get(['id', 'nama']) as $i) {
            $bagian = $this->bagianSheet($this->hitung($filter['tahun'], $i->id, $filter['saring']), $filter, $i->id);
            if (isset($detail[$i->id])) {
                $bagian[] = ['judul' => self::DETAIL, 'kolom' => self::KOLOM_DETAIL, 'tipe' => ['label', 'tanggal', 'nomor', 'tanggal', 'teks', 'teks', 'panjang'],
                    'baris' => $detail[$i->id]['baris'], 'total' => [], 'gabung' => $detail[$i->id]['gabung']];
            }
            $sheets[] = new RekapSheet($judul, $i->nama, $dibuat, $bagian);
        }

        return new RekapWorkbook($sheets);
    }

    /**
     * Selected summary sections of one sheet in RekapSheet shape ($irban null = the Gabungan sheet, which adds "Ringkasan per Irban"
     * after Rencana vs Realisasi). Percentages above 100 become "133 *". CATATAN_TARGET is a "Catatan" section right under the section
     * kodeCatatanTarget() picks (as in the PDF; after "Ringkasan per Irban" on Gabungan); CATATAN_LEBIH is a closing "Catatan" section.
     * The rencana note (catatanRencana, C1/C2) joins the same "Catatan" under each section with a Rencana column (rr, jenis).
     */
    private function bagianSheet(array $data, array $filter, ?int $irban): array
    {
        $gabungan = $irban === null;
        // Only the row's percentage cell (index `lebih`) gets the marker; the cell becomes text, e.g. "133 *".
        $tanda = fn (array $b) => array_map(fn ($n, $i) => $i === $b['lebih'] ? $n.' *' : $n, $b['sel'], array_keys($b['sel']));
        $catatan = fn (string ...$teks) => ['judul' => 'Catatan', 'kolom' => ['Keterangan'], 'tipe' => ['label'], 'baris' => array_map(fn ($t) => [$t], $teks), 'total' => []];
        $kodeTarget = $this->kodeCatatanTarget($filter, $irban);
        $hasil = [];
        $lebih = false;
        foreach ($this->bagian($data, $filter['tw'], $gabungan ? 'gabungan' : 'irban') as $s) {
            if (! in_array($s['kode'], $filter['bagian'], true)) {
                continue;
            }
            $baris = $s['tabel'][0]['baris'];
            $lebih = $lebih || (bool) array_filter(array_column($baris, 'lebih'), 'is_int');
            $hasil[] = ['judul' => $s['judul'], 'kolom' => [self::KEPALA[$s['kode']], 'Periode', ...$s['kolom']], 'tipe' => ['label', 'teks', ...($s['kode'] === 'dana' ? ['rupiah', 'teks'] : array_fill(0, count($s['kolom']), 'angka'))],
                'baris' => array_map(fn ($b) => [$b['label'], $b['periode'], ...$tanda($b)], $baris), 'total' => array_keys(array_filter(array_column($baris, 'total')))];
            if ($s['kode'] === 'rr' && $gabungan) {
                $sel = fn (array $b) => $filter['tw'] ? $b['tw'][$filter['tw']] : $b['total'];
                $ringkasan = array_map(fn ($b) => $this->baris($b['nama'], '', $sel($b), self::RR[1], false), $data['irban']['baris']);
                $lebih = $lebih || (bool) array_filter(array_column($ringkasan, 'lebih'), 'is_int');
                $hasil[] = ['judul' => 'Ringkasan per Irban', 'kolom' => ['Irban', ...self::RR[0]], 'tipe' => ['label', 'angka', 'angka', 'angka'], 'total' => [],
                    'baris' => array_map(fn ($b) => [$b['label'], ...$tanda($b)], $ringkasan)];
            }
            $teks = array_filter([$s['kode'] === $kodeTarget ? self::CATATAN_TARGET : null, self::catatanRencana($data, $s['kode'])]);
            if ($teks) {
                $hasil[] = $catatan(...$teks);
            }
        }
        if ($lebih) {
            $hasil[] = $catatan(self::CATATAN_LEBIH);
        }

        return $hasil;
    }

    /** The rencana note (C1/C2) under a section with a Rencana column, as hitung() decided it; null elsewhere. Shared by the page and both files. */
    public static function catatanRencana(array $rekap, string $kode): ?string
    {
        return in_array($kode, ['rr', 'jenis'], true) ? ($rekap['rencana']['catatan'] ?? null) : null;
    }

    public function html(User $user, array $filter): string
    {
        $data = $this->hitung($filter['tahun'], $filter['irban'], $filter['saring']);
        $pilihan = $filter['bagian'];

        return view('pdf.rekap-triwulan', [
            'tahun' => $filter['tahun'], 'tw' => $filter['tw'] ? self::TW[$filter['tw']] : 'Semua TW', 'saring' => app(SaringSpt::class)->ringkasan($filter['saring']), 'rekap' => $data,
            'irban' => $filter['irban'] ? Irban::whereKey($filter['irban'])->value('nama') : null,
            'pkptDisahkan' => $data['pkpt_disahkan'], 'catatanTarget' => $this->kodeCatatanTarget($filter, $filter['irban']), 'detailJudul' => self::DETAIL, 'detailKolom' => self::KOLOM_DETAIL,
            'detail' => in_array('detail', $pilihan, true) ? $this->detail($filter) : [], 'pilihan' => $pilihan, 'grafik' => $this->grafik($data, $filter),
            'bagian' => array_values(array_filter($this->bagian($data, $filter['tw'], 'semua'), fn ($s) => in_array($s['kode'], $pilihan, true))),
            'dibuat' => now('Asia/Jakarta')->translatedFormat('d F Y H:i').' WIB',
        ])->render();
    }

    /** Section that carries CATATAN_TARGET in the PDF and the Excel sheet of scope $irban: Rencana vs Realisasi, else Per Jenis Penugasan (both show rencana); null when no item lacks a target. */
    private function kodeCatatanTarget(array $filter, ?int $irban): ?string
    {
        $kode = array_values(array_intersect(['rr', 'jenis'], $filter['bagian']))[0] ?? null;

        return $kode && $this->tanpaTarget($filter['tahun'], $filter['tw'], $irban, $filter['saring']) > 0 ? $kode : null;
    }

    /**
     * Bar chart data per selected summary section for the PDF (partial pdf.partials.grafik-batang). Values follow the filter's TW (0 = the year).
     * When the rule hides the rencana (rencana.tampil false), rr draws Realisasi only ("Grafik Realisasi per Irban", or "per Triwulan" for
     * one irban) and the % per jenis chart, which needs a rencana, is left out.
     *
     * @return array<string, array{jenis: string, judul: string, baris: list<array>, legenda: list<array>}>
     */
    public function grafik(array $rekap, array $filter): array
    {
        $tw = $filter['tw'];
        $ambil = fn (array $b) => $tw ? $b['tw'][$tw] : $b['total'];
        $rr = [['label' => 'Rencana', 'isi' => PaletGrafik::RENCANA_ISI, 'garis' => PaletGrafik::RENCANA_GARIS], ['label' => 'Realisasi', 'isi' => PaletGrafik::REALISASI, 'garis' => PaletGrafik::REALISASI]];
        $tampil = $rekap['rencana']['tampil'] ?? true;
        $pasang = fn (array $s) => $tampil ? [$s['rencana'] ?? 0, $s['realisasi'] ?? 0] : [$s['realisasi'] ?? 0];
        $teksRr = fn (array $n) => $tampil ? ["Rencana {$n[0]}", "Realisasi {$n[1]}"] : ["Realisasi {$n[0]}"];
        $hasil = [];
        foreach ($filter['bagian'] as $kode) {
            if ($kode === 'rr') {
                $baris = $filter['irban']
                    ? array_map(fn ($n) => ['label' => self::TW[$n], 'nilai' => $v = $pasang($rekap['irban']['total']['tw'][$n]), 'teks' => $teksRr($v)], $tw ? [$tw] : array_keys(self::TW))
                    : array_map(fn ($b) => ['label' => $b['nama'], 'nilai' => $v = $pasang($ambil($b)), 'teks' => $teksRr($v)], $rekap['irban']['baris']);
                $judul = $tampil ? 'Grafik Rencana vs Realisasi' : ($filter['irban'] ? 'Grafik Realisasi per Triwulan' : 'Grafik Realisasi per Irban');
                $hasil['rr'] = ['jenis' => 'berpasangan', 'judul' => $judul, 'baris' => $baris, 'legenda' => $tampil ? $rr : [$rr[1]]];
            } elseif ($kode === 'klasifikasi') {
                $hasil['klasifikasi'] = ['jenis' => 'berpasangan', 'judul' => 'Grafik Per Klasifikasi', 'legenda' => [
                    ['label' => 'Berjalan', 'isi' => PaletGrafik::BERJALAN_ISI, 'garis' => PaletGrafik::BERJALAN_GARIS],
                    ['label' => 'Selesai', 'isi' => PaletGrafik::SELESAI, 'garis' => PaletGrafik::SELESAI]],
                    'baris' => array_map(function ($b) use ($ambil) {
                        [$jalan, $selesai] = [$ambil($b)['berjalan'] ?? 0, $ambil($b)['selesai'] ?? 0];

                        return ['label' => "{$b['nama']} (".($jalan + $selesai).')', 'nilai' => [$jalan, $selesai], 'teks' => "Berjalan {$jalan} · Selesai {$selesai}"];
                    }, $rekap['klasifikasi']['baris'])];
            } elseif ($kode === 'jenis' && $tampil) {
                $baris = [];
                foreach ($rekap['jenis']['baris'] as $b) {
                    [$rencana, $realisasi] = [$ambil($b)['rencana'] ?? 0, $ambil($b)['realisasi'] ?? 0];
                    if ($rencana > 0) {
                        $persen = (int) round($realisasi / $rencana * 100);
                        $baris[] = ['label' => $b['nama'], 'nilai' => [$persen], 'teks' => "{$persen}% ({$realisasi}/{$rencana})"];
                    }
                }
                usort($baris, fn ($a, $b) => $b['nilai'][0] <=> $a['nilai'][0]);
                $hasil['jenis'] = ['jenis' => 'persen', 'judul' => 'Grafik Per Jenis Penugasan (% realisasi)', 'baris' => $baris, 'legenda' => [$rr[1]]];
            }
        }

        return $hasil;
    }

    /** Failure notification target: Irban accounts have their own rekap page. */
    public function halaman(User $user): string
    {
        return route($user->role === Role::Irban ? 'irban.rekap' : 'pimpinan.rekap', absolute: false);
    }

    /**
     * Fourth section, same service as the Detail Realisasi tab. One table per irban; Jenis and Periode only on the first row of an item,
     * `gabung` = the row span of each item (merged in the xlsx).
     *
     * @return list<array{id: int, irban: string, baris: list<list<string>>, gabung: list<array{0: int, 1: int}>}>
     */
    private function detail(array $filter): array
    {
        $hasil = [];
        foreach ($this->detailRealisasi->untuk($filter['tahun'], $filter['tw'], $filter['irban'], $filter['saring']) as $g) {
            $baris = $gabung = [];
            foreach ($g['item'] as $it) {
                $mulai = count($baris);
                $awal = [$it['jenis']['nama'], Tanggal::rentang($it['mulai'], $it['selesai'])];
                foreach ($it['spt'] ?: [null] as $r) {
                    $baris[] = [...($awal ?? ['', '']), ...($r ? [
                        $r['nomor_lengkap'], Tanggal::rentang($r['mulai'], $r['selesai']),
                        PembangunMonitoring::KESESUAIAN[$r['kesesuaian']], PembangunMonitoring::labelStatus($r),
                        $r['kendala'] ? $r['kendala']['kategori'].': '.$r['kendala']['uraian'] : '–',
                    ] : ['–', 'Belum ada SPT', '–', '–', '–'])];
                    $awal = null;
                }
                $gabung[] = [$mulai, count($baris) - 1];
            }
            $hasil[] = ['id' => $g['irban']['id'], 'irban' => $g['irban']['nama'], 'baris' => $baris ?: [['Tidak ada item PKPT pada periode ini.', '', '', '', '', '', '']], 'gabung' => $gabung];
        }

        return $hasil;
    }

    /**
     * Sections shown on the page and in the files. $tab: 'irban' (one Rencana vs Realisasi table per irban), 'gabungan' (summed), 'semua' (both, for export).
     * Rencana vs Realisasi rows are TW I-IV + Total (one row when $tw is set); Klasifikasi and Jenis rows are the names, valued per $tw or the year total.
     *
     * @return list<array{kode: string, judul: string, kolom: list<string>, tabel: list<array{judul: ?string, baris: list<array{label: string, periode: string, sel: list<int|string>, total: bool}>}>}>
     */
    public function bagian(array $rekap, int $tw, string $tab): array
    {
        $irban = $rekap['irban'];
        $tabel = [];
        if ($tab !== 'gabungan') {
            foreach ($irban['baris'] as $b) {
                $tabel[] = ['judul' => $b['nama'], 'baris' => $this->barisTw($b, self::RR[1], $tw)];
            }
        }
        if ($tab === 'gabungan' || ($tab === 'semua' && count($irban['baris']) > 1)) {
            $tabel[] = ['judul' => $tab === 'gabungan' ? null : 'Gabungan', 'baris' => $this->barisTw($irban['total'], self::RR[1], $tw)];
        }

        return [
            ['kode' => 'rr', 'judul' => 'Rencana vs Realisasi', 'kolom' => self::RR[0], 'tabel' => $tabel],
            ['kode' => 'klasifikasi', 'judul' => 'Per Klasifikasi', 'kolom' => self::KL[0], 'tabel' => [['judul' => null, 'baris' => $this->barisNama($rekap['klasifikasi'], self::KL[1], $tw)]]],
            ['kode' => 'jenis', 'judul' => 'Per Jenis Penugasan', 'kolom' => self::JN[0], 'tabel' => [['judul' => null, 'baris' => $this->barisNama($rekap['jenis'], self::JN[1], $tw)]]],
            ['kode' => 'dana', 'judul' => 'Realisasi Dana', 'kolom' => self::DN[0], 'tabel' => $this->tabelDana($rekap['dana'], $tw, $tab)],
        ];
    }

    /** One table per irban (tab irban / semua) and the summed one (gabungan / semua), like Rencana vs Realisasi; Dana stays an int, `keterangan` is added per cell. */
    private function tabelDana(array $dana, int $tw, string $tab): array
    {
        $ket = fn (array $b) => ['tw' => array_map(fn ($c) => $c + ['keterangan' => "{$c['terisi']} dari {$c['jumlah']} SPT terisi"], $b['tw']),
            'total' => $b['total'] + ['keterangan' => "{$b['total']['terisi']} dari {$b['total']['jumlah']} SPT terisi"]] + $b;
        $tabel = [];
        if ($tab !== 'gabungan') {
            foreach ($dana['baris'] as $b) {
                $tabel[] = ['judul' => $b['nama'], 'baris' => $this->barisTw($ket($b), self::DN[1], $tw)];
            }
        }
        if ($tab === 'gabungan' || ($tab === 'semua' && count($dana['baris']) > 1)) {
            $tabel[] = ['judul' => $tab === 'gabungan' ? null : 'Gabungan', 'baris' => $this->barisTw($ket($dana['total']), self::DN[1], $tw)];
        }

        return $tabel;
    }

    private function barisTw(array $b, array $kunci, int $tw): array
    {
        $nama = $b['nama'] ?? 'Gabungan';
        $baris = [];
        foreach ($tw ? [$tw] : array_keys(self::TW) as $n) {
            $baris[] = $this->baris($nama, self::TW[$n], $b['tw'][$n], $kunci, false);
        }
        if (! $tw) {
            $baris[] = $this->baris($nama, 'Total', $b['total'], $kunci, true);
        }

        return $baris;
    }

    private function barisNama(array $tabel, array $kunci, int $tw): array
    {
        $ambil = fn (array $b) => $tw ? $b['tw'][$tw] : $b['total'];
        $periode = $tw ? self::TW[$tw] : 'Setahun';
        $baris = array_map(fn ($b) => $this->baris($b['nama'], $periode, $ambil($b), $kunci, false), $tabel['baris']);
        $baris[] = $this->baris('Total', $periode, $ambil($tabel['total']), $kunci, true);

        return $baris;
    }

    /** `lebih`: index in `sel` of the percentage when it is above 100 (diAtas100), else null; the page and both files mark that cell. */
    private function baris(string $label, string $periode, array $sel, array $kunci, bool $total): array
    {
        return ['label' => $label, 'periode' => $periode, 'total' => $total, 'sel' => array_map(fn ($k) => $sel[$k] ?? '–', $kunci),
            'lebih' => self::diAtas100($sel['persen'] ?? null) ? array_search('persen', $kunci, true) : null];
    }
}
