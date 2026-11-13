{{-- The Irban action modals (four `laksanakan` modals + Batalkan Penugasan) of the SPT detail page (Exact values "Modals"). md size; footer: x-app.diinput-oleh full width (login.md §5),
     then "Batal" + primary. Only the open modal ($aktif) renders its content, so ids such as `berkas` and `diinput_oleh` stay unique.
     Rules live in the actions; Tandai Selesai fields follow TandaiSelesai::syarat() and the block message TandaiSelesai::bisa(). No motion in forms (§7.5). --}}
@use('App\Support\Tanggal')
@use('App\Enums\SyaratSelesai')
@php
    $helperPdf = "PDF, maksimal {$maksMb} MB";
    $ukuranBerkas = $berkas ? number_format(max(1, round($berkas->getSize() / 1024)), 0, ',', '.').' KB' : null;
@endphp

@if ($laksanakan)
{{-- Ubah Tanggal Realisasi --}}
<x-ui.modal name="aksi-tanggal" title="Ubah Tanggal Realisasi">
    @if ($aktif === 'tanggal')
        <form id="form-aksi-tanggal" wire:submit="simpanTanggal" novalidate class="space-y-5">
            @include('auth.partials.error-summary', ['class' => '', 'fields' => [
                'tanggal_mulai' => 'Tanggal mulai', 'tanggal_selesai' => 'Tanggal selesai', 'alasan' => 'Alasan perubahan', 'diinput_oleh' => 'Diinput oleh',
            ]])
            <dl class="grid grid-cols-1 gap-x-4 gap-y-1 rounded-md border border-neutral-200 bg-neutral-25 p-3 sm:grid-cols-[auto_1fr]">
                <dt class="text-neutral-600">Tanggal saat ini</dt>
                <dd class="tabular-nums">
                    @if ($spt->tanggal_mulai && $spt->tanggal_selesai)
                        {{ Tanggal::rentang($spt->tanggal_mulai, $spt->tanggal_selesai) }} · {{ $spt->durasi() }} hari{{ $hariKerja ? ' kerja' : '' }}
                    @else — @endif
                </dd>
            </dl>
            <x-ui.date-range legend="Tanggal realisasi baru" required :error-mulai="$errors->first('tanggal_mulai')" :error-selesai="$errors->first('tanggal_selesai')" />
            <div>
                <x-ui.textarea label="Alasan perubahan" name="alasan" wire:model="alasan" rows="3" maxlength="1000" required
                    helper="Minimal 10 karakter." :error="$errors->first('alasan')" />
                <x-ui.penghitung-karakter model="alasan" :value="$alasan" />
            </div>
        </form>
    @endif
    <x-slot:footer>
        @if ($aktif === 'tanggal')
            @include('livewire.spt.partials.aksi-footer', ['modal' => 'tanggal'])
            <x-ui.button form="form-aksi-tanggal" icon="check" wire:loading.attr="disabled" wire:target="simpanTanggal" class="max-sm:w-full">Simpan Perubahan</x-ui.button>
        @endif
    </x-slot:footer>
</x-ui.modal>

{{-- Unggah SPT Final --}}
<x-ui.modal name="aksi-final" title="Unggah SPT Final">
    @if ($aktif === 'final')
        <form id="form-aksi-final" wire:submit="unggahFinal" novalidate class="space-y-5">
            @include('auth.partials.error-summary', ['class' => '', 'fields' => ['berkas' => 'SPT final', 'diinput_oleh' => 'Diinput oleh']])
            <p class="text-neutral-600">Unggah SPT final yang sudah ditandatangani. Dokumen sebelumnya tetap tersimpan.</p>
            <x-ui.file-upload name="berkas" label="SPT final bertanda tangan" accept=".pdf,application/pdf" wire:model="berkas" required :helper="$helperPdf"
                :error="$errors->first('berkas')" :filename="$berkas?->getClientOriginalName()" :size="$ukuranBerkas" />
        </form>
    @endif
    <x-slot:footer>
        @if ($aktif === 'final')
            @include('livewire.spt.partials.aksi-footer', ['modal' => 'final'])
            <x-ui.button form="form-aksi-final" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="unggahFinal,berkas" class="max-sm:w-full">Unggah</x-ui.button>
        @endif
    </x-slot:footer>
</x-ui.modal>

