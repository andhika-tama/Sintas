<?php

namespace App\Services\Ekspor;

use App\Enums\KejadianSpt;
use App\Enums\StatusPengajuan;
use App\Exports\RekapTahunanSheet;
use App\Models\Klasifikasi;
use App\Models\Spt;
use App\Models\User;
use App\Services\RegisterNomor;
use App\Support\Tanggal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Rekap Tahunan (spec 1.1.4 Task 4): the Bagian Umum stambook as 12 month sheets, Excel only (Ekspor::HANYA_XLSX).
 * Rows are the Register rows of the year (RegisterNomor::query, Tahun filter only), placed by the month of tanggal_spt, in number order.
 */
class PembangunRekapTahunan implements Pembangun
{
    /** Sheet names (ruling 1.1.4: full month names in capitals). */
    public const BULAN = ['JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'];

    public function otorisasi(User $user): void
    {
        Gate::forUser($user)->authorize('kelolaNomor', Spt::class);
    }

    /** Only Tahun counts; the Register's other filters are dropped. */
    public function filter(User $user, array $filter): array
    {
        $tahun = (int) ($filter['tahun'] ?? 0);

        return ['tahun' => $tahun >= 2000 ? $tahun : CarbonImmutable::now('Asia/Jakarta')->year];
    }

    public function jumlah(User $user, array $filter): int
    {
        return app(RegisterNomor::class)->query($filter)->count();
    }

    public function namaFile(User $user, array $filter): string
    {
        return "rekap-tahunan-spt-{$filter['tahun']}";
    }

    public function export(User $user, array $filter): Export
    {
        $baris = app(RegisterNomor::class)->query($filter)->get();
        // Legend (1.2.1 Task 6): active klasifikasi plus the inactive ones this year's SPT use, in urutan order.
        $legenda = Klasifikasi::urut()->where(fn ($q) => $q->aktif()->orWhereIn('id', $baris->pluck('klasifikasi_id')->unique()))->get()
            ->map(fn ($k) => ['nama' => $k->nama, 'warna' => $k->warna])->all();
        $perBulan = array_fill(1, 12, []);
        foreach ($baris as $spt) {
            // Every numbered SPT has tanggal_spt (set with the number); spt.tahun is its year, so the month alone picks the sheet.
            $perBulan[$spt->tanggal_spt->month][] = $this->blok($spt);
        }
        $sheets = [];
        foreach (self::BULAN as $i => $bulan) {
            $sheets[] = new RekapTahunanSheet(ucfirst(strtolower($bulan)), $filter['tahun'], $perBulan[$i + 1], $legenda);
        }

        return new class($sheets) implements Export, WithMultipleSheets
        {
            public function __construct(private array $sheets) {}

            public function sheets(): array
            {
                return $this->sheets;
            }
        };
    }

    /** @return array{nomor: string, sppd: string, terbit: string, rangka: string, tim: string, pita: string, warna: ?string} */
    public function blok(Spt $spt): array
    {
        if ($spt->status_pengajuan === StatusPengajuan::Dibatalkan) {
            $alasan = $spt->riwayat->where('kejadian', KejadianSpt::DIBATALKAN)->sortByDesc('id')->first()?->alasan;
            $rangka = 'DIBATALKAN'.($alasan ? ": {$alasan}" : '');
        } else {
            $rangka = trim((string) $spt->uraian_untuk) ?: '-';
        }
        // PPJ first, then the team in print order; names only, each person once.
        $nama = collect([$spt->ppj])->merge($spt->personel->pluck('pegawai'))->filter()->unique('id')->pluck('nama_bergelar')->values();

        return [
            'nomor' => str_pad((string) $spt->nomor_urut, 4, '0', STR_PAD_LEFT),
            'sppd' => trim((string) $spt->nomor_sppd) ?: '-',
            'terbit' => $spt->tanggal_spt->translatedFormat('d F Y'),
            'rangka' => $rangka,
            'tim' => $nama->isEmpty() ? '-' : $nama->map(fn ($n, $i) => ($i + 1).'. '.$n)->implode("\n"),
            'pita' => $spt->tanggal_mulai && $spt->tanggal_selesai ? Tanggal::pita($spt->tanggal_mulai, $spt->tanggal_selesai) : '-',
            'warna' => $spt->klasifikasi?->warna,
        ];
    }

    public function html(User $user, array $filter): string
    {
        throw new LogicException('Rekap Tahunan hanya tersedia dalam Excel.'); // Ekspor::minta refuses pdf first
    }

    public function halaman(User $user): string
    {
        return route('bagian-umum.register', absolute: false);
    }
}
