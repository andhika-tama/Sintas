<?php

namespace App\Services;

use App\Enums\StatusPengajuan;
use App\Enums\StatusSpt;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Spt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/** Monitoring table (PRD §7.9): accepted SPT grouped per PKPT item, then "Di luar PKPT". Shared by the page, export and dashboard cards. */
class MonitoringSpt
{
    /** `berjalan` = On Process group, `selesai`, or one On Process status. */
    public const STATUS = ['berjalan', 'selesai', 'siap_dilaksanakan', 'pelaksanaan', 'penyusunan_laporan'];

    public const KESESUAIAN = ['sesuai', 'tidak_sesuai', 'non_pkpt'];

    /** Orders of daftar(): by deadline (unfinished first) or by SPT date, newest first. */
    public const URUT = ['tenggat', 'terbit'];

    public function __construct(private Triwulan $triwulan, private PenandaSpt $penanda, private KesesuaianPkpt $kesesuaian) {}

    /**
     * Validated filter; unknown or malformed values fall back to the default (tahun = current Jakarta year, others null).
     *
     * @return array{tahun: int, tw: ?int, irban: ?int, status: ?string, jenis: ?int, kesesuaian: ?string, penanda: ?string, penjelasan: ?string, cari: ?string}
     */
    public function filter(array $input): array
    {
        $int = fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
        $pilih = fn ($v, array $boleh) => is_string($v) && in_array($v, $boleh, true) ? $v : null;
        $tahun = $int($input['tahun'] ?? null);
        $tw = $int($input['tw'] ?? null);
        $irban = $int($input['irban'] ?? null);
        $jenis = $int($input['jenis'] ?? null);

        return [
            'tahun' => $tahun !== null && $tahun >= 2000 && $tahun <= 2100 ? $tahun : (int) now('Asia/Jakarta')->year,
            'tw' => $tw !== null && $tw >= 1 && $tw <= 4 ? $tw : null,
            'irban' => $irban && Irban::whereKey($irban)->exists() ? $irban : null,
            'status' => $pilih($input['status'] ?? null, self::STATUS),
            'jenis' => $jenis && JenisPenugasan::whereKey($jenis)->exists() ? $jenis : null,
            'kesesuaian' => $pilih($input['kesesuaian'] ?? null, self::KESESUAIAN),
            'penanda' => $pilih($input['penanda'] ?? null, ['terlambat']),
            'penjelasan' => $pilih($input['penjelasan'] ?? null, ['terbuka']),
            'cari' => is_string($input['cari'] ?? null) ? (($c = trim(mb_substr(trim($input['cari']), 0, 100))) !== '' ? $c : null) : null,
        ];
    }

    /**
     * Groups (items by effective start, then id; "Di luar PKPT" last; only groups with rows):
     *   {item_id: ?int, label: string, jenis: ?{id, nama, warna, warna_teks}, mulai: ?CarbonImmutable, selesai: ?CarbonImmutable, irban: ?string, spt: list<row>}
     * Row: {id, nomor_lengkap, tanggal_spt: ?CarbonImmutable, irban: string, jenis: ?{id, nama, warna, warna_teks}, mulai, selesai, kesesuaian: sesuai|tidak_sesuai|non_pkpt,
     *   status: StatusSpt, penanda: list<string>, kendala: ?{kategori, uraian}, url}
     */
    public function baris(array $filter): array
    {
        $grup = [];
        foreach ($this->spt($this->filter($filter)) as [$spt, $baris]) {
            $item = $spt->pkptItem;
            $key = $item?->id ?? 0;
            if (! isset($grup[$key])) {
                [$mulai, $selesai] = $item ? $this->kesesuaian->rentang($item) : [null, null];
                $grup[$key] = [
                    'item_id' => $item?->id,
                    'label' => $item ? $item->jenisPenugasan->nama : 'Di luar PKPT',
                    'jenis' => $this->jenis($item?->jenisPenugasan),
                    'mulai' => $mulai,
                    'selesai' => $selesai,
                    'irban' => $item?->irban->nama,
                    'spt' => [],
                ];
            }
            $grup[$key]['spt'][] = $baris;
        }

        uasort($grup, fn ($a, $b) => [$a['item_id'] === null, $a['mulai'], $a['item_id']] <=> [$b['item_id'] === null, $b['mulai'], $b['item_id']]);

        return array_values($grup);
    }