{{-- Tandai Selesai --}}
<x-ui.modal name="aksi-selesai" title="Tandai Selesai">
    @if ($aktif === 'selesai')
        @php
            $lhp = $syarat === SyaratSelesai::LhpLengkap;
            $opsional = in_array($syarat, [SyaratSelesai::LaporanOpsional, SyaratSelesai::DokumenOpsional], true);
            $hariIni = now('Asia/Jakarta')->toDateString();
        @endphp
        <form id="form-aksi-selesai" wire:submit="tandaiSelesai" novalidate class="space-y-5">
            @include('auth.partials.error-summary', ['class' => '', 'fields' => [
                'berkas' => $lhp ? 'Cover LHP' : 'Dokumen', 'nomor_lhp' => 'Nomor LHP', 'tanggal_lhp' => 'Tanggal LHP', 'konfirmasi' => 'Konfirmasi', 'realisasi_dana' => 'Realisasi dana', 'diinput_oleh' => 'Diinput oleh',
            ]])
            @if ($blokir)
                <x-ui.alert variant="warning" id="selesai-blokir"><p>{{ $blokir }}</p></x-ui.alert>
            @endif
            <p class="text-neutral-600">Setelah ditandai Selesai, tanggal, dokumen, dan kendala SPT ini tidak dapat diubah lagi.</p>
            @if ($syarat === SyaratSelesai::Konfirmasi)
                <div>
                    <x-ui.checkbox name="konfirmasi" label="Saya menyatakan penugasan ini telah selesai." wire:model="konfirmasi"
                        :aria-invalid="$errors->has('konfirmasi') ? 'true' : null" :aria-describedby="$errors->has('konfirmasi') ? 'konfirmasi-error' : null" />
                    <x-ui.field-meta part="meta" id="konfirmasi" :error="$errors->first('konfirmasi')" />
                </div>
            @else
                <x-ui.file-upload name="berkas" :label="$lhp ? 'Cover LHP' : 'Laporan/dokumen penyelesaian (opsional)'" accept=".pdf,application/pdf" wire:model="berkas" :required="$lhp"
                    :helper="$helperPdf" :error="$errors->first('berkas')" :filename="$berkas?->getClientOriginalName()" :size="$ukuranBerkas"
                    :remove-action="$opsional ? 'hapusBerkas' : null" />
                <x-ui.input :label="$lhp ? 'Nomor LHP' : 'Nomor LHP (opsional)'" name="nomor_lhp" wire:model="nomor_lhp" maxlength="100" :required="$lhp"
                    :error="$errors->first('nomor_lhp')" class="tabular-nums" />
                <x-ui.input type="date" :label="$lhp ? 'Tanggal LHP' : 'Tanggal LHP (opsional)'" name="tanggal_lhp" wire:model="tanggal_lhp" :required="$lhp"
                    :min="$spt->tanggal_mulai?->toDateString()" :max="$hariIni" :error="$errors->first('tanggal_lhp')" class="tabular-nums" />
            @endif
            <x-ui.input-rupiah label="Realisasi dana (opsional)" name="realisasi_dana" id="realisasi_dana" wire:model="realisasi_dana" :value="$realisasi_dana"
                helper="Dapat diisi atau diubah nanti dari halaman detail." :error="$errors->first('realisasi_dana')" />
        </form>
    @endif
    <x-slot:footer>
        @if ($aktif === 'selesai')
            @include('livewire.spt.partials.aksi-footer', ['modal' => 'selesai'])
            <x-ui.button form="form-aksi-selesai" icon="check-circle" wire:loading.attr="disabled" wire:target="tandaiSelesai,berkas" class="max-sm:w-full"
                :disabled="(bool) $blokir" :aria-describedby="$blokir ? 'selesai-blokir' : null">Tandai Selesai</x-ui.button>
        @endif
    </x-slot:footer>
</x-ui.modal>

