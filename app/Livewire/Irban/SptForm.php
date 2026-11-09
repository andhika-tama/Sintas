<?php

namespace App\Livewire\Irban;

use App\Actions\Spt\SimpanSpt;
use App\Actions\Spt\ValidatePersonel;
use App\Enums\KejadianSpt;
use App\Enums\PeranTim;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Models\IrbanTahunan;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\Tim;
use App\Models\User;
use App\Services\KesesuaianPkpt;
use App\Services\SaranUraian;
use App\Support\DiinputOleh;
use App\Support\Tanggal;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Irban "Ajukan Nomor SPT" / edit (irban.md "Submission form"). Holds form state only; saving, validation, warnings and the
 * uraian suggestion live in SimpanSpt / ValidatePersonel / SaranUraian. Personel rows: [pegawai_id, peran, sumber, tim_id, ikut].
 */
#[Title('Ajukan Nomor SPT')]
class SptForm extends Component
{
    use WithFileUploads;

    private const FIELDS = ['klasifikasi_id', 'kode_arsip_id', 'pkpt_item_id', 'non_pkpt', 'jenis_penugasan_id', 'jenis_penugasan_lainnya', 'objek_ids', 'objek_lainnya',
        'periode_diperiksa', 'uraian_untuk', 'tanggal_mulai', 'tanggal_selesai', 'ppj_pegawai_id', 'catatan_bagian_umum', 'diinput_oleh', 'personel'];

    /** Props whose change can change the uraian suggestion. */
    private const SARAN = ['klasifikasi_id', 'pkpt_item_id', 'non_pkpt', 'jenis_penugasan_id', 'jenis_penugasan_lainnya', 'objek_ids', 'objek_lainnya', 'periode_diperiksa'];

    /** Value of the "Lainnya" option in the jenis select: no master id, so SimpanSpt reads it as "no jenis" and uses jenis_penugasan_lainnya. */
    public const JENIS_LAINNYA = 0;

    #[Locked]
    public ?int $sptId = null;

    public ?int $klasifikasi_id = null;

    public ?int $kode_arsip_id = null;

    public ?int $pkpt_item_id = null;

    public bool $non_pkpt = false;

    public ?int $jenis_penugasan_id = null;

    public string $jenis_penugasan_lainnya = '';

    /** @var list<int> */
    public array $objek_ids = [];

    public string $objek_lainnya = '';

    public string $periode_diperiksa = '';

    public string $uraian_untuk = '';

    /** Last suggestion put into uraian; while uraian still equals it, the suggestion keeps following the form. */
    public string $saranTerpakai = '';

    public string $tanggal_mulai = '';

    public string $tanggal_selesai = '';

    public ?int $ppj_pegawai_id = null;

    public string $catatan_bagian_umum = '';

    public ?int $diinput_oleh = null;

    public bool $ganti = false;

    /** @var list<array{pegawai_id: int, peran: string, sumber: string, tim_id: ?int, ikut: bool}> */
    public array $personel = [];

    /** @var list<int> */
    public array $timDipilih = [];

    public ?int $tambahId = null;

    public ?TemporaryUploadedFile $lampiran = null;

    public bool $hapusLampiranLama = false;

    /** Live-region text for reorders ("Budi dipindah ke posisi 2"). */
    public string $pengumuman = '';

    /** "Draf tersimpan 10.42" caption (H.i, Asia/Jakarta). */
    public string $tersimpanPada = '';

    /** Read by the unsaved-changes guard. */
    public bool $berubah = false;

