<?php

namespace App\Livewire\Admin;

use App\Services\Settings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Pengaturan Sistem')]
class Pengaturan extends Component
{
    /** Nested form state: v.penomoran.format, v.kendala.kategori, ... (dot keys become nested arrays). */
    public array $v = [];

    /** Stable row ids parallel to v.kendala.kategori, so wire:key survives removals. */
    public array $kategoriIds = [];

    public function mount(Settings $settings): void
    {
        Gate::authorize('manage-master-data');
        $this->v = $settings->all();
        $this->kategoriIds = array_map(fn () => (string) Str::uuid(), $this->v['kendala']['kategori']);
    }

    public function save(string $group, Settings $settings): void
    {
        Gate::authorize('manage-master-data');
        abort_unless(isset(Settings::GROUPS[$group]), 404);

        $this->v = Settings::sanitize($group, $this->v);
        $this->validate(Settings::groupRules($group));
        $settings->saveGroup($group, $this->v);

        $this->dispatch('toast', type: 'success', message: 'Pengaturan disimpan.');
    }

    public function addKategori(): void
    {
        Gate::authorize('manage-master-data');
        $this->v['kendala']['kategori'][] = '';
        $this->kategoriIds[] = (string) Str::uuid();
    }

    public function removeKategori(int $index): void
    {
        Gate::authorize('manage-master-data');
        $list = $this->v['kendala']['kategori'];
        // "Lainnya" must stay and at least one category must remain.
        if (count($list) <= 1 || trim($list[$index] ?? 'Lainnya') === 'Lainnya') {
            return;
        }
        unset($list[$index], $this->kategoriIds[$index]);
        $this->v['kendala']['kategori'] = array_values($list);
        $this->kategoriIds = array_values($this->kategoriIds);
    }

    protected function validationAttributes(): array
    {
        $labels = [
            'penomoran.format' => 'Format nomor',
            'penomoran.scope' => 'Cakupan penomoran', 'penugasan.hitungan_hari' => 'Hitungan hari',
            'penanda.batas_terlambat_hari' => 'Batas terlambat', 'rekap.dasar_triwulan' => 'Dasar triwulan',
            'upload.maks_mb' => 'Ukuran maksimum unggahan', 'kendala.kategori' => 'Kategori kendala',
        ];
        $attributes = ['v.kendala.kategori.*' => 'Nama kategori'];
        foreach ($labels as $key => $label) {
            $attributes["v.$key"] = $label;
        }

        return $attributes;
    }

    public function render()
    {
        Gate::authorize('manage-master-data');

        return view('livewire.admin.pengaturan');
    }
}
