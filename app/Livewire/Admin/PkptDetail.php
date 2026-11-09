<?php

namespace App\Livewire\Admin;

use App\Actions\Pkpt\DeletePkptItem;
use App\Actions\Pkpt\Perubahan\BuatPerubahan;
use App\Actions\Pkpt\SahkanPkpt;
use App\Actions\Pkpt\SavePkptItem;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Services\KesesuaianPkpt;
use App\Services\PerubahanPkpt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class PkptDetail extends Component
{
    private const KOSONG = ['irban_id' => '', 'jenis_penugasan_id' => '', 'tanggal_mulai' => '', 'tanggal_selesai' => '', 'keterangan' => '', 'target_spt' => ''];

    public Pkpt $pkpt;

    #[Url(as: 'irban')]
    public string $irbanId = '';

    /** Inline add row. */
    public array $baru = self::KOSONG;

    /** Inline edit row (one at a time). */
    public ?int $ubahId = null;

    public array $ubah = self::KOSONG;

    public bool $deleteOpen = false;

    public ?int $deletingId = null;

    public bool $sahkanOpen = false;

    /** Disahkan only: `item` | `perubahan` (anything else renders as `item`). */
    #[Url]
    public string $tab = 'item';

    /** Tab Item: also list items removed by an approved Perubahan PKPT. */
    #[Url]
    public bool $dihapus = false;

    /** Filter "Target kosong": only items without target_spt (Task 6 1.1.4). */
    #[Url(as: 'target-kosong')]
    public bool $targetKosong = false;

    /** Modal "Buat Perubahan". */
    public bool $buatOpen = false;

    /** Fields of "Buat Perubahan" (jenis, alasan); an array so `$jenis` stays the view's jenis options. */
    public array $buat = ['jenis' => 'perubahan', 'alasan' => ''];

    public function mount(Pkpt $pkpt): void
    {
        Gate::authorize('manage-master-data');
        $this->pkpt = $pkpt;
        $this->baru['irban_id'] = $this->irbanId;
    }

    public function updatedIrbanId(): void
    {
        $this->baru['irban_id'] = $this->irbanId;
    }

    public function tambah(SavePkptItem $action): void
    {
        Gate::authorize('manage-master-data');
        if ($this->write('baru', fn () => $action->handle($this->pkpt, null, $this->baru))) {
            // Keep irban + jenis for the next row: items are usually entered in runs.
            $this->baru = array_merge(self::KOSONG, array_intersect_key($this->baru, ['irban_id' => 1, 'jenis_penugasan_id' => 1]));
            $this->dispatch('toast', type: 'success', message: 'Item ditambahkan.');
        }
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-master-data');
        $item = $this->item($id);
        $this->resetValidation();
        $this->ubahId = $item->id;
        $this->ubah = [
            'irban_id' => (string) $item->irban_id, 'jenis_penugasan_id' => (string) $item->jenis_penugasan_id,
            'tanggal_mulai' => $item->tanggal_mulai->toDateString(), 'tanggal_selesai' => $item->tanggal_selesai->toDateString(),
            'keterangan' => (string) $item->keterangan, 'target_spt' => (string) $item->target_spt,
        ];
    }

    public function simpan(SavePkptItem $action): void
    {
        Gate::authorize('manage-master-data');
        $item = $this->item((int) $this->ubahId);
        if ($this->write('ubah', fn () => $action->handle($this->pkpt, $item, $this->ubah))) {
            $this->batal();
            $this->dispatch('toast', type: 'success', message: 'Item disimpan.');
        }
    }

    public function batal(): void
    {
        $this->resetValidation();
        $this->ubahId = null;
        $this->ubah = self::KOSONG;
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('manage-master-data');
        $this->deletingId = $this->item($id)->id;
        $this->deleteOpen = true;
    }

    public function delete(DeletePkptItem $action): void
    {
        Gate::authorize('manage-master-data');
        $item = $this->item((int) $this->deletingId);
        $this->deleteOpen = false;
        if ($this->write(null, fn () => $action->handle($item))) {
            if ($this->ubahId === $item->id) {
                $this->batal();
            }
            $this->dispatch('toast', type: 'success', message: 'Item dihapus.');
        }
    }

    public function sahkan(SahkanPkpt $action): void
    {
        Gate::authorize('manage-master-data');
        $this->sahkanOpen = false;
        if ($this->write(null, fn () => $action->handle($this->pkpt, auth()->user()))) {
            $this->batal();
            $this->dispatch('toast', type: 'success', message: "PKPT {$this->pkpt->tahun} disahkan.");
        }
    }

    /** Opens a Perubahan PKPT (BuatPerubahan holds every rule) and goes to its page. */
    public function buatPerubahan(BuatPerubahan $action): void
    {
        Gate::authorize('manage-master-data');
        $this->resetValidation();
        try {
            $p = $action->handle(auth()->user(), $this->pkpt, array_intersect_key($this->buat, ['jenis' => 1, 'alasan' => 1]));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                if (in_array($key, ['jenis', 'alasan'], true)) {
                    $this->addError("buat.{$key}", $messages[0]);
                } else {
                    $this->buatOpen = false;
                    $this->dispatch('toast', type: 'error', message: $messages[0]);
                    $this->dispatch('fokus-ke', id: 'judul-pkpt');
                }
            }

            return;
        }
        $this->redirectRoute('admin.pkpt.perubahan', ['pkpt' => $this->pkpt->id, 'perubahan' => $p->id], navigate: true);
    }

    /** Runs an action; field errors go under "$prefix.field", anything else (status, SK, items) becomes an error toast. */
    private function write(?string $prefix, callable $fn): bool
    {
        $this->resetValidation();
        try {
            $fn();
        } catch (ValidationException $e) {
            $fields = array_keys(self::KOSONG);
            foreach ($e->errors() as $key => $messages) {
                if ($prefix && in_array($key, $fields, true)) {
                    $this->addError("{$prefix}.{$key}", $messages[0]);
                } else {
                    $this->dispatch('toast', type: 'error', message: $messages[0]);
                }
            }

            return false;
        } finally {
            $this->pkpt->refresh();
        }

        return true;
    }

    private function item(int $id): PkptItem
    {
        return $this->pkpt->items()->findOrFail($id);
    }

    public function render(KesesuaianPkpt $kesesuaian, PerubahanPkpt $perubahan)
    {
        Gate::authorize('manage-master-data');
        $draf = $this->pkpt->isDraf();
        $tab = ! $draf && $this->tab === 'perubahan' ? 'perubahan' : 'item';
        $items = $this->pkpt->items()->when($draf || ! $this->dihapus, fn ($q) => $q->aktif())
            ->with(['irban', 'jenisPenugasan'])
            ->when($this->irbanId !== '', fn ($q) => $q->where('irban_id', $this->irbanId))
            ->when($this->targetKosong, fn ($q) => $q->tanpaTarget())
            ->orderBy('tanggal_mulai')->orderBy('id')->get();

        $rentang = $items->mapWithKeys(fn (PkptItem $i) => [$i->id => $kesesuaian->rentang($i)]);

        return view('livewire.admin.pkpt-detail', [
            'tabAktif' => $tab,
            'paket' => $tab === 'perubahan' ? PkptPerubahan::where('pkpt_id', $this->pkpt->id)->withCount('operasi')
                ->orderByRaw('nomor_urut is null desc')->orderByDesc('nomor_urut')->orderByDesc('id')->get() : collect(),
            'terbuka' => $draf ? null : $perubahan->terbuka($this->pkpt),
            'items' => $items,
            'rentang' => $rentang,
            'riwayat' => $draf ? [] : $perubahan->riwayatItemUntuk(auth()->user(), $items->pluck('id')->all()),
            'total' => $this->pkpt->items()->aktif()->count(),
            'tanpaTarget' => $this->pkpt->jumlahTanpaTarget(),
            'irbans' => Irban::orderBy('id')->pluck('nama', 'id')->all(),
            'jenis' => JenisPenugasan::orderBy('nama')->pluck('nama', 'id')->all(),
            'deleting' => $this->deletingId ? PkptItem::with(['irban', 'jenisPenugasan'])->find($this->deletingId) : null,
        ])->title("PKPT {$this->pkpt->tahun}");
    }
}
