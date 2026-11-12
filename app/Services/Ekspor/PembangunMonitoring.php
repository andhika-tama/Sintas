<?php

namespace App\Services\Ekspor;

use App\Exports\MonitoringSheet;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Spt;
use App\Models\User;
use App\Services\MonitoringSpt;
use App\Support\Tanggal;
use Illuminate\Support\Facades\Gate;

/** Monitoring SPT export (pimpinan.md "Monitoring table"): one row per SPT, group label in Jenis Penugasan. Rows come from MonitoringSpt::baris(). */
class PembangunMonitoring implements Pembangun
{
    public const KOLOM = ['Nomor SPT', 'Irban', 'Tim · PPJ', 'Jenis Penugasan', 'Periode Penugasan (PKPT)', 'Realisasi Periode', 'Kesesuaian dengan Jadwal PKPT', 'Status', 'Permasalahan/Kendala'];

    /** Labels of the MonitoringSpt::STATUS filter values (page select, PDF filter summary). */
    public const STATUS = ['berjalan' => 'Berjalan', 'selesai' => 'Selesai', 'siap_dilaksanakan' => 'Siap Dilaksanakan', 'pelaksanaan' => 'Pelaksanaan', 'penyusunan_laporan' => 'Penyusunan Laporan'];

    public const KESESUAIAN = ['sesuai' => 'Sesuai', 'tidak_sesuai' => 'Tidak Sesuai PKPT', 'non_pkpt' => 'Non-PKPT'];

    public const TW = [1 => 'TW I', 2 => 'TW II', 3 => 'TW III', 4 => 'TW IV'];

    /** MonitoringSpt::baris() per filter: a direct download asks jumlah() and then export()/html() of the same rows. */
    private array $grup = [];

    public function __construct(private MonitoringSpt $monitoring) {}

    public function otorisasi(User $user): void
    {
        Gate::forUser($user)->authorize('monitoring', Spt::class);
    }

    public function filter(User $user, array $filter): array
    {
        return $this->monitoring->filter($filter);
    }

    public function jumlah(User $user, array $filter): int
    {
        return array_sum(array_map(fn ($g) => count($g['spt']), $this->grup($filter)));
    }

    public function namaFile(User $user, array $filter): string
    {
        $kode = $filter['irban'] ? Irban::whereKey($filter['irban'])->value('kode_nomor') : null;

        return "monitoring-spt-{$filter['tahun']}".($kode ? "-irban-{$kode}" : '');
    }

    public function export(User $user, array $filter): MonitoringSheet
    {
        return new MonitoringSheet($filter['tahun'], $this->ringkasan($filter), $this->baris($filter), now('Asia/Jakarta')->translatedFormat('d F Y H:i').' WIB');
    }

    public function html(User $user, array $filter): string
    {
        // The PDF has no Tim · PPJ column: it sits under the Irban name (second line, with the PPJ marker), so the view gets the rows minus column 3.
        $baris = $this->baris($filter, true);

        return view('pdf.monitoring-spt', [
            'tahun' => $filter['tahun'], 'filter' => $this->ringkasan($filter), 'kolom' => array_values(array_diff_key(self::KOLOM, [2 => 1])), 'baris' => $baris,
            'dibuat' => now('Asia/Jakarta')->translatedFormat('d F Y H:i').' WIB',
        ])->render();
    }

    public function halaman(User $user): string
    {
        return route('pimpinan.monitoring', absolute: false);
    }

    /** Status text of a MonitoringSpt row in files, shared with the Rekap detail export. */
    public static function labelStatus(array $r): string
    {
        return $r['status']->label().(in_array('terlambat', $r['penanda'], true) ? ', Terlambat' : '');
    }

    /** @return list<list<?string>> */
    private function baris(array $filter, bool $untukPdf = false): array
    {
        $out = [];
        foreach ($this->grup($filter) as $g) {
            $pkpt = $g['mulai'] ? Tanggal::rentang($g['mulai'], $g['selesai']) : '–';
            foreach ($g['spt'] as $r) {
                $out[] = [
                    $r['nomor_lengkap'], $untukPdf ? [$r['irban'], MonitoringSpt::timPpj($r, true)] : $r['irban'], ...($untukPdf ? [] : [MonitoringSpt::timPpj($r)]), $g['label'], $pkpt,
                    $r['mulai'] && $r['selesai'] ? Tanggal::rentang($r['mulai'], $r['selesai']) : '–',
                    self::KESESUAIAN[$r['kesesuaian']], self::labelStatus($r),
                    $r['kendala'] ? "{$r['kendala']['kategori']}: {$r['kendala']['uraian']}" : null,
                ];
            }
        }

        return $out;
    }

    private function grup(array $filter): array
    {
        return $this->grup[serialize($filter)] ??= $this->monitoring->baris($filter);
    }

    /** @return list<string> */
    private function ringkasan(array $f): array
    {
        return array_values(array_filter([
            $f['tw'] ? self::TW[$f['tw']] : null,
            $f['irban'] ? 'Irban: '.Irban::whereKey($f['irban'])->value('nama') : null,
            $f['status'] ? 'Status: '.self::STATUS[$f['status']] : null,
            $f['jenis'] ? 'Jenis: '.JenisPenugasan::whereKey($f['jenis'])->value('nama') : null,
            $f['kesesuaian'] ? 'Kesesuaian: '.self::KESESUAIAN[$f['kesesuaian']] : null,
            $f['penanda'] ? 'Terlambat' : null,
            $f['penjelasan'] ? 'Permintaan Penjelasan Terbuka' : null,
            $f['cari'] !== null ? 'Cari: '.$f['cari'] : null,
        ]));
    }
}