    public function mount(?Spt $spt = null)
    {
        $user = $this->irban();
        $this->diinput_oleh = DiinputOleh::terakhir($user);
        if (! $spt?->exists) {
            abort_unless($user->can('create', Spt::class), 403);
            $this->ppj_pegawai_id = IrbanTahunan::where('irban_id', $user->irban_id)->where('tahun', DiinputOleh::tahun())->value('ppj_pegawai_id');

            return;
        }

        abort_unless($user->can('view', $spt), 403);
        if (! $user->can('update', $spt)) {
            session()->flash('toast', ['type' => 'info', 'message' => 'SPT ini sudah dikirim dan tidak dapat diubah.']);

            return $this->redirectRoute('irban.spt', navigate: true);
        }
        $this->sptId = $spt->id;
        $this->fill([
            'klasifikasi_id' => $spt->klasifikasi_id, 'kode_arsip_id' => $spt->kode_arsip_id, 'pkpt_item_id' => $spt->pkpt_item_id, 'jenis_penugasan_id' => $spt->jenis_penugasan_id,
            'objek_ids' => $spt->objek()->pluck('objek.id')->all(), 'periode_diperiksa' => (string) $spt->periode_diperiksa,
            'uraian_untuk' => (string) $spt->uraian_untuk, 'tanggal_mulai' => (string) $spt->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => (string) $spt->tanggal_selesai?->toDateString(), 'ppj_pegawai_id' => $spt->ppj_pegawai_id,
            'catatan_bagian_umum' => (string) $spt->catatan_bagian_umum,
            'jenis_penugasan_lainnya' => (string) $spt->jenis_penugasan_lainnya, 'objek_lainnya' => (string) $spt->objek_lainnya,
        ]);
        if (! $spt->jenis_penugasan_id && $spt->jenis_penugasan_lainnya !== null) {
            $this->jenis_penugasan_id = self::JENIS_LAINNYA;
        }
        // Non-PKPT has no column: it is "no item" under a klasifikasi that does not require one (drafts included).
        $this->non_pkpt = ! $spt->pkpt_item_id && ! Klasifikasi::find($spt->klasifikasi_id)?->wajib_item_pkpt;
        $this->personel = $spt->personel->map(fn ($p) => ['pegawai_id' => $p->pegawai_id, 'peran' => $p->peran->value, 'sumber' => $p->sumber,
            'tim_id' => $p->tim_id, 'ikut' => true])->all();
        $this->timDipilih = array_values(array_unique(array_filter(array_column($this->personel, 'tim_id'))));
        $this->saranTerpakai = $this->saran() ?? '';
    }

    public function updated(string $prop): void
    {
        $this->irban();
        $this->berubah = true;
        if ($prop === 'klasifikasi_id' && $this->klasifikasi()?->wajib_item_pkpt) {
            $this->non_pkpt = false;
        }
        if ($prop === 'non_pkpt' && $this->non_pkpt) {
            $this->pkpt_item_id = null; // SimpanSpt reads "no item" as Non-PKPT.
        }
        if ($prop === 'pkpt_item_id' && $this->pkpt_item_id) {
            $this->jenis_penugasan_id = $this->item()?->jenis_penugasan_id;
            $this->jenis_penugasan_lainnya = $this->objek_lainnya = ''; // SimpanSpt drops them anyway once an item is linked
        }
        if (in_array(explode('.', $prop)[0], self::SARAN, true) && ($this->uraian_untuk === '' || $this->uraian_untuk === $this->saranTerpakai)) {
            $this->gunakanSaran();
        }
    }

    public function updatedTambahId(): void
    {
        $id = $this->tambahId;
        $this->tambahId = null;
        $p = $id ? Pegawai::where('aktif', true)->find($id) : null;
        if (! $p) {
            return;
        }
        if ($this->indeks($p->id) !== null) {
            $this->dispatch('toast', type: 'info', message: "{$p->nama_bergelar} sudah ada di Susunan Personel");

            return;
        }
        $this->personel[] = ['pegawai_id' => $p->id, 'peran' => PeranTim::Anggota->value, 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true];
    }

    public function gunakanSaran(): void
    {
        $this->irban();
        if (($saran = $this->saran()) !== null) {
            $this->uraian_untuk = $this->saranTerpakai = $saran;
        }
    }

    /** Check a team: append its members with default roles (deduped, info toast). Uncheck: drop the rows it added. */
    public function toggleTim(int $id): void
    {
        $user = $this->irban();
        $tim = Tim::with('anggota.pegawai')->where('irban_id', $user->irban_id)->where('tahun', DiinputOleh::tahun())->findOrFail($id);
        $this->berubah = true;
        if (in_array($tim->id, $this->timDipilih, true)) {
            $this->timDipilih = array_values(array_diff($this->timDipilih, [$tim->id]));
            $this->personel = array_values(array_filter($this->personel, fn ($r) => $r['tim_id'] !== $tim->id));

            return;
        }
        $this->timDipilih[] = $tim->id;
        $namaTim = Tim::whereIn('id', array_filter(array_column($this->personel, 'tim_id')))->pluck('nama', 'id');
        foreach ($tim->anggota as $a) {
            if (($i = $this->indeks($a->pegawai_id)) !== null) {
                $asal = $namaTim[$this->personel[$i]['tim_id']] ?? null;
                $this->dispatch('toast', type: 'info', message: $asal ? "{$a->pegawai->nama_bergelar} sudah ada dari {$asal}" : "{$a->pegawai->nama_bergelar} sudah ada di Susunan Personel");

                continue;
            }
            $this->personel[] = ['pegawai_id' => $a->pegawai_id, 'peran' => $a->peran_default->value, 'sumber' => 'tim', 'tim_id' => $tim->id, 'ikut' => true];
        }
    }

