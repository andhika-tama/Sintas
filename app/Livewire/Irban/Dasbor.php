<?php

namespace App\Livewire\Irban;

use App\Enums\KejadianSpt;
use App\Enums\StatusSpt;
use App\Models\Komentar;
use App\Models\Spt;
use App\Services\PenandaSpt;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dasbor Irban (irban.md): Perlu Tindakan (all years, by urgency), six stat cards and the "Status SPT" mini chart for the current year,
 * and the 10 most recent SPT. Everything is scoped to the user's irban. Read-only; the year follows SPT Saya (Spt::tahunPenugasan) so a
 * card's number equals the SPT Saya rows behind its link.
 */
#[Title('Dasbor Irban')]
class Dasbor extends Component
{
    public function mount(): void
    {
        Gate::authorize('create', Spt::class);
    }

    public function render(PenandaSpt $penanda)
    {
        Gate::authorize('create', Spt::class);
        $user = auth()->user();
        $irbanId = $user->irban_id;
        $tahun = (int) now('Asia/Jakarta')->year;
        $hariIni = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $umur = fn (DateTimeInterface $t) => (int) CarbonImmutable::instance($t)->startOfDay()->diffInDays($hariIni);
        $milik = fn () => Spt::where('irban_id', $irbanId);

        // Terlambat over all years (Perlu Tindakan); the card counts the ones in this year.
        $penyusunan = $milik()->berstatus(StatusSpt::PenyusunanLaporan)->with(['jenisPenugasan', 'objek'])->get();
        $flags = $penanda->untukBanyak($penyusunan);
        $terlambat = $penyusunan->filter(fn (Spt $s) => in_array('terlambat', $flags[$s->id], true));
        $terlambatTahunIni = $milik()->tahunPenugasan($tahun)->whereIn('id', $terlambat->modelKeys())->count();

        $status = $milik()->tahunPenugasan($tahun)->get()->countBy(fn (Spt $s) => $s->status()->value);
        $n = fn (StatusSpt ...$st) => (int) collect($st)->sum(fn ($s) => $status[$s->value] ?? 0);
        $berjalan = $n(StatusSpt::SiapDilaksanakan, StatusSpt::Pelaksanaan, StatusSpt::PenyusunanLaporan);
        // SPT Saya filters one status: Berjalan (three statuses) and Terlambat (a flag) have no matching filter, so they are not links.
        $link = fn (StatusSpt $s) => route('irban.spt', ['status' => $s->value, 'tahun' => $tahun]);
        $kartu = [
            ['label' => 'Draf', 'nilai' => $n(StatusSpt::Draf), 'href' => $link(StatusSpt::Draf), 'alert' => false],
            ['label' => 'Menunggu Nomor', 'nilai' => $n(StatusSpt::MenungguNomor), 'href' => $link(StatusSpt::MenungguNomor), 'alert' => false],
            ['label' => 'Perlu Revisi', 'nilai' => $n(StatusSpt::PerluRevisi), 'href' => $link(StatusSpt::PerluRevisi), 'alert' => false],
            ['label' => 'Berjalan', 'nilai' => $berjalan, 'href' => null, 'alert' => false],
            ['label' => 'Selesai', 'nilai' => $n(StatusSpt::Selesai), 'href' => $link(StatusSpt::Selesai), 'alert' => false],
            ['label' => 'Terlambat', 'nilai' => $terlambatTahunIni, 'href' => null, 'alert' => true],
        ];

        $permintaan = Komentar::permintaanTerbuka()->whereHas('spt', fn ($q) => $q->where('irban_id', $irbanId))
            ->with(['spt.jenisPenugasan', 'spt.objek'])->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Komentar $k) => ['jenis' => 'permintaan', 'spt' => $k->spt, 'umur' => $umur($k->created_at),
                'href' => route('spt.show', $k->spt).'#komentar-'.$k->id]);
        $revisi = $milik()->berstatus(StatusSpt::PerluRevisi)->with(['jenisPenugasan', 'objek'])
            ->withMax(['riwayat as revisi_pada' => fn ($q) => $q->where('kejadian', KejadianSpt::PERLU_REVISI)], 'created_at')->get()
            ->map(fn (Spt $s) => ['jenis' => 'perlu_revisi', 'spt' => $s,
                'umur' => $umur($s->revisi_pada ? CarbonImmutable::parse($s->revisi_pada) : $s->updated_at), 'href' => route('irban.spt.ubah', $s)])
            ->sortBy([['umur', 'desc'], [fn ($a, $b) => $a['spt']->id <=> $b['spt']->id]]);
        $telat = $terlambat->map(fn (Spt $s) => ['jenis' => 'terlambat', 'spt' => $s, 'umur' => $umur($s->tanggal_selesai), 'href' => route('spt.show', $s)])
            ->sortBy([['umur', 'desc'], [fn ($a, $b) => $a['spt']->id <=> $b['spt']->id]]);

        $grafikStatus = [StatusSpt::Draf, StatusSpt::MenungguNomor, StatusSpt::PerluRevisi, StatusSpt::Selesai];

        return view('livewire.irban.dasbor', [
            'irban' => $user->irban,
            'tahun' => $tahun,
            'tindakan' => [...$permintaan->values()->all(), ...$revisi->values()->all(), ...$telat->values()->all()],
            'kartu' => $kartu,
            // Five disjoint statuses (Terlambat is a flag inside Berjalan, so it is not stacked twice).
            'grafik' => [
                'tahun' => $tahun,
                'label' => ['Draf', 'Menunggu Nomor', 'Perlu Revisi', 'Berjalan', 'Selesai'],
                'nilai' => [$n($grafikStatus[0]), $n($grafikStatus[1]), $n($grafikStatus[2]), $berjalan, $n($grafikStatus[3])],
            ],
            'terbaru' => $milik()->tahunPenugasan($tahun)->with(['jenisPenugasan', 'objek'])->latest('updated_at')->latest('id')->limit(10)->get(),
        ]);
    }
}
