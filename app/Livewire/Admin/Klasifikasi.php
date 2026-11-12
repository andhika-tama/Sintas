<?php

namespace App\Livewire\Admin;

use App\Actions\MasterData\DeleteKlasifikasi;
use App\Actions\MasterData\SaveKlasifikasi;
use App\Models\Klasifikasi as Model;
use App\Services\ColorRules;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Admin master klasifikasi (1.2.1 Task 6, K7; pattern of Admin\KodeArsip): add, edit (kode locked), deactivate/activate, delete only when unused. */
#[Title('Klasifikasi & Aturan')]
class Klasifikasi extends Component
{
    public const BASI = 'Klasifikasi tidak ditemukan; mungkin sudah dihapus.';

    public bool $open = false;

    #[Locked]
    public ?int $editingId = null;

    public string $kode = '';

    public string $nama = '';

    public bool $wajibItemPkpt = false;

    public string $syaratSelesai = '';

    public bool $tampilPublik = false;

    /** Rekap Tahunan ribbon colour, 6 hex digits without '#'; empty = none. */
    public string $warna = '';

    public string $urutan = '';

    public bool $deleteOpen = false;

    #[Locked]
    public ?int $deletingId = null;

    public function mount(): void
    {
        Gate::authorize('manage-master-data');
    }

    /** The row, or null with a toast when another tab deleted it (pitfall "Item basi"). */
    private function cari(?int $id): ?Model
    {
        $k = $id ? Model::find($id) : null;
        if (! $k) {
            $this->dispatch('toast', type: 'error', message: self::BASI);
        }

        return $k;
    }

    public function create(): void
    {
        Gate::authorize('manage-master-data');
        $this->resetValidation();
        $this->reset('editingId', 'kode', 'nama', 'wajibItemPkpt', 'syaratSelesai', 'tampilPublik', 'warna');
        $this->urutan = (string) ((int) Model::max('urutan') + 1);
        $this->open = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-master-data');
        if (! $k = $this->cari($id)) {
            return;
        }
        $this->resetValidation();
        $this->editingId = $k->id;
        $this->kode = $k->kode;
        $this->nama = $k->nama;
        $this->wajibItemPkpt = $k->wajib_item_pkpt;
        $this->syaratSelesai = $k->syarat_selesai->value;
        $this->tampilPublik = $k->tampil_publik;
        $this->warna = (string) $k->warna;
        $this->urutan = (string) $k->urutan;
        $this->open = true;
    }

    public function save(SaveKlasifikasi $action): void
    {
        Gate::authorize('manage-master-data');
        $this->warna = ltrim(trim($this->warna), '#'); // a pasted "#BDD7EE" is accepted
        $this->validate(SaveKlasifikasi::rules($this->editingId), SaveKlasifikasi::messages());
        $k = null;
        if ($this->editingId && ! $k = $this->cari($this->editingId)) {
            $this->open = false;

            return;
        }
        try {
            $k = $action->handle($k, $this->only('kode', 'nama', 'wajibItemPkpt', 'syaratSelesai', 'tampilPublik', 'warna') + ['urutan' => $this->urutan === '' ? null : $this->urutan]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->open = false;
        $this->dispatch('toast', type: 'success', message: "Klasifikasi {$k->nama} disimpan.");
    }

    public function toggleAktif(int $id): void
    {
        Gate::authorize('manage-master-data');
        if (! $k = $this->cari($id)) {
            return;
        }
        $k->update(['aktif' => ! $k->aktif]);
        $this->dispatch('toast', type: 'success', message: "Klasifikasi {$k->nama} ".($k->aktif ? 'diaktifkan.' : 'dinonaktifkan.'));
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('manage-master-data');
        if (! $k = $this->cari($id)) {
            return;
        }
        $this->deletingId = $k->id;
        $this->deleteOpen = true;
    }

    public function delete(DeleteKlasifikasi $action): void
    {
        Gate::authorize('manage-master-data');
        $this->deleteOpen = false;
        if (! $k = $this->cari($this->deletingId)) {
            return;
        }
        try {
            $action->handle($k);
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: $e->errors()['delete'][0]);

            return;
        }
        $this->dispatch('toast', type: 'success', message: "Klasifikasi {$k->nama} dihapus.");
        $this->dispatch('fokus-ke', id: 'judul-klasifikasi'); // the row's menu is gone
    }

    /** Preview colours (`--type`/`--type-fg`) of a stored or typed hex, or null when it is not a 6-digit hex; the text colour is the one the workbook uses. */
    public static function pratinjau(?string $hex): ?array
    {
        $hex = ltrim(trim((string) $hex), '#');
        if (! preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            return null;
        }
        $warna = '#'.strtoupper($hex);

        return ['warna' => $warna, 'teks' => ColorRules::labelFor($warna) ?? ColorRules::DARK];
    }

    public function render()
    {
        Gate::authorize('manage-master-data');

        return view('livewire.admin.klasifikasi', [
            'rows' => Model::withCount('spt')->urut()->get(),
            'deleting' => $this->deletingId ? Model::find($this->deletingId) : null,
            'pratinjau' => self::pratinjau($this->warna),
        ]);
    }
}
