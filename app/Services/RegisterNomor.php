<?php

namespace App\Services;

use App\Enums\KejadianSpt;
use App\Enums\PeranTim;
use App\Enums\StatusPengajuan;
use App\Enums\SumberSpt;
use App\Models\Irban;
use App\Models\NomorCounter;
use App\Models\Spt;
use App\Support\Tanggal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;

/** Register Nomor SPT (digital stambook): the one query + row format shared by the table, the Excel export and the PDF. */
class RegisterNomor
{
    public const KOLOM = [
        'Nomor SPT', 'Tanggal SPT', 'Nomor SPPD', 'Klasifikasi', 'Item PKPT', 'Jenis Penugasan', 'Wilayah/Objek', 'Tanggal Mulai',
        'Tanggal Selesai', 'Periode Diperiksa', 'Uraian Penugasan', 'Penanggung Jawab', 'Anggota Pemeriksa', 'Status Pengajuan', 'Catatan', 'Keterangan',
    ];

    /** Empty cell text (manual numbers leave most columns empty). */
    public const KOSONG = '–';

    /** @param array{tahun: int, cari?: string, status?: string, irban?: int|string, bulan?: int|string, klasifikasi?: int|string|null, kode_arsip?: int|string|null, jenis?: int|string|null} $f */
    public function query(array $f): Builder
    {
        $status = $this->status($f);
        $irban = filter_var($f['irban'] ?? null, FILTER_VALIDATE_INT);
        $bulan = $this->bulan($f);
        $cari = trim((string) ($f['cari'] ?? ''));

        return Spt::query()->whereNotNull('nomor_urut')->where('tahun', (int) $f['tahun'])
            ->with(['irban', 'klasifikasi', 'pkptItem.jenisPenugasan', 'jenisPenugasan', 'objek.parent', 'ppj', 'personel.pegawai',
                'riwayat' => fn ($q) => $q->where('kejadian', KejadianSpt::DIBATALKAN)->latest('id')])
            ->when($cari !== '', fn ($q) => $q->where('nomor_lengkap', 'like', '%'.addcslashes($cari, '%_\\').'%'))
            ->when($status, fn ($q) => $q->where('status_pengajuan', $status))
            ->when($irban, fn ($q) => $q->where('irban_id', $irban))
            ->when($bulan, fn ($q) => $q->whereMonth('tanggal_spt', $bulan))
            ->saring(app(SaringSpt::class)->dari($f))
            ->orderBy('nomor_urut')->orderBy('irban_id')->orderBy('id');
    }

    /**
     * Raw values of one row, keyed in KOLOM order (null = empty). The screen renders the rich form (link, badge, short member list) from these;
     * baris() flattens the same values for both exports.
     *
     * @return array{nomor: string, tanggal: ?string, sppd: ?string, klasifikasi: ?string, item: string, jenis: ?string, objek: ?string, mulai: ?string,
     *     selesai: ?string, periode: ?string, uraian: ?string, ppj: ?string, anggota: list<string>, status: StatusPengajuan, catatan: ?string, keterangan: ?string}
     */
    public function data(Spt $spt): array
    {
        $tgl = fn ($d) => $d?->translatedFormat('d F Y');
        $isi = fn ($v) => trim((string) $v) === '' ? null : (string) $v;
        $item = $spt->pkptItem;
        $ket = array_filter([
            $spt->sumber === SumberSpt::Sistem ? null : $spt->sumber->label(),
            ($alasan = $spt->riwayat->where('kejadian', KejadianSpt::DIBATALKAN)->sortByDesc('id')->first()?->alasan) ? "Dibatalkan: {$alasan}" : null,
        ]);

        return [
            'nomor' => (string) $spt->nomor_lengkap,
            'tanggal' => $tgl($spt->tanggal_spt),
            'sppd' => $isi($spt->nomor_sppd),
            'klasifikasi' => $spt->klasifikasi?->nama,
            'item' => $item ? $item->jenisPenugasan->nama.' · '.Tanggal::rentang(...app(KesesuaianPkpt::class)->rentang($item)) : 'Non-PKPT',
            'jenis' => $spt->namaJenis(),
            'objek' => $isi(implode(', ', $spt->namaObjek(true))),
            'mulai' => $tgl($spt->tanggal_mulai),
            'selesai' => $tgl($spt->tanggal_selesai),
            'periode' => $isi($spt->periode_diperiksa),
            'uraian' => $isi($spt->uraian_untuk),
            'ppj' => $spt->ppj?->nama_bergelar,
            // Peran wording of the Ajukan Nomor form ("Ketua Tim"), in print order.
            'anggota' => $spt->personel->map(fn ($p) => $p->pegawai->nama_bergelar.' ('.($p->peran === PeranTim::Ketua ? 'Ketua Tim' : $p->peran->label()).')')->values()->all(),
            'status' => $spt->status_pengajuan,
            'catatan' => $isi($spt->catatan_bagian_umum),
            'keterangan' => $ket ? implode('. ', $ket) : null,
        ];
    }