    /** Drag (wire:sort, 0-based position), buttons and Alt+↑/↓ all end here. */
    public function pindah(int $pegawaiId, int $posisi): void
    {
        $this->irban();
        $i = $this->indeks($pegawaiId);
        $posisi = max(0, min($posisi, count($this->personel) - 1));
        if ($i === null || $i === $posisi) {
            return;
        }
        $row = array_splice($this->personel, $i, 1);
        array_splice($this->personel, $posisi, 0, $row);
        $this->berubah = true;
        $this->pengumuman = Pegawai::find($pegawaiId)?->nama_bergelar.' dipindah ke posisi '.($posisi + 1);
        $this->dispatch('personel-dipindah', id: $pegawaiId);
    }

    public function naik(int $pegawaiId): void
    {
        $this->pindah($pegawaiId, ($this->indeks($pegawaiId) ?? 0) - 1);
    }

    public function turun(int $pegawaiId): void
    {
        $this->pindah($pegawaiId, ($this->indeks($pegawaiId) ?? 0) + 1);
    }

    public function hapus(int $pegawaiId): void
    {
        $this->irban();
        $this->personel = array_values(array_filter($this->personel, fn ($r) => $r['pegawai_id'] !== $pegawaiId));
        $this->berubah = true;
    }

    public function hapusLampiran(): void
    {
        $this->irban();
        $this->lampiran = null;
        $this->hapusLampiranLama = true;
        $this->berubah = true;
    }

    public function gantiPenginput(): void
    {
        $this->irban();
        $this->ganti = true;
    }

    public function simpan(SimpanSpt $action): void
    {
        $spt = $this->kirim($action, 'draf');
        if (! $spt) {
            return;
        }
        $baru = ! $this->sptId;
        $this->sptId = $spt->id;
        $this->tersimpanPada = now('Asia/Jakarta')->format('H.i');
        if ($baru) {
            $this->js('history.replaceState(history.state, "", '.json_encode(route('irban.spt.ubah', $spt)).')');
        }
        $this->dispatch('toast', type: 'success', message: 'Draf tersimpan');
    }

    public function ajukan(SimpanSpt $action)
    {
        if (! $this->kirim($action, 'ajukan')) {
            return null;
        }
        session()->flash('toast', ['type' => 'success', 'message' => 'Pengajuan terkirim ke Bagian Umum']);

        return $this->redirectRoute('irban.spt', navigate: true);
    }

    private function kirim(SimpanSpt $action, string $mode): ?Spt
    {
        $user = $this->irban();
        $spt = $this->sptId ? Spt::where('irban_id', $user->irban_id)->findOrFail($this->sptId) : null;
        $this->resetValidation();
        try {
            $spt = $action->handle($user, $spt, $this->only(self::FIELDS), $mode, $this->lampiran, $this->hapusLampiranLama && ! $this->lampiran);
        } catch (ValidationException $e) {
            if (isset($e->errors()['spt'])) {
                session()->flash('toast', ['type' => 'info', 'message' => $e->errors()['spt'][0]]);
                $this->redirectRoute('irban.spt', navigate: true);

                return null;
            }
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $m) {
                    $this->addError($key, $m);
                }
            }
            $this->dispatch('gagal-simpan');

