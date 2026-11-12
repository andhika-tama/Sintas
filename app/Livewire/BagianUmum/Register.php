<?php

namespace App\Livewire\BagianUmum;

use App\Actions\Spt\AturNomorAwal;
use App\Actions\Spt\BatalkanNomor;
use App\Actions\Spt\CatatNomorManual;
use App\Actions\Spt\UbahNomorSppd;
use App\Enums\StatusPengajuan;
use App\Livewire\Concerns\MintaEkspor;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\NomorCounter;
use App\Models\Spt;
use App\Services\Penomoran;
use App\Services\RegisterNomor;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Register Nomor SPT (design/dashboard/bagian-umum.md "Register"): the digital stambook for one year, with Catat Nomor Manual,
 * Batalkan Nomor, Atur nomor awal and Excel/PDF export through App\Services\Ekspor. Rows and exports come from App\Services\RegisterNomor.
 */
#[Title('Register Nomor SPT')]
class Register extends Component
{
    use MintaEkspor, WithPagination;

    public const PER_HALAMAN = 100;

    #[Url]
    public int $tahun = 0;

    #[Url]
    public string $cari = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $irban = '';

    #[Url]
    public string $bulan = '';

    /** The pagination control binds here; the register is fixed at 100 rows per page. */
    public int $perPage = self::PER_HALAMAN;

    public bool $showManual = false;

    /** @var array<string, string> irban_id, klasifikasi_id, kode_arsip_id, jenis_penugasan_id, uraian_untuk, tanggal_mulai, tanggal_selesai, alasan */
    public array $manual = [];

    /** Display only: Penomoran::pratinjau when the form is filled; the issued number is always taken fresh. */
    public string $pratinjau = '';

    public bool $showBatal = false;

    public ?int $batalId = null;

    public string $alasan = '';

    public bool $showAwal = false;

    public string $awalNomor = '';

    public string $awalIrban = '';

    public function mount(): void
    {
        Gate::authorize('kelolaNomor', Spt::class);
        if (! in_array($this->status, ['', StatusPengajuan::Diterima->value, StatusPengajuan::Dibatalkan->value], true)) {
            $this->status = '';
        }
        if ($this->tahun < 2000) {
            $this->tahun = CarbonImmutable::now('Asia/Jakarta')->year;
        }
    }

    public function updated(string $prop): void
    {
        Gate::authorize('kelolaNomor', Spt::class);
        if (in_array($prop, ['tahun', 'cari', 'status', 'irban', 'bulan'], true)) {
            $this->resetPage();
        }
        if (str_starts_with($prop, 'manual')) {
            $this->hitungPratinjau();
        }
    }

    public function bukaManual(): void
    {
        Gate::authorize('kelolaNomor', Spt::class);
        $this->manual = ['irban_id' => '', 'klasifikasi_id' => '', 'kode_arsip_id' => '', 'jenis_penugasan_id' => '', 'uraian_untuk' => '', 'tanggal_mulai' => '', 'tanggal_selesai' => '', 'alasan' => '', 'nomor_sppd' => ''];
        $this->pratinjau = '';
        $this->resetErrorBag();
        $this->showManual = true;
    }

