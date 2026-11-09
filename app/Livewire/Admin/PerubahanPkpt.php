<?php

namespace App\Livewire\Admin;

use App\Actions\Pkpt\Perubahan\AjukanPerubahan;
use App\Actions\Pkpt\Perubahan\AturPengingatSk;
use App\Actions\Pkpt\Perubahan\BuangPerubahan;
use App\Actions\Pkpt\Perubahan\BuatPerubahan;
use App\Actions\Pkpt\Perubahan\HapusOperasi;
use App\Actions\Pkpt\Perubahan\SimpanOperasi;
use App\Actions\Pkpt\Perubahan\SimpanSkPerubahan;
use App\Enums\AksiPerubahan;
use App\Livewire\Concerns\MenampilkanOperasi;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\PkptPerubahanItem;
use App\Services\PerubahanPkpt as AturanPerubahan;
use App\Services\Settings;
use App\Support\Tanggal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Admin page of one Perubahan PKPT (Fase 9.2 Task 7): jenis/alasan, operasi (tambah/ubah/hapus item), validation strip from
 * PerubahanPkpt::cek(), SK block, Ajukan/Buang, pengingat SK toggle, riwayat. Every rule lives in the actions (they authorize through
 * PkptPerubahanPolicy); this component authorizes `update` (Admin) on mount and on every request, and scopes every lookup to the route's PKPT.
 */
class PerubahanPkpt extends Component
{
    use MenampilkanOperasi;
    use WithFileUploads;

    private const OPERASI = ['aksi', 'pkpt_item_id', 'irban_id', 'jenis_penugasan_id', 'tanggal_mulai', 'tanggal_selesai', 'keterangan', 'target_spt'];

    #[Locked]
    public int $pkptId;

    #[Locked]
    public int $perubahanId;

    public string $jenis = '';

    public string $alasan = '';

    /** Operasi modal; `opId` = operation being edited (null = new). */
    public bool $opOpen = false;

    #[Locked]
    public ?int $opId = null;

    public string $aksi = '';

    public ?int $pkpt_item_id = null;

    public string $irban_id = '';

    public string $jenis_penugasan_id = '';

    public string $tanggal_mulai = '';

    public string $tanggal_selesai = '';

    public string $keterangan = '';

    public string $target_spt = '';

    /** Confirm modals. */
    public bool $hapusOpen = false;

    #[Locked]
    public ?int $hapusOpId = null;

    public bool $buangOpen = false;

    public bool $pengingatOpen = false;

    public string $nomor_sk = '';

    public string $tanggal_sk = '';

    public ?TemporaryUploadedFile $berkas_sk = null;

    public function mount(Pkpt $pkpt, PkptPerubahan $perubahan): void
    {
        abort_unless($perubahan->pkpt_id === $pkpt->id, 404);
        Gate::authorize('update', $perubahan);
        $this->pkptId = $pkpt->id;
        $this->perubahanId = $perubahan->id;
        $this->isiForm($perubahan);
    }

    public function simpanForm(BuatPerubahan $aksi): void
    {
        if ($this->jalankan(['jenis', 'alasan'], fn (PkptPerubahan $p) => $aksi->perbarui(auth()->user(), $p, $this->only('jenis', 'alasan')))) {
            $this->dispatch('toast', type: 'success', message: 'Jenis dan alasan disimpan.');
        }
    }

    /** Opens the operasi modal: a new operation of `$aksi`, or an existing one (`$opId`) to edit. */
    public function bukaOperasi(string $aksi, ?int $opId = null): void
    {
        $p = $this->paket();
        $this->resetValidation();
        $this->reset(self::OPERASI);
        $this->opId = null;
        if ($opId !== null) {
            $op = $p->operasi()->findOrFail($opId);
            $this->opId = $op->id;
            $this->aksi = $op->aksi->value;
            $this->pkpt_item_id = $op->pkpt_item_id;
            $this->isiOperasi($op->aksi === AksiPerubahan::Tambah ? $op->data_baru : ($op->data_baru ?: $this->dataItem($op->pkpt_item_id)));
        } else {
            $this->aksi = (AksiPerubahan::tryFrom($aksi) ?? AksiPerubahan::Tambah)->value;
        }
        $this->opOpen = true;
    }

    /** Picking an item for `ubah` starts from its current values. */
    public function updatedPkptItemId(): void
    {
        $this->paket();
        if ($this->aksi === AksiPerubahan::Ubah->value && $this->pkpt_item_id) {
            $this->isiOperasi($this->dataItem($this->pkpt_item_id));
        }
    }