            return null;
        }
        $this->lampiran = null;
        $this->hapusLampiranLama = false;
        $this->ganti = false;
        $this->berubah = false;
        $this->dispatch('tersimpan');

        return $spt;
    }

    /** Route middleware does not run on Livewire update requests, so every entry point checks the role. */
    private function irban(): User
    {
        $user = auth()->user();
        abort_unless($user?->role === Role::Irban && $user->irban_id, 403);

        return $user;
    }

    private function indeks(int $pegawaiId): ?int
    {
        $i = array_search($pegawaiId, array_column($this->personel, 'pegawai_id'), true);

        return $i === false ? null : $i;
    }

    private function klasifikasi(): ?Klasifikasi
    {
        return $this->klasifikasi_id ? Klasifikasi::find($this->klasifikasi_id) : null;
    }

    private function item(): ?PkptItem
    {
        return $this->pkpt_item_id ? PkptItem::aktif()->with(['jenisPenugasan', 'pkpt'])->where('irban_id', $this->irban()->irban_id)->find($this->pkpt_item_id) : null;
    }

    /** Same rule as SimpanSpt: Lainnya only without an item, under a klasifikasi that does not require one. */
    private function bolehLainnya(): bool
    {
        return SimpanSpt::bolehLainnya($this->klasifikasi(), (bool) $this->item());
    }

    private function saran(): ?string
    {
        $lainnya = $this->bolehLainnya();
        $jenis = $this->item()?->jenisPenugasan?->nama ?? ($this->jenis_penugasan_id ? JenisPenugasan::find($this->jenis_penugasan_id)?->nama : null)
            ?? ($lainnya && trim($this->jenis_penugasan_lainnya) !== '' ? trim($this->jenis_penugasan_lainnya) : null);
        if ($jenis === null) {
            return null;
        }
        $objek = Objek::whereIn('id', $this->objek_ids)->pluck('nama', 'id');
        $nama = collect($this->objek_ids)->map(fn ($id) => $objek[$id] ?? '')->all();
        if ($lainnya) {
            $nama[] = trim($this->objek_lainnya);
        }

        return app(SaranUraian::class)->saran($jenis, $nama, $this->periode_diperiksa);
    }

    public function render(SimpanSpt $action, ValidatePersonel $validatePersonel, KesesuaianPkpt $kesesuaian)
    {
        $user = $this->irban();
        $tahun = DiinputOleh::tahun();
        $spt = $this->sptId ? Spt::with('dokumen')->where('irban_id', $user->irban_id)->find($this->sptId) : null;
        $items = PkptItem::aktif()->with(['jenisPenugasan', 'pkpt'])->where('irban_id', $user->irban_id)
            ->whereHas('pkpt', fn ($q) => $q->where('tahun', $tahun))->orderBy('tanggal_mulai')->get();
        $rentang = $items->mapWithKeys(fn ($i) => [$i->id => Tanggal::rentang(...$kesesuaian->rentang($i))]);
        $item = $items->firstWhere('id', $this->pkpt_item_id);
        $aktif = Pegawai::where('aktif', true)->orderBy('nama_bergelar')->get();
        $pegawai = Pegawai::whereIn('id', array_column($this->personel, 'pegawai_id'))->get()->keyBy('id');
        $objek = Objek::with('parent')->get()->sortBy(fn ($o) => ($o->parent?->nama ?? $o->nama).' '.($o->parent ? $o->nama : ''));
        $mulai = $this->tanggal_mulai && strtotime($this->tanggal_mulai) ? $this->tanggal_mulai : null;
        $selesai = $this->tanggal_selesai && strtotime($this->tanggal_selesai) ? $this->tanggal_selesai : null;
        $penginput = DiinputOleh::pilihan($user);
        $revisi = $spt?->status_pengajuan === StatusPengajuan::PerluRevisi
            ? $spt->riwayat()->where('kejadian', KejadianSpt::PERLU_REVISI)->latest('id')->value('alasan') : null;

        return view('livewire.irban.spt-form', [
            'spt' => $spt,
            'dokumen' => $spt?->dokumen->firstWhere('jenis', 'draf'),
            'klasifikasiList' => Klasifikasi::orderBy('id')->get(),
            'klasifikasi' => $this->klasifikasi(),
            'kodeArsipOptions' => KodeArsip::orderBy('kode')->get()->mapWithKeys(fn ($k) => [$k->id => $k->kode.' · '.$k->nama])->all(),
            'itemOptions' => $items->mapWithKeys(fn ($i) => [$i->id => $i->jenisPenugasan->nama.' · '.$rentang[$i->id]])->all(),
            'item' => $item,
            'itemRentang' => $item ? $rentang[$item->id] : null,
            'bolehLainnya' => $bolehLainnya = $this->bolehLainnya(),
            'jenisOptions' => JenisPenugasan::orderBy('nama')->pluck('nama', 'id')->all() + ($bolehLainnya ? [self::JENIS_LAINNYA => Spt::LABEL_LAINNYA] : []),
            'jenisLainnya' => $bolehLainnya && $this->jenis_penugasan_id === self::JENIS_LAINNYA,
            'jenis' => $this->jenis_penugasan_id ? JenisPenugasan::find($this->jenis_penugasan_id) : null,
            'objekOptions' => $objek->mapWithKeys(fn ($o) => [$o->id => $o->parent ? $o->parent->nama.' › '.$o->nama : $o->nama])->all(),
            'pegawaiOptions' => $aktif->mapWithKeys(fn ($p) => [$p->id => $p->nama_bergelar.' · '.$p->nip.($p->jabatan ? ' · '.$p->jabatan : '')])->all(),
            'tims' => Tim::withCount('anggota')->where('irban_id', $user->irban_id)->where('tahun', $tahun)->orderBy('nama')->get(),
            'tahun' => $tahun,
            'pegawai' => $pegawai,
            'cek' => $validatePersonel->cek($this->personel),
            'peringatan' => $action->peringatan($this->only(self::FIELDS), $user->irban_id),
            'durasi' => $mulai && $selesai && $mulai <= $selesai ? Tanggal::durasiPenugasan($mulai, $selesai) : null,
            'saran' => $this->saran(),
            'penginput' => $penginput,
            'catatanRevisi' => $revisi,
        ]);
    }
}
