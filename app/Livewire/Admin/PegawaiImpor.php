<?php

namespace App\Livewire\Admin;

use App\Actions\MasterData\ImportPegawai;
use App\Exports\ArrayExport;
use App\Livewire\Concerns\HapusUnggahanSementara;
use App\Services\Import\PegawaiImportParser;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

#[Title('Impor Pegawai')]
class PegawaiImpor extends Component
{
    use HapusUnggahanSementara, WithFileUploads;

    public $file = null;

    public int $step = 1;

    /** Unvalidated cell strings only; status is recomputed from them on every render and on import, so tampered state cannot skip validation. */
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
            $this->raw = app(PegawaiImportParser::class)->read($this->file->getRealPath());
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
        $this->step = 2;
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
        $this->step = 1;
    }

    public function impor(): void
    {
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
        $this->diimpor = app(ImportPegawai::class)->handle(auth()->user(), $rows, $this->namaFile);
        $this->step = 3;
        $this->dispatch('toast', type: 'success', message: "{$this->diimpor} pegawai diimpor.");
    }

    public function unduhTemplat()
    {
        Gate::authorize('manage-master-data');

        return Excel::download(new ArrayExport(PegawaiImportParser::HEADERS, [], ['B:B'], ['A' => 30, 'B' => 22, 'C' => 20, 'D' => 10, 'E' => 30, 'F' => 28, 'G' => 7]), 'templat-pegawai.xlsx');
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

        return Excel::download(new ArrayExport(['Baris', 'Kolom', 'Pesan'], $out, [], ['A' => 10, 'B' => 22, 'C' => 80], ['C']), 'galat-impor-pegawai.xlsx');
    }

    #[Computed]
    public function rows(): array
    {
        if (count($this->raw) > PegawaiImportParser::MAX_ROWS) { // raw is client state; enforce the cap again
            $this->ulangi();
            $this->addError('file', PegawaiImportParser::capMessage());

            return [];
        }

        return $this->raw === [] ? [] : app(PegawaiImportParser::class)->analyze($this->raw);
    }

    public function render()
    {
        Gate::authorize('manage-master-data');
        $rows = $this->rows;
        $count = fn (string $s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));

        return view('livewire.admin.pegawai-impor', [
            'rows' => $rows,
            'valid' => $count('valid'), 'peringatan' => $count('warning'), 'galat' => $count('error'),
        ]);
    }
}
