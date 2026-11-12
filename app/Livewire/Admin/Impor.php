<?php

namespace App\Livewire\Admin;

use App\Actions\Spt\ImporSpt;
use App\Exports\ArrayExport;
use App\Livewire\Concerns\HapusUnggahanSementara;
use App\Services\Import\SptImportParser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Throwable;

/** Impor Data Lama (legacy SPT): same stepper as PkptImpor. The file is parsed and dropped; rows are recomputed on the server from `raw`. */
class Impor extends Component
{
    use HapusUnggahanSementara, WithFileUploads;

    public $file = null;

    public int $step = 1;

    /** Unvalidated cell strings only; status is recomputed from them on every render and on import. */
    public array $raw = [];

    public string $namaFile = '';

    public bool $galatSaja = false;

    public bool $lewati = false;

    public int $diimpor = 0;

    public function mount(): void
    {
        Gate::authorize('manage-master-data');
    }

    public function updatedFile(): void
    {
        Gate::authorize('manage-master-data');
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream', 'max:5120']], [
            'file.mimes' => 'Berkas harus berformat .xlsx.', 'file.mimetypes' => 'Berkas harus berformat .xlsx.', 'file.max' => 'Ukuran berkas maksimal 5 MB.', 'file.uploaded' => 'Ukuran berkas maksimal 5 MB.',
        ]);

        try {
            $this->raw = app(SptImportParser::class)->read($this->file->getRealPath());
        } catch (\InvalidArgumentException $e) {
            $this->addError('file', $e->getMessage());

            return;
        } catch (Throwable) {
            $this->addError('file', 'Berkas tidak dapat dibaca. Pastikan berkas .xlsx yang valid.');

            return;
        }
        if ($this->raw === []) {
            $this->addError('file', 'Berkas tidak berisi baris data.');

            return;
        }
        $this->namaFile = $this->file->getClientOriginalName();
        $this->hapusUnggahan($this->file); // remove the Livewire temp upload (and its sidecar) too
        $this->file = null; // the upload is never stored; only parsed cells are kept
        $this->pindah(2);
    }

    public function hapusFile(): void
    {
        Gate::authorize('manage-master-data');
        $this->reset('file');
        $this->resetErrorBag();
    }

    public function ulangi(): void
    {
        Gate::authorize('manage-master-data');
        $this->reset('file', 'raw', 'namaFile', 'galatSaja', 'lewati', 'diimpor');
        $this->resetErrorBag();
        $this->pindah(1);
    }

    public function impor(): void
    {
        @set_time_limit(300); // a 2000-row file takes several seconds of inserts; the PHP default of 30 s is too tight
        Gate::authorize('manage-master-data');
        $rows = $this->rows;
        $galat = count(array_filter($rows, fn ($r) => $r['status'] === 'error'));
        if ($this->step !== 2 || ($galat > 0 && ! $this->lewati)) {
            return;
        }
        if (count($rows) === $galat) { // every row is an error: nothing to import
            $this->dispatch('toast', type: 'error', message: 'Tidak ada baris yang dapat diimpor.');

            return;
        }
        try {
            $this->diimpor = app(ImporSpt::class)->handle(auth()->user(), $rows, $this->namaFile);
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: collect($e->errors())->flatten()->first());

            return;
        }
        $this->pindah(3);
        $this->dispatch('toast', type: 'success', message: "{$this->diimpor} SPT berhasil diimpor.");
    }

    public function unduhTemplat()
    {
        Gate::authorize('manage-master-data');
        // TEXT_COLUMNS holds names; ArrayExport wants column letters.
        $huruf = array_map(function ($h) {
            $kolom = Coordinate::stringFromColumnIndex(array_search($h, SptImportParser::HEADERS, true) + 1);

            return "{$kolom}:{$kolom}";
        }, SptImportParser::TEXT_COLUMNS);

        return Excel::download(new ArrayExport(SptImportParser::HEADERS, [], $huruf, ['A' => 11, 'B' => 13, 'C' => 7, 'D' => 13, 'E' => 17, 'F' => 40, 'G' => 40, 'H' => 40, 'I' => 15, 'J' => 17, 'K' => 16, 'L' => 11, 'M' => 40, 'N' => 11, 'O' => 13, 'P' => 9, 'Q' => 18, 'R' => 40]), 'templat-impor-spt.xlsx');
    }

    public function unduhGalat()
    {
        Gate::authorize('manage-master-data');
        $out = [];
        foreach ($this->rows as $r) {
            foreach ($r['errors'] as $e) {
                $out[] = [$r['baris'], $e['kolom'], $e['pesan']];
            }
        }

        return Excel::download(new ArrayExport(['Baris', 'Kolom', 'Pesan'], $out, [], ['A' => 10, 'B' => 22, 'C' => 80], ['C']), 'galat-impor-spt.xlsx');
    }

    /** Changes the step and moves focus to the step heading: the control that triggered it is gone after the morph. */
    private function pindah(int $step): void
    {
        $this->step = $step;
        $this->dispatch('impor-fokus');
    }

    #[Computed]
    public function rows(): array
    {
        if (count($this->raw) > SptImportParser::MAX_ROWS) { // raw is client state; enforce the cap again
            $this->ulangi();
            $this->addError('file', SptImportParser::capMessage());

            return [];
        }

        return $this->raw === [] ? [] : app(SptImportParser::class)->analyze($this->raw);
    }

    public function render()
    {
        Gate::authorize('manage-master-data');
        $rows = $this->rows;
        $count = fn (string $s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));

        return view('livewire.admin.impor', [
            'rows' => $rows, 'valid' => $count('valid'), 'peringatan' => $count('warning'), 'galat' => $count('error'),
            'kolom' => $this->petunjuk(),
        ])->title('Impor Data Lama');
    }

    /** @return list<array{0:string,1:string,2:string}> kolom, wajib, format/contoh: one row per header, in template order */
    private function petunjuk(): array
    {
        $tgl = 'dd/mm/yyyy atau yyyy-mm-dd; contoh 28/02/2025';
        $nip = '18 digit, format teks; pisahkan dengan ; bila lebih dari satu';

        return [
            ['nomor_spt', 'Ya', 'Teks, belum pernah ada; contoh 700/751/INSPEKTORAT-I/SPT/2025'],
            ['tanggal_spt', 'Ya', $tgl],
            ['irban', 'Ya', 'Nama atau kode nomor irban; contoh Irban I'],
            ['klasifikasi', 'Ya', 'Nama klasifikasi; contoh REGULER'],
            ['jenis_penugasan', 'Tidak', 'Nama jenis penugasan; dipakai mencocokkan item PKPT'],
            ['uraian_untuk', 'Ya', 'Teks, maksimal 1000 karakter'],
            ['periode_diperiksa', 'Tidak', 'Teks, maksimal 255 karakter; contoh Tahun 2024'],
            ['objek', 'Tidak', 'Nama objek pemeriksaan; pisahkan dengan ;'],
            ['tanggal_mulai', 'Ya', $tgl],
            ['tanggal_selesai', 'Ya', $tgl.'; tidak sebelum tanggal mulai'],
            ['nip_supervisor', 'Tidak', 'Paling banyak satu NIP; '.$nip],
            ['nip_ketua', 'Tidak', 'Wajib bila ada personel, tepat satu NIP; '.$nip],
            ['nip_anggota', 'Tidak', $nip],
            ['nomor_lhp', 'Tidak', 'Teks, maksimal 255 karakter; diisi bersama tanggal_lhp'],
            ['tanggal_lhp', 'Tidak', $tgl.'; diisi bersama nomor_lhp'],
            ['selesai', 'Tidak', 'ya atau tidak; kosong dianggap ya bila LHP terisi'],
            ['kendala_kategori', 'Tidak', 'Salah satu kategori kendala di Pengaturan Sistem; diisi bersama kendala_uraian'],
            ['kendala_uraian', 'Tidak', 'Teks, maksimal 2000 karakter'],
        ];
    }
}