    /**
     * Flat list of the same rows baris() holds (same filters), ordered by `tenggat` (unfinished first, earliest end, no date last)
     * or `terbit` (tanggal_spt newest first, none last, then nomor_urut desc). An unknown $urut means tenggat.
     *
     * @return list<array> rows in the shape documented on baris()
     */
    public function daftar(array $filter, string $urut = 'tenggat'): array
    {
        $hasil = $this->spt($this->filter($filter));
        $kunci = $urut === 'terbit'
            ? fn ($h) => [$h[0]->tanggal_spt === null, -($h[0]->tanggal_spt?->getTimestamp() ?? 0), -$h[0]->nomor_urut, -$h[0]->id]
            : fn ($h) => [$h[1]['status'] === StatusSpt::Selesai, $h[0]->tanggal_selesai === null, $h[0]->tanggal_selesai?->getTimestamp(), $h[0]->id];
        usort($hasil, fn ($a, $b) => $kunci($a) <=> $kunci($b));

        return array_column($hasil, 1);
    }

    /**
     * Number of SPT rows baris() returns for this filter (dashboard stat cards), through the same query and flag pass.
     * Filters decided in SQL alone are counted in SQL; flag-based ones (penanda, sesuai/tidak_sesuai) run saring() like baris().
     */
    public function jumlah(array $filter): int
    {
        $f = $this->filter($filter);

        return $f['penanda'] || in_array($f['kesesuaian'], ['sesuai', 'tidak_sesuai'], true)
            ? count($this->saring($this->query($f)->get(), $f))
            : $this->query($f)->count();
    }

    /** Accepted SPT after the SQL filters. `penanda=terlambat` covers every year (late work is still work, PRD §14 butir 4), so it skips the tahun clause. */
    private function query(array $f): Builder
    {
        return Spt::where('status_pengajuan', StatusPengajuan::Diterima)
            ->when(! $f['penanda'], fn ($q) => $q->where('tahun', $f['tahun']))
            // The quarter follows the year scope: all years while the Terlambat chip is on, else the selected year.
            ->when($f['tw'], fn ($q, $tw) => $f['penanda'] ? $this->triwulan->batasiTriwulan($q, $tw) : $this->triwulan->batasi($q, $f['tahun'], $tw))
            // ESCAPE '!' instead of backslash: SQLite has no default escape character, MySQL has, and '!' works on both.
            ->when($f['cari'] !== null, fn ($q) => $q->whereRaw("nomor_lengkap LIKE ? ESCAPE '!'", ['%'.preg_replace('/[!%_]/', '!$0', $f['cari']).'%']))
            ->when($f['irban'], fn ($q, $id) => $q->where('irban_id', $id))
            ->when($f['jenis'], fn ($q, $id) => $q->where('jenis_penugasan_id', $id))
            ->when($f['kesesuaian'] === 'non_pkpt', fn ($q) => $q->whereNull('pkpt_item_id'))
            ->when($f['penjelasan'], fn ($q) => $q->whereHas('komentar', fn ($k) => $k->permintaanTerbuka()))
            ->when($f['status'], fn ($q, $s) => $s === 'berjalan' ? $q->whereNull('ditandai_selesai_pada') : $q->berstatus(StatusSpt::from($s)))
            // Narrowing only, the flags in saring() still decide: Terlambat needs Penyusunan Laporan, Tidak Sesuai needs an item.
            ->when($f['penanda'] === 'terlambat', fn ($q) => $q->berstatus(StatusSpt::PenyusunanLaporan))
            ->when($f['kesesuaian'] === 'tidak_sesuai', fn ($q) => $q->whereNotNull('pkpt_item_id'));
    }

