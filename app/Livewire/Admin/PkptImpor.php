<?php

namespace App\Livewire\Admin;

use App\Actions\Pkpt\ImportPkptItems;
use App\Exports\ArrayExport;
use App\Livewire\Concerns\HapusUnggahanSementara;
use App\Models\Pkpt;
use App\Services\Import\PkptItemImportParser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class PkptImpor extends Component
{
    use HapusUnggahanSementara, WithFileUploads;

    public Pkpt $pkpt;

    public $file = null;

    public int $step = 1;

    /** Unvalidated cell strings only; status is recomputed from them on every render and on import. */
    public array $raw = [];

    public string $namaFile = '';

    public bool $galatSaja = false;

    public bool $lewati = false;

    public bool $lewatiDuplikat = true;

    public int $diimpor = 0;

    public function mount(Pkpt $pkpt): void
    {
        Gate::authorize('manage-master-data');
        $this->pkpt = $pkpt;
    }

    public function updatedFile(): void
    {
        Gate::authorize('manage-master-data');
        if (! $this->pkpt->fresh()->isDraf()) {
            return;
        }
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream', 'max:5120']], [
            'file.mimes' => 'Berkas harus berformat .xlsx.', 'file.mimetypes' => 'Berkas harus berformat .xlsx.', 'file.max' => 'Ukuran berkas maksimal 5 MB.', 'file.uploaded' => 'Ukuran berkas maksimal 5 MB.',
        ]);

        try {
            $this->raw = app(PkptItemImportParser::class)->read($this->file->getRealPath());
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
        $this->reset('file', 'raw', 'namaFile', 'galatSaja', 'lewati', 'lewatiDuplikat', 'diimpor');
        $this->resetErrorBag();
        $this->step = 1;
    }

    public function impor(): void
    {
        Gate::authorize('manage-master-data');
        $this->pkpt->refresh();
        $rows = $this->rows;
        $galat = count(array_filter($rows, fn ($r) => $r['status'] === 'error'));
        if ($this->step !== 2 || ($galat > 0 && ! $this->lewati)) {
            return;
        }
        // Nothing importable: every row is an error, or the only non-error rows are duplicates that get skipped.
        if (! array_filter($rows, fn ($r) => $r['status'] === 'valid' || ($r['status'] === 'warning' && ! $this->lewatiDuplikat))) {
            $this->dispatch('toast', type: 'error', message: 'Tidak ada baris yang dapat diimpor.');

            return;
        }
        try {
            $this->diimpor = app(ImportPkptItems::class)->handle(auth()->user(), $this->pkpt, $rows, $this->lewatiDuplikat, $this->namaFile);
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: collect($e->errors())->flatten()->first());

            return;
        }
        $this->step = 3;
        $this->dispatch('toast', type: 'success', message: "{$this->diimpor} item diimpor.");
    }

    public function unduhTemplat()
    {
        Gate::authorize('manage-master-data');

        return Excel::download(new ArrayExport(PkptItemImportParser::HEADERS, [], [], ['A' => 7, 'B' => 17, 'C' => 15, 'D' => 17, 'E' => 40, 'F' => 12]), 'templat-item-pkpt.xlsx');
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

        return Excel::download(new ArrayExport(['Baris', 'Kolom', 'Pesan'], $out, [], ['A' => 10, 'B' => 22, 'C' => 80], ['C']), 'galat-impor-pkpt.xlsx');
    }

    #[Computed]
    public function rows(): array
    {
        if (count($this->raw) > PkptItemImportParser::MAX_ROWS) { // raw is client state; enforce the cap again
            $this->ulangi();
            $this->addError('file', PkptItemImportParser::capMessage());

            return [];
        }

        return $this->raw === [] ? [] : app(PkptItemImportParser::class)->analyze($this->pkpt, $this->raw);
    }

    public function render()
    {
        Gate::authorize('manage-master-data');
        $rows = $this->rows;
        $count = fn (string $s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));

        return view('livewire.admin.pkpt-impor', [
            'rows' => $rows, 'draf' => $this->pkpt->isDraf(),
            'valid' => $count('valid'), 'peringatan' => $count('warning'), 'galat' => $count('error'),
        ])->title("Impor PKPT {$this->pkpt->tahun}");
    }
}
