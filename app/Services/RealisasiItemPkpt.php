<?php

namespace App\Services;

use App\Enums\StatusPengajuan;
use App\Enums\StatusPkpt;
use App\Models\Irban;
use App\Models\PkptItem;
use App\Models\Spt;
use Carbon\CarbonImmutable;

/** Detail Realisasi (Rekap): per irban, the Disahkan PKPT items of a year/quarter with their accepted SPT. */
class RealisasiItemPkpt
{
    public function __construct(private Triwulan $triwulan, private RekapTriwulan $rekap, private KesesuaianPkpt $kesesuaian, private MonitoringSpt $monitoring) {}

    /**
     * An item is listed when its rencana quarter (RekapTriwulan::rencanaItem) is $tw, or when it has an accepted SPT whose
     * basis date falls in $tw (and $tahun). $tw = 0 means the whole year. SPT without an item never appear.
     * $saring (1.3): SPT rows follow Spt::scopeSaring, items follow PkptItem::scopeSaring (jenis). When RekapTriwulan::aturanRencana
     * hides the rencana (klasifikasi not wajib, kode arsip), an item is listed only through a matching SPT, never for its rencana alone.
     *
     * @return list<array{irban: array{id: int, nama: string}, item: list<array{item_id: int, jenis: array{nama: string, warna: string, warna_teks: string}, keterangan: ?string, mulai: CarbonImmutable, selesai: CarbonImmutable, direvisi: bool, spt: list<array>}>}>
     */
    public function untuk(int $tahun, int $tw, ?int $irbanId, array $saring = []): array
    {
        $saring = app(SaringSpt::class)->dari($saring);
        $tampil = $this->rekap->aturanRencana($saring)['tampil'];
        $irbans = Irban::orderBy('id')->when($irbanId, fn ($q) => $q->whereKey($irbanId))->get(['id', 'nama']);
        $items = PkptItem::aktif()->saring($saring)->with('jenisPenugasan')
            ->whereHas('pkpt', fn ($q) => $q->where('tahun', $tahun)->where('status', StatusPkpt::Disahkan))
            ->when($irbanId, fn ($q) => $q->where('irban_id', $irbanId))
            ->get();

        $spts = Spt::where('status_pengajuan', StatusPengajuan::Diterima)
            ->whereIn('pkpt_item_id', $items->modelKeys())->saring($saring)
            ->tap(fn ($q) => $this->triwulan->batasi($q, $tahun, $tw ?: null))
            ->with(['irban:id,nama', 'jenisPenugasan', 'kendala'])
            ->orderBy('tanggal_mulai')->orderBy('id')->get();
        $baris = collect($this->monitoring->barisSpt($spts))->keyBy('id');
        $sptPerItem = $spts->groupBy('pkpt_item_id');

        $hasil = [];
        foreach ($items as $item) {
            $rows = ($sptPerItem[$item->id] ?? collect())->map(fn ($s) => $baris[$s->id])->all();
            $rencana = $this->rekap->rencanaItem($item, $tahun);
            if (! $rows && ! ($tampil && $rencana && ($tw === 0 || $rencana[0] === $tw))) {
                continue;
            }
            [$mulai, $selesai] = $this->kesesuaian->rentang($item);
            $hasil[$item->irban_id][] = [
                'item_id' => $item->id,
                'jenis' => ['nama' => $item->jenisPenugasan->nama, 'warna' => $item->jenisPenugasan->warna, 'warna_teks' => $item->jenisPenugasan->warna_teks],
                'keterangan' => $item->keterangan,
                'mulai' => $mulai,
                'selesai' => $selesai,
                'direvisi' => $item->direvisi(),
                'spt' => $rows,
            ];
        }

        return $irbans->map(fn ($i) => [
            'irban' => ['id' => $i->id, 'nama' => $i->nama],
            'item' => collect($hasil[$i->id] ?? [])->sortBy([['mulai', 'asc'], ['item_id', 'asc']])->values()->all(),
        ])->all();
    }
}
