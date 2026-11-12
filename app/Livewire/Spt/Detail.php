<?php

namespace App\Livewire\Spt;

use App\Actions\Spt\BatalkanNomor;
use App\Actions\Spt\Concerns\IzinPelaksanaan;
use App\Actions\Spt\SimpanKendala;
use App\Actions\Spt\TandaiSelesai;
use App\Actions\Spt\UbahNomorSppd;
use App\Actions\Spt\UbahTanggalRealisasi;
use App\Actions\Spt\UnggahDokumenSpt;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Models\Aktivitas;
use App\Models\Spt;
use App\Services\KesesuaianPkpt;
use App\Services\PenandaSpt;
use App\Services\Settings;
use App\Support\DiinputOleh;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Shared SPT detail page (_shared.md §7), any role allowed by SptPolicy::view, with the four Irban action modals
 * (Ubah Tanggal, Unggah SPT Final, Tandai Selesai, Kendala) plus Batalkan Penugasan for the owning Irban (versi 1.2.1 Task 4).
 * The component only collects input and calls the actions; the actions authorize (`laksanakan`, `batalkan`) and hold every rule
 * (the UI reads TandaiSelesai::syarat()/bisa() and BatalkanNomor::cegah()).
 * The Nomor SPPD panel is editable for `ubahSppd` (Bagian Umum) through UbahNomorSppd, read-only for everyone else.
 */
class Detail extends Component
{
    use IzinPelaksanaan;
    use WithFileUploads;

    /** modal => fields that can show an inline error in it; any other error key becomes a toast. */
    private const FIELDS = [
        'tanggal' => ['tanggal_mulai', 'tanggal_selesai', 'alasan', 'diinput_oleh'],
        'final' => ['berkas', 'diinput_oleh'],
        'selesai' => ['berkas', 'nomor_lhp', 'tanggal_lhp', 'konfirmasi', 'diinput_oleh'],
        'kendala' => ['kategori', 'uraian', 'diinput_oleh'],
        'batal' => ['alasan', 'diinput_oleh'],
    ];

    #[Locked]
    public int $sptId;

    /** The open modal ('' = none). Only its content is rendered, so field ids stay unique across the four modals. */
    public string $modal = '';

    public string $tanggal_mulai = '';

    public string $tanggal_selesai = '';

    public string $alasan = '';

    public ?TemporaryUploadedFile $berkas = null;

    public string $nomor_lhp = '';

    public string $tanggal_lhp = '';

    public bool $konfirmasi = false;

    public string $kategori = '';

    public string $uraian = '';

    public ?int $diinput_oleh = null;

    /** Diinput oleh shows the combobox instead of the prefilled chip. */
    public bool $ganti = false;

    /** Nomor SPPD panel input (Bagian Umum). */
    public string $nomor_sppd = '';

    public function mount(Spt $spt): void
    {
        Gate::authorize('view', $spt);
        $this->sptId = $spt->id;
        $this->nomor_sppd = (string) $spt->nomor_sppd;
    }