    public function catatManual(CatatNomorManual $aksi): void
    {
        Gate::authorize('kelolaNomor', Spt::class);
        $this->resetErrorBag();
        try {
            $spt = $aksi->handle(auth()->user(), $this->manual + ['jenis_penugasan_id' => null]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["manual.{$k}" => $m])->all());
        }
        $this->showManual = false;
        $this->resetPage();
        $this->dispatch('toast', type: 'success', message: "Nomor {$spt->nomor_lengkap} dicatat");
    }

    public function bukaBatal(int $id, BatalkanNomor $aksi): void
    {
        Gate::authorize('kelolaNomor', Spt::class);
        $spt = Spt::whereNotNull('nomor_urut')->find($id);
        if (! $spt) {
            return;
        }
        Gate::authorize('batalkan', $spt);
        if ($pesan = $aksi->cegah($spt)) {
            $this->galatBatal($pesan);

            return;
        }
        $this->batalId = $spt->id;
        $this->alasan = '';
        $this->resetErrorBag();
        $this->showBatal = true;
    }

    public function batalkan(BatalkanNomor $aksi): void
    {
        Gate::authorize('kelolaNomor', Spt::class);
        $this->resetErrorBag();
        $spt = Spt::whereNotNull('nomor_urut')->findOrFail($this->batalId ?? 0);
        try {
            $aksi->handle(auth()->user(), $spt, $this->alasan);
        } catch (ValidationException $e) {
            // Stale tab (cancelled or Selesai elsewhere): the shared guard's `spt` message is a toast; `alasan` stays inline.
            if (isset($e->errors()['spt'])) {
                $this->galatBatal($e->errors()['spt'][0]);

                return;
            }
            throw $e;
        }
        $this->showBatal = false;
        $this->batalId = null;
        $this->dispatch('toast', type: 'success', message: "Nomor {$spt->nomor_lengkap} dibatalkan");
        $this->dispatch('fokus-ke', id: 'judul-register'); // the row's button is gone
    }

    /** Non-field cancel error: close the modal, toast, focus the h1 (the row's button may be gone or disabled). */
    private function galatBatal(string $pesan): void
    {
        $this->showBatal = false;
        $this->batalId = null;
        $this->dispatch('toast', type: 'error', message: $pesan);
        $this->dispatch('fokus-ke', id: 'judul-register');
    }

    public function bukaAwal(): void
    {
        Gate::authorize('kelolaNomor', Spt::class);
        $this->awalIrban = '';
        $this->awalNomor = (string) (int) NomorCounter::where(app(Penomoran::class)->kunci(null, $this->tahun))->value('nomor_terakhir');
        $this->resetErrorBag();
        $this->showAwal = true;
    }

    public function aturAwal(AturNomorAwal $aksi): void
    {
        Gate::authorize('kelolaNomor', Spt::class);
        $this->resetErrorBag();
        $this->validate(['awalNomor' => ['required', 'integer', 'min:0', 'max:99999999']], [
            'awalNomor.*' => 'Nomor awal harus berupa bilangan bulat 0 atau lebih.',
        ]);
        try {
            $aksi->handle(auth()->user(), $this->tahun, Irban::find((int) $this->awalIrban), (int) $this->awalNomor);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [['nomor' => 'awalNomor', 'irban_id' => 'awalIrban'][$k] ?? $k => $m])->all());
        }
        $this->showAwal = false;
        $this->dispatch('toast', type: 'success', message: "Nomor awal register {$this->tahun} diatur. Nomor berikutnya: ".((int) $this->awalNomor + 1));
    }

    public function ekspor(string $format)
    {
        Gate::authorize('kelolaNomor', Spt::class);
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            return null;
        }

        return $this->mintaEkspor('register', $format, $this->filter());
    }

    /** Rekap Tahunan workbook (spec 1.1.4 Task 4): Excel only and the Tahun filter only. */
    public function eksporRekapTahunan()
    {
        Gate::authorize('kelolaNomor', Spt::class);

        return $this->mintaEkspor('rekap-tahunan', 'xlsx', ['tahun' => $this->tahun]);
    }

    private function filter(): array
    {
        return ['tahun' => $this->tahun, 'cari' => $this->cari, 'status' => $this->status, 'irban' => $this->irban, 'bulan' => $this->bulan];
    }

    private function hitungPratinjau(): void
    {
        $irban = Irban::find((int) ($this->manual['irban_id'] ?? 0));
        $klasifikasi = Klasifikasi::find((int) ($this->manual['klasifikasi_id'] ?? 0));
        $kode = KodeArsip::find((int) ($this->manual['kode_arsip_id'] ?? 0));
        $this->pratinjau = $irban && $klasifikasi && $kode
            ? app(Penomoran::class)->pratinjau($irban, CarbonImmutable::now('Asia/Jakarta'), $klasifikasi, $kode)['nomor_lengkap']
            : '';
    }

    public function render()
    {
        Gate::authorize('kelolaNomor', Spt::class);
        $register = app(RegisterNomor::class);
        $tahunIni = CarbonImmutable::now('Asia/Jakarta')->year;
        $tahunList = Spt::whereNotNull('nomor_urut')->whereNotNull('tahun')->distinct()->pluck('tahun')->push($tahunIni, $this->tahun)->unique()->sortDesc()->mapWithKeys(fn ($t) => [$t => (string) $t])->all();
        $perIrban = app(Settings::class)->get('penomoran.scope') === 'per_irban';
        $awalIrban = Irban::find((int) $this->awalIrban);

        return view('livewire.bagian-umum.register', [
            'register' => $register,
            'pembatalan' => app(BatalkanNomor::class),
            'baris' => $register->query($this->filter())->paginate(self::PER_HALAMAN),
            'tahunList' => $tahunList,
            'irbanOptions' => Irban::orderBy('nama')->pluck('nama', 'id')->all(),
            'statusOptions' => collect([StatusPengajuan::Diterima, StatusPengajuan::Dibatalkan])->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(),
            'bulanOptions' => collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => CarbonImmutable::create(2000, $m, 1)->translatedFormat('F')])->all(),
            'klasifikasiOptions' => Klasifikasi::aktif()->urut()->pluck('nama', 'id')->all(),
            'kodeArsipOptions' => KodeArsip::orderBy('kode')->get()->mapWithKeys(fn ($k) => [$k->id => $k->kode.' · '.$k->nama])->all(),
            'jenisOptions' => JenisPenugasan::orderBy('nama')->pluck('nama', 'id')->all(),
            'perIrban' => $perIrban,
            'awalTerkunci' => $register->nomorAwalTerkunci($this->tahun, $perIrban ? $awalIrban : null),
            'terkunciHalaman' => ! $perIrban && $register->nomorAwalTerkunci($this->tahun, null),
            'batal' => $this->batalId ? Spt::find($this->batalId) : null,
            'tahunIni' => $tahunIni,
            'duplikatSppd' => $this->showManual ? app(UbahNomorSppd::class)->duplikat($tahunIni, $this->manual['nomor_sppd'] ?? null) : null,
        ]);
    }
}
