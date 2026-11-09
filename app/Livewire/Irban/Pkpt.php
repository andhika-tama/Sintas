<?php

namespace App\Livewire\Irban;

use App\Actions\PerubahanPenugasan\AjukanPerubahanPenugasan;
use App\Enums\Role;
use App\Enums\StatusPkpt;
use App\Models\PerubahanPenugasan;
use App\Models\Pkpt as PkptModel;
use App\Models\PkptItem;
use App\Models\User;
use App\Services\KalenderPkpt;
use App\Services\KesesuaianPkpt;
use App\Services\PerubahanPkpt;
use App\Services\WilayahPengawasan;
use App\Support\DiinputOleh;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Irban "Jadwal PKPT": the own irban's active items of a Disahkan PKPT (calendar or table, always the original PKPT dates, K12), riwayat per
 * item, the "Ajukan Perubahan Penugasan" modal (1.2 K5/K7) and the "Perubahan Penugasan Saya" list. Rules live in AjukanPerubahanPenugasan;
 * every item id goes through its irban-scoped itemSendiri() before the action, so a foreign id is a 404 like a missing one.
 */
#[Title('Jadwal PKPT')]
class Pkpt extends Component
{
    private const FIELDS = ['tanggal_mulai', 'tanggal_selesai', 'alasan', 'diinput_oleh'];

    private const TAMPILAN = ['kalender', 'tabel', 'perubahan'];

    #[Url]
    public string $tahun = '';

    /** kalender | tabel | perubahan; anything else falls back to kalender. */
    #[Url]
    public string $tampilan = 'kalender';

    public bool $perubahanOpen = false;

    /** Item of the open modal. */
    #[Locked]
    public ?int $perubahanItemId = null;

    public string $tanggal_mulai = '';

    public string $tanggal_selesai = '';

    public string $alasan = '';

    public ?int $diinput_oleh = null;

    /** Diinput oleh shows the combobox instead of the prefilled chip. */
    public bool $ganti = false;

    public function mount(): void
    {
        $this->irban();
        $this->tahun = $this->tahun !== '' ? $this->tahun : (string) now('Asia/Jakarta')->year;
    }

    /** Opens the modal for an own active item (404 for a foreign or missing id), prefilled with the PKPT dates. */
    public function bukaPerubahan(int $id): void
    {
        $user = $this->irban();
        $this->resetValidation();
        $this->reset('alasan');
        if (! $item = $this->item($id)) {
            return;
        }
        $this->perubahanItemId = $item->id;
        $this->tanggal_mulai = $item->tanggal_mulai->toDateString();
        $this->tanggal_selesai = $item->tanggal_selesai->toDateString();
        $this->diinput_oleh = DiinputOleh::terakhir($user);
        $this->ganti = false;
        $this->perubahanOpen = true;
    }

    public function gantiPenginput(): void
    {
        $this->irban();
        $this->ganti = true;
    }

    public function kirimPerubahan(AjukanPerubahanPenugasan $action): void
    {
        $user = $this->irban();
        if (! $this->perubahanItemId || ! $item = $this->item($this->perubahanItemId)) {
            return;
        }
        $this->resetValidation();
        try {
            $action->handle($user, $item, $this->only(self::FIELDS));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                if (in_array($key, self::FIELDS, true)) {
                    $this->addError($key, $messages[0]);
                } else {
                    // `item` / `pkpt` have no field in the modal (pitfall "Galat non-kolom"): close it and show a toast.
                    $this->perubahanOpen = false;
                    $this->dispatch('toast', type: 'error', message: $messages[0]);
                    $this->dispatch('perubahan-fokus', id: null);
                }
            }

            return;
        }
        $this->perubahanOpen = false;
        $this->dispatch('perubahan-fokus', id: $item->id);
        $this->dispatch('toast', type: 'success', message: 'Perubahan Penugasan terkirim ke Pimpinan.');
    }

    /** Route middleware does not run on Livewire update requests, so every entry point checks the role. */
    private function irban(): User
    {
        $user = auth()->user();
        abort_unless($user?->role === Role::Irban, 403);

        return $user;
    }

    /** An active item of this irban in a Disahkan PKPT; null after a stale-tab toast (item removed or moved since); a foreign id is a 404. */
    private function item(int $id): ?PkptItem
    {
        try {
            return AjukanPerubahanPenugasan::itemSendiri($this->irban(), $id);
        } catch (ValidationException $e) {
            $this->perubahanOpen = false;
            $this->dispatch('toast', type: 'error', message: collect($e->errors())->flatten()->first());
            $this->dispatch('perubahan-fokus', id: null);

            return null;
        }
    }

    public function render(KesesuaianPkpt $kesesuaian, KalenderPkpt $kalender, WilayahPengawasan $wilayah, PerubahanPkpt $perubahan)
    {
        $user = $this->irban();
        // Old UsulanPerubahanDiputuskan notifications (removed in 1.2 Task 8) link to `tampilan=usulan`.
        if ($this->tampilan === 'usulan') {
            $this->tampilan = 'perubahan';
        }
        if (! in_array($this->tampilan, self::TAMPILAN, true)) {
            $this->tampilan = 'kalender';
        }
        $pkpt = PkptModel::where('tahun', (int) $this->tahun)->first();
        $disahkan = $pkpt?->status === StatusPkpt::Disahkan;
        // Jenis and PKPT dates feed the table, the cards and the open modal; the calendar gets them from KalenderPkpt.
        $perluBaris = $this->tampilan === 'tabel' || $this->perubahanOpen;
        $items = $disahkan
            ? $pkpt->items()->aktif()->where('irban_id', $user->irban_id)->when($perluBaris, fn ($q) => $q->with('jenisPenugasan'))
                ->orderBy('tanggal_mulai')->orderBy('id')->get()
            : collect();
        $rentang = $perluBaris ? $items->mapWithKeys(fn (PkptItem $i) => [$i->id => $kesesuaian->rentang($i)]) : collect();
        $tahunList = PkptModel::pluck('tahun')->push(now('Asia/Jakarta')->year)
            ->unique()->sortDesc()->mapWithKeys(fn ($t) => [$t => $t])->all();

        $pakaiKalender = $this->tampilan === 'kalender' && $items->isNotEmpty();
        $daftar = $disahkan && $this->tampilan === 'perubahan'
            ? PerubahanPenugasan::where('irban_id', $user->irban_id)->whereHas('item', fn ($q) => $q->where('pkpt_id', $pkpt->id))
                ->with('item.jenisPenugasan')->latest()->orderByDesc('id')->get()
            : collect();

        return view('livewire.irban.pkpt', [
            'kalender' => $pakaiKalender ? $kalender->untuk((int) $this->tahun, $user->irban_id) : [],
            'wilayah' => $pakaiKalender ? $wilayah->untuk((int) $this->tahun, $user->irban_id)[0] ?? null : null,
            'pkpt' => $pkpt,
            'items' => $items,
            'rentang' => $rentang,
            'riwayat' => $this->tampilan === 'perubahan' ? [] : $perubahan->riwayatItemUntuk($user, $items->pluck('id')->all()),
            'daftar' => $daftar,
            'tahunList' => $tahunList,
            'irbanNama' => $user->irban?->nama ?? 'irban ini',
            'aktif' => $this->perubahanOpen && $this->perubahanItemId ? $items->firstWhere('id', $this->perubahanItemId) : null,
            'penginput' => $this->perubahanOpen ? DiinputOleh::pilihan($user) : collect(),
        ]);
    }
}