    public function simpanOperasi(SimpanOperasi $simpan): void
    {
        $ok = $this->jalankan([...self::OPERASI, 'operasi'], function (PkptPerubahan $p) use ($simpan) {
            $op = $this->opId ? $p->operasi()->findOrFail($this->opId) : null;
            $data = ['aksi' => $this->aksi, 'pkpt_item_id' => $this->pkpt_item_id];
            if ($this->aksi !== AksiPerubahan::Hapus->value) {
                $data += $this->only(AturanPerubahan::KOLOM);
            }

            return $simpan->handle(auth()->user(), $p, $op, $data);
        });
        if ($ok) {
            $this->opOpen = false;
            $this->dispatch('toast', type: 'success', message: 'Operasi disimpan.');
            $this->dispatch('fokus-ke', id: 'judul-operasi');
        }
    }

    public function konfirmasiHapusOperasi(int $opId): void
    {
        $this->hapusOpId = $this->paket()->operasi()->findOrFail($opId)->id;
        $this->hapusOpen = true;
    }

    public function hapusOperasi(HapusOperasi $hapus): void
    {
        $this->hapusOpen = false;
        if ($this->jalankan([], fn (PkptPerubahan $p) => $hapus->handle(auth()->user(), $p->operasi()->findOrFail((int) $this->hapusOpId)))) {
            $this->dispatch('toast', type: 'success', message: 'Operasi dihapus.');
            $this->dispatch('fokus-ke', id: 'judul-operasi');
        }
    }

    public function simpanSk(SimpanSkPerubahan $sk): void
    {
        $ok = $this->jalankan(['nomor_sk', 'tanggal_sk', 'berkas_sk'],
            fn (PkptPerubahan $p) => $sk->handle(auth()->user(), $p, $this->only('nomor_sk', 'tanggal_sk'), $this->berkas_sk));
        if ($ok) {
            $this->reset('berkas_sk');
            $this->dispatch('toast', type: 'success', message: 'SK disimpan.');
        }
    }

    /** Submits with the form's jenis/alasan (it may hold unsaved edits); both are saved only if the submit goes through. */
    public function ajukan(AjukanPerubahan $ajukan): void
    {
        $ok = $this->jalankan(['jenis', 'alasan'], fn (PkptPerubahan $p) => $ajukan->handle(auth()->user(), $p, $this->only('jenis', 'alasan')));
        if ($ok) {
            $this->dispatch('toast', type: 'success', message: 'Perubahan diajukan ke Pimpinan.');
            $this->dispatch('fokus-ke', id: 'judul-perubahan');
        }
    }

    public function buang(BuangPerubahan $buang): void
    {
        $this->buangOpen = false;
        if ($this->jalankan([], fn (PkptPerubahan $p) => $buang->handle(auth()->user(), $p))) {
            session()->flash('toast', ['type' => 'success', 'message' => 'Perubahan dibuang.']);
            $this->redirectRoute('admin.pkpt.show', ['pkpt' => $this->pkptId, 'tab' => 'perubahan'], navigate: true);
        }
    }

    public function aturPengingat(AturPengingatSk $atur): void
    {
        $this->pengingatOpen = false;
        $nyala = $this->paket()->pengingat_sk_dimatikan_pada !== null;
        if ($this->jalankan([], fn (PkptPerubahan $p) => $atur->handle(auth()->user(), $p, $nyala))) {
            $this->dispatch('toast', type: 'success', message: $nyala ? 'Pengingat SK dinyalakan.' : 'Pengingat SK dimatikan.');
            $this->dispatch('fokus-ke', id: 'judul-perubahan');
        }
    }

    /**
     * Runs an action on the fresh package. Errors of `$fields` stay inline (simpanOperasi adds `operasi`: "Tidak ada yang berubah" stays in its
     * modal); any other key (`perubahan`, `pkpt`: stale tab, status) closes the modals as a toast and moves focus to the h1, since the
     * trigger may be gone.
     */
    private function jalankan(array $fields, callable $fn): bool
    {
        $p = $this->paket();
        $this->resetValidation();
        try {
            $fn($p);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                if (in_array($key, $fields, true)) {
                    $this->addError($key, $messages[0]);
                } else {
                    $this->reset('opOpen', 'hapusOpen', 'buangOpen', 'pengingatOpen');
                    $this->dispatch('toast', type: 'error', message: $messages[0]);
                    $this->dispatch('fokus-ke', id: 'judul-perubahan');
                }
            }

            return false;
        }