{{-- Isi / Ubah Kendala --}}
<x-ui.modal name="aksi-kendala" :title="$spt->kendala ? 'Ubah Kendala' : 'Isi Kendala'">
    @if ($aktif === 'kendala')
        <form id="form-aksi-kendala" wire:submit="simpanKendala" novalidate class="space-y-5">
            @include('auth.partials.error-summary', ['class' => '', 'fields' => ['kategori' => 'Kategori', 'uraian' => 'Uraian', 'diinput_oleh' => 'Diinput oleh']])
            <x-ui.select label="Kategori" name="kategori" wire:model="kategori" :options="array_combine($kategoriKendala, $kategoriKendala)" placeholder="Pilih kategori"
                :value="$kategori" required :error="$errors->first('kategori')" />
            <div>
                <x-ui.textarea label="Uraian" name="uraian" wire:model="uraian" rows="4" maxlength="2000" required
                    helper="Jelaskan penyebab kendala (minimal 10 karakter)." :error="$errors->first('uraian')" />
                <x-ui.penghitung-karakter model="uraian" :value="$uraian" :max="2000" />
            </div>
        </form>
    @endif
    <x-slot:footer>
        @if ($aktif === 'kendala')
            @include('livewire.spt.partials.aksi-footer', ['modal' => 'kendala'])
            <x-ui.button form="form-aksi-kendala" icon="check" wire:loading.attr="disabled" wire:target="simpanKendala" class="max-sm:w-full">Simpan Kendala</x-ui.button>
        @endif
    </x-slot:footer>
</x-ui.modal>
@endif

{{-- Realisasi Dana (versi 1.3 Task 3): owning Irban; gated on the policy only so a stale tab's save becomes a toast (UbahRealisasiDana::cegah()). --}}
@if ($ubahDana)
<x-ui.modal name="aksi-dana" title="Realisasi Dana">
    @if ($aktif === 'dana')
        <form id="form-aksi-dana" wire:submit="simpanDana" novalidate class="space-y-5">
            @include('auth.partials.error-summary', ['class' => '', 'fields' => ['realisasi_dana' => 'Realisasi dana', 'diinput_oleh' => 'Diinput oleh']])
            <x-ui.input-rupiah label="Realisasi dana" name="realisasi_dana" id="realisasi_dana" wire:model="realisasi_dana" :value="$realisasi_dana"
                helper="Dalam Rupiah. Kosongkan lalu simpan untuk menghapus nilai." :error="$errors->first('realisasi_dana')" />
        </form>
    @endif
    <x-slot:footer>
        @if ($aktif === 'dana')
            @include('livewire.spt.partials.aksi-footer', ['modal' => 'dana'])
            <x-ui.button form="form-aksi-dana" icon="check" wire:loading.attr="disabled" wire:target="simpanDana" class="max-sm:w-full">Simpan</x-ui.button>
        @endif
    </x-slot:footer>
</x-ui.modal>
@endif

{{-- Batalkan Penugasan (versi 1.2.1 Task 4): owning Irban, before Selesai (BatalkanNomor::cegah()). Danger confirm; "Kembali" instead of "Batal" so the two buttons never read alike. --}}
@if ($modalBatal)
<x-ui.modal name="aksi-batal" title="Batalkan Penugasan">
    @if ($aktif === 'batal')
        <form id="form-aksi-batal" wire:submit="batalkan" novalidate class="space-y-5">
            @include('auth.partials.error-summary', ['class' => '', 'fields' => ['alasan' => 'Alasan pembatalan', 'diinput_oleh' => 'Diinput oleh']])
            <p class="text-neutral-900">Nomor <span class="font-semibold tabular-nums [overflow-wrap:anywhere]">{{ $spt->nomor_lengkap }}</span> akan ditandai Dibatalkan. Bagian Umum dan Pimpinan menerima notifikasi beserta alasannya.</p>
            <x-ui.alert variant="warning"><p>Nomor tidak dapat dipakai ulang.</p></x-ui.alert>
            <div>
                <x-ui.textarea label="Alasan pembatalan" name="alasan" wire:model="alasan" rows="4" maxlength="1000" required
                    helper="Minimal 10 karakter." :error="$errors->first('alasan')" />
                <x-ui.penghitung-karakter model="alasan" :value="$alasan" />
            </div>
        </form>
    @endif
    <x-slot:footer>
        @if ($aktif === 'batal')
            @include('livewire.spt.partials.aksi-footer', ['modal' => 'batal', 'tutup' => 'Kembali'])
            <x-ui.button form="form-aksi-batal" variant="danger" icon="no-symbol" wire:loading.attr="disabled" wire:target="batalkan" class="max-sm:w-full">Batalkan Penugasan</x-ui.button>
        @endif
    </x-slot:footer>
</x-ui.modal>
@endif
