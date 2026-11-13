<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Enums\StatusPkpt;
use App\Livewire\Concerns\MintaEkspor;
use App\Models\Irban;
use App\Models\Pkpt;
use App\Models\Spt;
use App\Services\Ekspor\PembangunRekap;
use App\Services\RealisasiItemPkpt;
use App\Services\RekapTriwulan;
use App\Services\SaringSpt;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Rekap Triwulanan (Pimpinan) / Rekap Saya (Irban), one component for both routes. Numbers come from RekapTriwulan and the
 * section layout from PembangunRekap::bagian(), the same code the Excel/PDF export uses. An Irban is always scoped to its own irban.
 */
class Rekap extends Component
{
    use MintaEkspor;

    #[Url]
    public string $tahun = '';

    /** 'irban' (Per Irban / Ringkasan) | 'gabungan' (Pimpinan only) | 'detail' (Detail Realisasi). */
    #[Url]
    public string $tab = 'irban';

    /** '' = all quarters, else 1-4. */
    #[Url]
    public string $tw = '';

    /** Pimpinan may scope by URL; ignored for Irban accounts. */
    #[Url]
    public string $irban = '';

    /** 1.3: Klasifikasi / Kode Arsip ids and Jenis (master id or 'lainnya'), validated by SaringSpt through PembangunRekap::filter(). */
    #[Url]
    public string $klasifikasi = '';

    #[Url]
    public string $kode_arsip = '';

    #[Url]
    public string $jenis = '';

    private const FILTER = ['tahun', 'tw', 'irban', 'klasifikasi', 'kode_arsip', 'jenis'];

    public function mount(): void
    {
        Gate::authorize('rekap', Spt::class);
    }

    public function updated(string $prop): void
    {
        Gate::authorize('rekap', Spt::class);
    }

    /** Clears every filter but Tahun. */
    public function aturUlang(): void
    {
        Gate::authorize('rekap', Spt::class);
        $this->reset('tw', 'irban', 'klasifikasi', 'kode_arsip', 'jenis');
    }

    /** Export modal "Pilih isi ekspor": sections picked in $bagianEkspor, format fixed by the button that opened it. */
    public array $bagianEkspor = [];

    public string $formatEkspor = '';

    public bool $pilihEkspor = false;

    public function bukaEkspor(string $format): void
    {
        Gate::authorize('rekap', Spt::class);
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            return;
        }
        $this->resetErrorBag('bagianEkspor');
        $this->formatEkspor = $format;
        $this->bagianEkspor = PembangunRekap::BAGIAN_DEFAULT[$this->tab] ?? PembangunRekap::BAGIAN_DEFAULT['irban'];
        $this->pilihEkspor = true;
    }

    public function unduhEkspor()
    {
        Gate::authorize('rekap', Spt::class);
        if (! in_array($this->formatEkspor, ['xlsx', 'pdf'], true)) {
            $this->pilihEkspor = false;

            return null;
        }
        $bagian = array_values(array_intersect(PembangunRekap::BAGIAN, array_filter((array) $this->bagianEkspor, 'is_string')));
        if (! $bagian) {
            $this->addError('bagianEkspor', 'Pilih minimal satu bagian.');

            return null;
        }
        $this->pilihEkspor = false;

        return $this->mintaEkspor('rekap', $this->formatEkspor, $this->only(self::FILTER) + ['bagian' => $bagian]);
    }

    public function render()
    {
        Gate::authorize('rekap', Spt::class);
        $user = auth()->user();
        $pembangun = app(PembangunRekap::class);
        $f = $pembangun->filter($user, $this->only(self::FILTER));
        $pimpinan = $user->role === Role::Pimpinan;
        $this->tahun = (string) $f['tahun'];
        $this->tw = $f['tw'] ? (string) $f['tw'] : '';
        $this->irban = $f['irban'] ? (string) $f['irban'] : '';
        foreach ($f['saring'] as $k => $v) {
            $this->{$k} = (string) $v; // unknown URL values fall back visibly, as on Monitoring
        }
        $this->tab = match (true) {
            $this->tab === 'detail', $pimpinan && $this->tab === 'gabungan' => $this->tab,
            default => 'irban',
        };
        $detail = $this->tab === 'detail';

        // The Detail tab needs only the year's status for its alert and the C1/C2 note, not the whole calculation.
        $rekap = $detail
            ? ['tahun' => $f['tahun'], 'pkpt_disahkan' => Pkpt::where('tahun', $f['tahun'])->where('status', StatusPkpt::Disahkan)->exists(),
                'rencana' => app(RekapTriwulan::class)->aturanRencana(app(SaringSpt::class)->dari($f['saring']))]
            : app(RekapTriwulan::class)->hitung($f['tahun'], $f['irban'], $f['saring']);
        // An Irban account only lists the years it has SPT or PKPT items in; the current year and the selected one stay.
        $milik = $pimpinan ? null : $user->irban_id;
        $tahunList = Spt::whereNotNull('tahun')->when($milik, fn ($q, $id) => $q->where('irban_id', $id))->distinct()->pluck('tahun')
            ->merge(Pkpt::when($milik, fn ($q, $id) => $q->whereHas('items', fn ($i) => $i->where('irban_id', $id)))->distinct()->pluck('tahun'))
            ->push((int) now('Asia/Jakarta')->year, $f['tahun'])->unique()->sortDesc()->mapWithKeys(fn ($t) => [$t => (string) $t])->all();

        return view('livewire.rekap', [
            'judul' => $pimpinan ? 'Rekap Triwulanan' : 'Rekap Saya',
            'rekap' => $rekap,
            'bagian' => $detail ? [] : $pembangun->bagian($rekap, $f['tw'], $this->tab),
            'catatanTarget' => ! $detail && $pembangun->tanpaTarget($f['tahun'], $f['tw'], $f['irban'], $f['saring']) > 0,
            'detail' => $detail ? app(RealisasiItemPkpt::class)->untuk($f['tahun'], $f['tw'], $f['irban'], $f['saring']) : null,
            'tahunList' => $tahunList,
            'irbanOptions' => $pimpinan ? Irban::orderBy('nama')->pluck('nama', 'id')->all() : [],
            'saringOpsi' => app(SaringSpt::class)->opsi(),
            'saringAktif' => app(SaringSpt::class)->aktif($f['saring']),
            'filterAktif' => $f['tw'] || $f['irban'] && $pimpinan || app(SaringSpt::class)->aktif($f['saring']),
            'pimpinan' => $pimpinan,
        ])->title($pimpinan ? 'Rekap Triwulanan' : 'Rekap Saya');
    }
}