        return true;
    }

    /** The package was discarded in another tab: any request (action or render) leads back to the PKPT with a toast, not a 404. */
    public function exception(Throwable $e, callable $stopPropagation): void
    {
        if ($e instanceof ModelNotFoundException && $e->getModel() === PkptPerubahan::class) {
            session()->flash('toast', ['type' => 'error', 'message' => BuangPerubahan::DIBUANG]);
            $this->keDaftar();
            $this->skipRender();
            $stopPropagation();
        }
    }

    private function keDaftar(): void
    {
        session()->flash('toast', ['type' => 'error', 'message' => BuangPerubahan::DIBUANG]);
        $this->redirectRoute('admin.pkpt.show', ['pkpt' => $this->pkptId, 'tab' => 'perubahan'], navigate: true);
    }

    /** The package of this page, re-read and re-authorized on every request (the update endpoint skips route middleware). */
    private function paket(): PkptPerubahan
    {
        $p = PkptPerubahan::where('pkpt_id', $this->pkptId)->findOrFail($this->perubahanId);
        Gate::authorize('update', $p);

        return $p;
    }

    private function isiForm(PkptPerubahan $p): void
    {
        $this->jenis = $p->jenis->value;
        $this->alasan = $p->alasan;
        $this->nomor_sk = (string) $p->nomor_sk;
        $this->tanggal_sk = (string) $p->tanggal_sk?->format('Y-m-d');
    }

    private function dataItem(?int $id): array
    {
        $item = PkptItem::where('pkpt_id', $this->pkptId)->find((int) $id);

        return $item ? AturanPerubahan::data($item) : [];
    }

    private function isiOperasi(array $d): void
    {
        foreach (AturanPerubahan::KOLOM as $k) {
            $this->{$k} = (string) ($d[$k] ?? '');
        }
    }

    public function render(AturanPerubahan $aturan)
    {
        try {
            $p = $this->paket();
        } catch (ModelNotFoundException) {
            // Caught here, not in exception(): a render stopped by that hook leaves Livewire without a view.
            $this->keDaftar();

            return '<div></div>';
        }
        $p->load(['pkpt', 'pemutus:id,name', 'operasi.item', 'riwayat' => fn ($q) => $q->with('pengguna:id,name')->orderBy('created_at')->orderBy('id')]);
        $bisaUbah = $p->bisaDiubah();
        $irban = Irban::orderBy('id')->pluck('nama', 'id')->all();
        $jenis = JenisPenugasan::orderBy('nama')->get(['id', 'nama', 'warna'])->keyBy('id');

        $diPaket = $p->operasi->pluck('pkpt_item_id')->filter()->reject(fn ($id) => $id === $this->opItemSaatIni($p))->all();
        $items = $bisaUbah ? PkptItem::where('pkpt_id', $this->pkptId)->aktif()->whereNotIn('id', $diPaket)
            ->orderBy('tanggal_mulai')->orderBy('id')->get() : collect();
        $itemOptions = $items->mapWithKeys(fn (PkptItem $i) => [$i->id => [
            'label' => ($jenis[$i->jenis_penugasan_id]->nama ?? '').' · '.($irban[$i->irban_id] ?? '').' · '.Tanggal::rentang($i->tanggal_mulai, $i->tanggal_selesai),
            'warna' => $jenis[$i->jenis_penugasan_id]->warna ?? null,
        ]])->all();

        // The client-set item id counts only when it is an aktif item of this PKPT; a foreign id behaves like "no item".
        $pilihan = $this->pkpt_item_id ? PkptItem::where('pkpt_id', $this->pkptId)->aktif()->whereKey($this->pkpt_item_id)->value('id') : null;
        $spt = $aturan->punyaSpt(array_values(array_unique(array_filter([...$p->operasi->pluck('pkpt_item_id')->all(), $pilihan]))));
        $judul = 'Perubahan PKPT '.$p->pkpt->tahun.' · '.($p->nomor_urut ? "Perubahan ke-{$p->nomor_urut}" : 'Belum bernomor');

        return view('livewire.admin.perubahan-pkpt', [
            'p' => $p,
            'pkpt' => $p->pkpt,
            'bisaUbah' => $bisaUbah,
            'bisaBuang' => $p->bisaDibuang(),
            'cek' => $p->status->terbuka() ? $aturan->cek($p) : [],
            'operasi' => $p->operasi->map(fn (PkptPerubahanItem $op) => $this->tampilOperasi($op, $irban, $jenis)),
            'itemOptions' => $itemOptions,
            'punyaSpt' => $spt,
            'pilihanPunyaSpt' => $pilihan ? ($spt[$pilihan] ?? false) : false,
            'judul' => $judul,
            'irbans' => $irban,
            'jenisOptions' => $jenis->map->nama->all(),
            'maksMb' => (int) app(Settings::class)->get('upload.maks_mb'),
        ])->title($judul);
    }

    /** The item of the operation being edited stays selectable in its own combobox. */
    private function opItemSaatIni(PkptPerubahan $p): ?int
    {
        return $this->opId ? $p->operasi->firstWhere('id', $this->opId)?->pkpt_item_id : null;
    }
}