    public function simpanSppd(UbahNomorSppd $aksi): void
    {
        $spt = $this->sptDasar();
        Gate::authorize('kelolaNomor', Spt::class); // other roles 403; a Dibatalkan SPT reaches the action and becomes a toast
        $this->resetValidation();
        try {
            $spt = $aksi->handle(auth()->user(), $spt, $this->nomor_sppd);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $key === 'nomor_sppd' ? $this->addError($key, $messages[0]) : $this->galatUmum($messages[0]);
            }

            return;
        }
        $this->nomor_sppd = (string) $spt->nomor_sppd;
        $this->dispatch('toast', type: 'success', message: $spt->nomor_sppd === null ? 'Nomor SPPD dihapus' : 'Nomor SPPD disimpan');
    }

    public function buka(string $modal, BatalkanNomor $batal): void
    {
        $spt = $this->sptDasar();
        if ($modal === 'batal') {
            Gate::authorize('batalkan', $spt);
            if ($pesan = $batal->cegah($spt)) {
                $this->galatUmum($pesan);

                return;
            }
        } else {
            try {
                $this->izinkan(auth()->user(), $spt); // owning Irban on a Selesai SPT gets the toast, others 403
            } catch (ValidationException $e) {
                $this->galatUmum($e->errors()['spt'][0]);

                return;
            }
        }
        if (! isset(self::FIELDS[$modal])) {
            return;
        }
        $this->resetValidation();
        $this->reset('alasan', 'berkas', 'nomor_lhp', 'tanggal_lhp', 'konfirmasi', 'ganti');
        $this->tanggal_mulai = (string) $spt->tanggal_mulai?->toDateString();
        $this->tanggal_selesai = (string) $spt->tanggal_selesai?->toDateString();
        $kendala = $spt->kendala()->first();
        $this->kategori = (string) $kendala?->kategori;
        $this->uraian = (string) $kendala?->uraian;
        $this->diinput_oleh = DiinputOleh::terakhir(auth()->user());
        $this->modal = $modal;
        $this->dispatch('open-modal', name: "aksi-{$modal}");
    }

    public function gantiPenginput(): void
    {
        Gate::authorize($this->modal === 'batal' ? 'batalkan' : 'laksanakan', $this->sptDasar());
        $this->ganti = true;
    }

    public function hapusBerkas(): void
    {
        Gate::authorize('laksanakan', $this->sptDasar());
        $this->berkas = null;
    }

    public function simpanTanggal(UbahTanggalRealisasi $aksi): void
    {
        $this->jalankan('tanggal', 'Tanggal realisasi diperbarui', fn (Spt $spt) => $aksi->handle(auth()->user(), $spt, $this->only(self::FIELDS['tanggal'])));
    }

    public function unggahFinal(UnggahDokumenSpt $aksi): void
    {
        $this->jalankan('final', 'SPT final diunggah', fn (Spt $spt) => $aksi->handle(auth()->user(), $spt, $this->only('diinput_oleh'), $this->berkas));
    }

    public function tandaiSelesai(TandaiSelesai $aksi): void
    {
        // The action buttons vanish once Selesai, so focus goes to the h1.
        $this->jalankan('selesai', 'SPT ditandai Selesai', fn (Spt $spt) => $aksi->handle(
            auth()->user(), $spt, $this->only('diinput_oleh', 'konfirmasi', 'nomor_lhp', 'tanggal_lhp'), $this->berkas,
        ), 'judul-spt');
    }

    public function simpanKendala(SimpanKendala $aksi): void
    {
        // The perlu-kendala alert (and its button) disappears once a kendala exists, so focus goes to the header button.
        $this->jalankan('kendala', 'Kendala disimpan', fn (Spt $spt) => $aksi->handle(auth()->user(), $spt, $this->only(self::FIELDS['kendala'])), 'aksi-kendala');
    }

    public function batalkan(BatalkanNomor $aksi): void
    {
        // The status becomes Dibatalkan and every action button disappears, so focus goes to the h1.
        $this->jalankan('batal', 'Penugasan dibatalkan', fn (Spt $spt) => $aksi->handle(auth()->user(), $spt, $this->alasan, $this->diinput_oleh), 'judul-spt');
    }

    /**
     * Runs an action (it authorizes `laksanakan` or `batalkan`): errors of the open modal's fields stay inline, any other key (`spt`) closes it as a toast.
     * `$fokus` = id of the element to focus after success when the trigger may be gone. The actions call DiinputOleh::ingat() themselves.
     */
    private function jalankan(string $modal, string $pesan, callable $fn, ?string $fokus = null): void
    {
        $spt = $this->sptDasar();
        $this->resetValidation();
        try {
            $fn($spt);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                if (in_array($key, self::FIELDS[$modal], true)) {
                    $this->addError($key, $messages[0]);
                } else {
                    $this->galatUmum($messages[0]);
                }
            }

            return;
        }
        $this->tutup();
        $this->reset('berkas', 'ganti');
        $this->dispatch('toast', type: 'success', message: $pesan);
        if ($fokus) {
            $this->dispatch('fokus-ke', id: $fokus);
        }
    }

    /** Non-field error: close the modal, toast, and focus the h1 since a re-render may remove the action buttons. */
    private function galatUmum(string $pesan): void
    {
        $this->tutup();
        $this->dispatch('toast', type: 'error', message: $pesan);
        $this->dispatch('fokus-ke', id: 'judul-spt');
    }

    private function tutup(): void
    {
        if ($this->modal !== '') {
            $this->dispatch('close-modal', name: "aksi-{$this->modal}");
        }
        $this->modal = '';
    }

    /** Lookup for the write methods: `view` here, `laksanakan` in the action (which first turns "already Selesai" into a toastable `spt` error). */
    private function sptDasar(): Spt
    {
        $spt = Spt::findOrFail($this->sptId);
        Gate::authorize('view', $spt);

        return $spt;
    }

    private function spt(): Spt
    {
        $spt = Spt::with([
            'irban', 'klasifikasi', 'kodeArsip', 'jenisPenugasan', 'pkptItem.pkpt', 'pkptItem.jenisPenugasan', 'ppj', 'objek.parent', 'personel.pegawai',
            'dokumen' => fn ($q) => $q->with(['diunggahOleh', 'diinputOleh'])->orderBy('created_at')->orderBy('id'),
            'riwayat' => fn ($q) => $q->with(['olehUser', 'diinputOleh'])->orderBy('created_at')->orderBy('id'),
            'kendala.dibuatOleh', 'kendala.diinputOleh',
        ])->findOrFail($this->sptId);
        Gate::authorize('view', $spt);

        return $spt;
    }

    public function render(PenandaSpt $penanda, KesesuaianPkpt $kesesuaian, Settings $settings, TandaiSelesai $selesai, BatalkanNomor $batal)
    {
        $spt = $this->spt();
        $item = $spt->pkptItem;
        $user = auth()->user();
        $laksanakan = $user->can('laksanakan', $spt);
        $ubahSppd = $user->can('ubahSppd', $spt);
        $tahunSppd = $spt->tahun ?? now('Asia/Jakarta')->year;
        // Batalkan Penugasan: the owning Irban (Bagian Umum cancels from the Register); shown while Diterima, disabled with cegah()'s reason.
        $tombolBatal = $user->role === Role::Irban && $user->can('batalkan', $spt) && $spt->status_pengajuan === StatusPengajuan::Diterima;
        // The batal modal stays open after a Selesai in another tab, so its save still reaches the action and becomes a toast.
        $modal = ($this->modal === 'batal' ? $tombolBatal : $laksanakan) ? $this->modal : '';

        return view('livewire.spt.detail', [
            'spt' => $spt,
            'penanda' => $penanda->untuk($spt),
            'perluKendala' => $penanda->perluKendala($spt),
            'rentang' => $item ? $kesesuaian->rentang($item) : null,
            'sesuai' => $item && $spt->tanggal_mulai && $spt->tanggal_selesai ? $kesesuaian->sesuai($item, $spt->tanggal_mulai, $spt->tanggal_selesai) : null,
            // 1.2 K6: why a SPT inside the PKPT range is still Tidak Sesuai.
            'perubahanPenugasan' => $item?->perubahanPenugasan,
            'hariKerja' => $settings->get('penugasan.hitungan_hari') === 'kerja',
            'laksanakan' => $laksanakan,
            'tombolBatal' => $tombolBatal,
            'blokirBatal' => $tombolBatal ? $batal->cegah($spt) : null,
            'bisaUbah' => auth()->user()->can('update', $spt),
            'ubahSppd' => $ubahSppd,
            'tahunSppd' => $tahunSppd,
            'duplikatSppd' => $ubahSppd ? app(UbahNomorSppd::class)->duplikat($tahunSppd, $this->nomor_sppd, $spt->id) : null,
            'aktif' => $modal,
            'penginput' => $modal !== '' ? DiinputOleh::pilihan(auth()->user()) : collect(),
            'syarat' => $modal === 'selesai' ? $selesai->syarat($spt) : null,
            'blokir' => $modal === 'selesai' ? $selesai->bisa($spt) : null,
            'maksMb' => (int) $settings->get('upload.maks_mb'),
            'kategoriKendala' => (array) $settings->get('kendala.kategori'),
            'log' => Gate::allows('lihat-log') ? Aktivitas::with('causer')->untukSpt($spt->id)->latest('id')->limit(20)->get() : null,
        ])->title($spt->nomor_lengkap ?? 'Detail SPT');
    }
}
