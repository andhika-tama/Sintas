<?php

namespace App\Livewire\Pimpinan;

use App\Actions\PerubahanPenugasan\PutuskanPerubahanPenugasan;
use App\Enums\Role;
use App\Enums\StatusPerubahanPenugasan;
use App\Models\PerubahanPenugasan as Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pimpinan list of Perubahan Penugasan (1.2 Task 7, K1): status tabs (Diajukan by default), Setujui behind a confirm modal, Tolak with a
 * required catatan. Rules live in PutuskanPerubahanPenugasan; a stale decision (decided in another tab, item removed) closes the modal as a
 * toast and moves focus to the h1, since the row and its buttons leave the Diajukan list.
 */
#[Title('Perubahan Penugasan')]
class PerubahanPenugasan extends Component
{
    use WithPagination;

    private const FOKUS = 'judul-perubahan-penugasan';

    #[Url]
    public string $status = 'diajukan';

    #[Locked]
    public int $perPage = 20;

    /** Request of the open Setujui/Tolak modal. */
    #[Locked]
    public ?int $pilihId = null;

    public bool $setujuiOpen = false;

    public bool $tolakOpen = false;

    public string $catatan = '';

    public function mount(): void
    {
        $this->izin();
    }

    public function updatedStatus(): void
    {
        $this->izin();
        $this->resetPage();
    }

    public function bukaSetujui(int $id): void
    {
        $this->buka($id, 'setujuiOpen');
    }

    public function bukaTolak(int $id): void
    {
        $this->buka($id, 'tolakOpen');
    }

    public function setujui(PutuskanPerubahanPenugasan $putuskan): void
    {
        $this->putuskan($putuskan, true);
    }

    public function tolak(PutuskanPerubahanPenugasan $putuskan): void
    {
        $this->putuskan($putuskan, false);
    }

    private function buka(int $id, string $modal): void
    {
        $this->izin();
        $this->resetValidation();
        $this->reset('catatan', 'setujuiOpen', 'tolakOpen');
        $p = Model::find($id);
        // The row goes with its item (cascade) or was decided in another tab: same toasts as the action.
        if (! $p || $p->status !== StatusPerubahanPenugasan::Diajukan) {
            $this->basi($p ? PutuskanPerubahanPenugasan::SUDAH_DIPUTUSKAN : PutuskanPerubahanPenugasan::ITEM_BASI);

            return;
        }
        $this->pilihId = $p->id;
        $this->{$modal} = true;
    }

    private function putuskan(PutuskanPerubahanPenugasan $putuskan, bool $setuju): void
    {
        $this->izin();
        $this->resetValidation();
        $p = $this->pilihId ? Model::find($this->pilihId) : null;
        if (! $p) {
            $this->basi(PutuskanPerubahanPenugasan::ITEM_BASI);

            return;
        }
        try {
            $putuskan->handle(auth()->user(), $p, $setuju, $setuju ? null : $this->catatan);
        } catch (ValidationException $e) {
            $galat = $e->errors();
            if (isset($galat['catatan'])) {
                $this->addError('catatan', $galat['catatan'][0]);
                $this->dispatch('fokus-ke', id: 'catatan-tolak');

                return;
            }
            // Every other key has no field here (pitfall "Galat non-kolom"): a toast.
            $this->basi(collect($galat)->flatten()->first());

            return;
        }
        $this->reset('setujuiOpen', 'tolakOpen', 'catatan', 'pilihId');
        $this->dispatch('toast', type: 'success', message: $setuju ? 'Perubahan Penugasan disetujui.' : 'Perubahan Penugasan ditolak.');
        $this->dispatch('fokus-ke', id: self::FOKUS);
    }

    private function basi(string $pesan): void
    {
        $this->reset('setujuiOpen', 'tolakOpen', 'catatan', 'pilihId');
        $this->dispatch('toast', type: 'error', message: $pesan);
        $this->dispatch('fokus-ke', id: self::FOKUS);
    }

    /** The Livewire update endpoint skips the route middleware, so every request re-checks the role (K13: Pimpinan only). */
    private function izin(): void
    {
        abort_unless(auth()->user()?->role === Role::Pimpinan, 403);
    }

    public function render()
    {
        $this->izin();
        $status = StatusPerubahanPenugasan::tryFrom($this->status) ?? StatusPerubahanPenugasan::Diajukan;
        $this->status = $status->value;
        $q = Model::query()->where('status', $status->value)->with(['item.jenisPenugasan', 'irban:id,nama', 'diinputOleh:id,nama_bergelar']);
        $q = $status === StatusPerubahanPenugasan::Diajukan
            ? $q->orderBy('created_at')->orderBy('id')
            : $q->orderByDesc('diputuskan_pada')->orderByDesc('id');

        return view('livewire.pimpinan.perubahan-penugasan', [
            'daftar' => $q->paginate($this->perPage),
            'jumlahDiajukan' => Model::where('status', StatusPerubahanPenugasan::Diajukan->value)->count(),
            'pilih' => $this->pilihId && ($this->setujuiOpen || $this->tolakOpen) ? Model::with(['item.jenisPenugasan', 'irban:id,nama'])->find($this->pilihId) : null,
        ]);
    }
}