    /**
     * Applies the flag-based filters with PenandaSpt (one batch).
     *
     * @param  Collection<int, Spt>  $spts
     * @return list<array{0: Spt, 1: list<string>, 2: string}> [spt, flags, kesesuaian]
     */
    private function saring(Collection $spts, array $f): array
    {
        $penanda = $this->penanda->untukBanyak($spts);
        $hasil = [];
        foreach ($spts as $spt) {
            $flags = $penanda[$spt->id];
            $kesesuaian = match (true) {
                $spt->pkpt_item_id === null => 'non_pkpt',
                in_array('tidak_sesuai_pkpt', $flags, true) => 'tidak_sesuai',
                default => 'sesuai',
            };
            if ((($f['kesesuaian'] ?? null) && $f['kesesuaian'] !== $kesesuaian) || (($f['penanda'] ?? null) && ! in_array($f['penanda'], $flags, true))) {
                continue;
            }
            $hasil[] = [$spt, $flags, $kesesuaian];
        }

        return $hasil;
    }

    /** @return list<array{0: Spt, 1: array}> SPT in scope after every filter, ordered by tanggal_mulai then id */
    private function spt(array $f): array
    {
        $spts = $this->query($f)
            ->with(['irban:id,nama', 'jenisPenugasan', 'kendala', 'pkptItem.irban:id,nama', 'pkptItem.jenisPenugasan'])
            ->orderBy('tanggal_mulai')->orderBy('id')->get();
        $hari = now('Asia/Jakarta')->toDateString(); // once per call, not per row
        $hasil = [];
        foreach ($this->saring($spts, $f) as $h) {
            $hasil[] = [$h[0], $this->bentukBaris($h[0], $h[1], $h[2], $hari)];
        }

        return $hasil;
    }

    /**
     * Rows (the shape documented on baris()) for already loaded SPT, no filters; one PenandaSpt batch. Loads the
     * irban, jenisPenugasan and kendala relations itself (only what is missing), so the query count stays constant.
     *
     * @param  Collection<int, Spt>  $spts
     * @return list<array>
     */
    public function barisSpt(Collection $spts): array
    {
        $spts = new EloquentCollection($spts->all());
        $spts->loadMissing(['irban:id,nama', 'jenisPenugasan', 'kendala']);
        $hari = now('Asia/Jakarta')->toDateString();

        return array_map(fn ($h) => $this->bentukBaris($h[0], $h[1], $h[2], $hari), $this->saring($spts, []));
    }

    private function bentukBaris(Spt $spt, array $flags, string $kesesuaian, string $hari): array
    {
        return [
            'id' => $spt->id,
            'nomor_lengkap' => $spt->nomor_lengkap,
            'tanggal_spt' => $spt->tanggal_spt,
            'irban' => $spt->irban->nama,
            'jenis' => $this->jenis($spt->jenisPenugasan) ?? ($spt->jenis_penugasan_lainnya !== null ? ['id' => null, 'nama' => $spt->jenis_penugasan_lainnya, 'warna' => null, 'warna_teks' => null] : null),
            'mulai' => $spt->tanggal_mulai,
            'selesai' => $spt->tanggal_selesai,
            'kesesuaian' => $kesesuaian,
            'status' => $spt->status($hari),
            'penanda' => $flags,
            'kendala' => $spt->kendala ? ['kategori' => $spt->kendala->kategori, 'uraian' => $spt->kendala->uraian] : null,
            'url' => route('spt.show', $spt->id),
        ];
    }

    private function jenis(?JenisPenugasan $j): ?array
    {
        return $j ? ['id' => $j->id, 'nama' => $j->nama, 'warna' => $j->warna, 'warna_teks' => $j->warna_teks] : null;
    }
}
