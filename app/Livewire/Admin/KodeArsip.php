<?php

namespace App\Livewire\Admin;

use App\Actions\MasterData\DeleteKodeArsip;
use App\Actions\MasterData\SaveKodeArsip;
use App\Models\KodeArsip as Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Kode Arsip')]
class KodeArsip extends Component
{
    public bool $open = false;

    public ?int $editingId = null;

    public string $kode = '';

    public string $nama = '';

    public bool $deleteOpen = false;

    public ?int $deletingId = null;

    public function mount(): void
    {
        Gate::authorize('manage-master-data');
    }

    public function create(): void
    {
        Gate::authorize('manage-master-data');
        $this->resetValidation();
        $this->reset('editingId', 'kode', 'nama');
        $this->open = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-master-data');
        $k = Model::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $k->id;
        $this->kode = $k->kode;
        $this->nama = $k->nama;
        $this->open = true;
    }

    public function save(SaveKodeArsip $action): void
    {
        Gate::authorize('manage-master-data');
        $this->validate(SaveKodeArsip::rules($this->editingId), SaveKodeArsip::messages());
        try {
            $k = $action->handle(['kode' => $this->kode, 'nama' => $this->nama], $this->editingId ? Model::findOrFail($this->editingId) : null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->open = false;
        $this->dispatch('toast', type: 'success', message: "Kode arsip {$k->kode} disimpan.");
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('manage-master-data');
        $this->deletingId = Model::findOrFail($id)->id;
        $this->deleteOpen = true;
    }

    public function delete(DeleteKodeArsip $action): void
    {
        Gate::authorize('manage-master-data');
        $k = Model::findOrFail($this->deletingId);
        $this->deleteOpen = false;
        try {
            $action->handle($k);
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: $e->errors()['delete'][0]);

            return;
        }
        $this->dispatch('toast', type: 'success', message: "Kode arsip {$k->kode} dihapus.");
    }

    public function render()
    {
        Gate::authorize('manage-master-data');

        return view('livewire.admin.kode-arsip', [
            'rows' => Model::withCount('spt')->orderBy('kode')->get(),
            'deleting' => $this->deletingId ? Model::find($this->deletingId) : null,
        ]);
    }
}