    /** @return list<string> the 16 cells of one row as plain text, identical for the table and both exports. */
    public function baris(Spt $spt, string $pemisahAnggota = '; '): array
    {
        $d = $this->data($spt);
        $d['anggota'] = implode($pemisahAnggota, $d['anggota']);
        $d['status'] = $d['status']->label();

        return array_values(array_map(fn ($v) => $v === null || $v === '' ? self::KOSONG : $v, $d));
    }

    /**
     * @param  string  $pemisahAnggota  "; " for screen text and PDF; the Excel sheet passes a newline (one person per line in the cell)
     * @return list<list<string>>
     */
    public function semua(array $f, string $pemisahAnggota = '; '): array
    {
        return $this->query($f)->get()->map(fn (Spt $s) => $this->baris($s, $pemisahAnggota))->all();
    }

    /** @return list<string> human summary of the active filters (PDF header). */
    public function ringkasanFilter(array $f): array
    {
        $bulan = $this->bulan($f);
        $irban = filter_var($f['irban'] ?? null, FILTER_VALIDATE_INT);
        $cari = trim((string) ($f['cari'] ?? ''));

        return array_values(array_filter([
            $cari !== '' ? 'Cari: '.$cari : null,
            ($s = $this->status($f)) ? 'Status: '.$s->label() : null,
            $irban ? 'Irban: '.Irban::whereKey($irban)->value('nama') : null,
            $bulan ? 'Bulan: '.now('Asia/Jakarta')->setDay(1)->setMonth($bulan)->translatedFormat('F') : null,
            ...app(SaringSpt::class)->ringkasan($f),
        ]));
    }

    public function html(array $f): string
    {
        return view('pdf.register-nomor', [
            'tahun' => (int) $f['tahun'], 'filter' => $this->ringkasanFilter($f), 'kolom' => self::KOLOM, 'baris' => $this->semua($f),
            'dibuat' => now('Asia/Jakarta')->translatedFormat('d F Y H:i').' WIB',
        ])->render();
    }

    public function pdf(array $f): string
    {
        return Pdf::loadHTML($this->html($f))->setPaper('a4', 'landscape')->output();
    }

    /** True once any SPT holds a number on this register: the starting number can no longer be set (PRD §7.7). */
    public function nomorAwalTerkunci(int $tahun, ?Irban $irban): bool
    {
        $counter = NomorCounter::where(app(Penomoran::class)->kunci($irban, $tahun))->first();

        return $counter && Spt::where('nomor_counter_id', $counter->id)->whereNotNull('nomor_urut')->exists();
    }

    /** Status filter: Semua (null) / Diterima / Dibatalkan, the only states a numbered SPT can hold. */
    public function status(array $f): ?StatusPengajuan
    {
        $s = StatusPengajuan::tryFrom((string) ($f['status'] ?? ''));

        return in_array($s, [StatusPengajuan::Diterima, StatusPengajuan::Dibatalkan], true) ? $s : null;
    }

    private function bulan(array $f): int|false
    {
        return filter_var($f['bulan'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    }
}
