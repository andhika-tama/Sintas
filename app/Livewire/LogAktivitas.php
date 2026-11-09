<?php

namespace App\Livewire;

use App\Models\Aktivitas;
use App\Models\Spt;
use App\Models\User;
use App\Support\LabelAktivitas;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/** Log Aktivitas (admin.md, PRD §7.12): read-only, 25 newest first, filters in the URL. Shared by Admin and Pimpinan (Gate `lihat-log`). */
class LogAktivitas extends Component
{
    use WithPagination;

    public const PER_HALAMAN = 25;

    public const AKSI = ['created' => 'Dibuat', 'updated' => 'Diubah', 'deleted' => 'Dihapus', 'lainnya' => 'Lainnya'];

    private const FILTER = ['dari', 'sampai', 'pengguna', 'objek', 'aksi', 'spt'];

    #[Url]
    public string $dari = '';

    #[Url]
    public string $sampai = '';

    #[Url]
    public string $pengguna = '';

    #[Url]
    public string $objek = '';

    #[Url]
    public string $aksi = '';

    #[Url]
    public string $spt = '';

    public function mount(): void
    {
        Gate::authorize('lihat-log');
        $this->normalisasi();
    }

    public function updated(string $prop): void
    {
        Gate::authorize('lihat-log');
        if (in_array($prop, self::FILTER, true)) {
            $this->normalisasi();
            $this->resetPage();
        }
    }

    /** @return array<int, string> id => name of every user who appears as a causer */
    #[Computed]
    public function penggunaOpsi(): array
    {
        return User::whereIn('id', Aktivitas::where('causer_type', (new User)->getMorphClass())->whereNotNull('causer_id')->select('causer_id'))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    public function aturUlang(): void
    {
        Gate::authorize('lihat-log');
        foreach (self::FILTER as $k) {
            $this->{$k} = '';
        }
        $this->resetPage();
    }

    private static function tanggal(string $v): ?CarbonImmutable
    {
        try {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::createFromFormat('!Y-m-d', $v, 'Asia/Jakarta') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Unknown or malformed URL values are blanked, so the selects and chips show what is applied. */
    private function normalisasi(): void
    {
        $pengguna = $this->penggunaOpsi;
        $this->dari = self::tanggal($this->dari) ? $this->dari : '';
        $this->sampai = self::tanggal($this->sampai) ? $this->sampai : '';
        $this->pengguna = isset($pengguna[(int) $this->pengguna]) && ctype_digit($this->pengguna) ? $this->pengguna : '';
        $this->objek = array_key_exists($this->objek, LabelAktivitas::opsiObjek()) ? $this->objek : '';
        $this->aksi = array_key_exists($this->aksi, self::AKSI) ? $this->aksi : '';
        $this->spt = ctype_digit($this->spt) && $this->spt !== '' ? $this->spt : '';
    }

    public function render()
    {
        Gate::authorize('lihat-log');
        $morph = (new User)->getMorphClass();
        $aktif = array_filter($this->only(self::FILTER), fn ($v) => $v !== '');

        $q = Aktivitas::query()->with(['causer', 'diinputOleh'])->latest('id');
        if ($this->dari) {
            $q->where('created_at', '>=', self::tanggal($this->dari));
        }
        if ($this->sampai) {
            $q->where('created_at', '<', self::tanggal($this->sampai)->addDay());
        }
        if ($this->pengguna) {
            $q->where('causer_type', $morph)->where('causer_id', $this->pengguna);
        }
        if ($this->objek) {
            $kelas = LabelAktivitas::kelas($this->objek);
            $kelas ? $q->where('subject_type', Relation::getMorphAlias($kelas)) : $q->where('log_name', $this->objek)->whereNull('subject_type');
        }
        if ($this->aksi) {
            $this->aksi === 'lainnya' ? $q->where(fn ($w) => $w->whereNull('event')->orWhereNotIn('event', ['created', 'updated', 'deleted'])) : $q->where('event', $this->aksi);
        }
        if ($this->spt) {
            $q->untukSpt((int) $this->spt);
        }

        $baris = $q->paginate(self::PER_HALAMAN);
        // Only an SPT subject is shown (its nomor); loading every type would fail on rows of a deleted model (PkptUsulan, 1.2 Task 8).
        $baris->getCollection()->where('subject_type', (new Spt)->getMorphClass())->load('subject');

        return view('livewire.log-aktivitas', [
            'baris' => $baris,
            'aktif' => $aktif,
            'penggunaOpsi' => $this->penggunaOpsi,
        ])->title('Log Aktivitas');
    }
}
