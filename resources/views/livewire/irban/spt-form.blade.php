{{-- Irban "Ajukan Nomor SPT" / edit (irban.md "Submission form", _shared.md §4 forms, login.md §5 Diinput oleh). Target: 6 personel < 30 s.
     No motion in the form (DESIGN §7.5) except the 150ms mint-50 flash on a moved personel row, off under reduced motion (motion-safe:).
     Text fields are deferred/blur so typing never round-trips; only fields that drive warnings, durasi or the suggestion are live. --}}
@php
    $fields = [
        'diinput_oleh' => 'Diinput oleh', 'klasifikasi_id' => 'Klasifikasi', 'kode_arsip_id' => 'Kode arsip', 'pkpt_item_id' => 'Item PKPT', 'jenis_penugasan_id' => 'Jenis penugasan',
        'jenis_penugasan_lainnya' => 'Jenis penugasan lainnya', 'objek_ids' => 'Wilayah/Objek', 'objek_lainnya' => 'Objek lainnya',
        'tanggal_mulai' => 'Tanggal mulai', 'tanggal_selesai' => 'Tanggal selesai', 'periode_diperiksa' => 'Periode diperiksa',
        'uraian_untuk' => 'Untuk', 'ppj_pegawai_id' => 'PPJ', 'personel' => 'Susunan Personel', 'lampiran' => 'Lampiran draf', 'catatan_bagian_umum' => 'Catatan untuk Bagian Umum',
    ];
    $peranLabel = ['supervisor_dalnis' => 'Supervisor/Dalnis', 'ketua' => 'Ketua Tim', 'anggota' => 'Anggota'];
    $wajib = (bool) $klasifikasi?->wajib_item_pkpt;
    $pkptDraf = in_array('PKPT belum disahkan.', $peringatan, true);
    $diLuar = collect($peringatan)->first(fn ($w) => str_starts_with($w, 'Di luar jadwal PKPT'));
    $ditandai = collect($peringatan)->first(fn ($w) => str_starts_with($w, 'Perubahan Penugasan disetujui'));
    $section = 'border-t border-neutral-200 pt-6';
    $h3 = 'mb-4 text-lg font-semibold text-neutral-900';
    $iconBtn = 'inline-flex size-11 shrink-0 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 aria-disabled:text-neutral-400 aria-disabled:hover:bg-transparent lg:size-8';
@endphp
<div x-data="{
        typed: false, leaving: false, tujuan: null,
        get dirty() { return ! this.leaving && (this.typed || $wire.berubah) },
        // Unsaved-changes guard (_shared.md §4): browser unload + wire:navigate; listeners live as long as this form.
        init() {
            const pesan = this.$root.dataset.pesanTinggalkan;
            this.unload = (e) => { if (this.dirty) { e.preventDefault(); e.returnValue = pesan } };
            this.nav = (e) => { if (this.dirty && ! confirm(pesan)) e.preventDefault() };
            window.addEventListener('beforeunload', this.unload);
            document.addEventListener('livewire:navigate', this.nav);
        },
        destroy() {
            window.removeEventListener('beforeunload', this.unload);
            document.removeEventListener('livewire:navigate', this.nav);
        },
        // Within a row: ↑/↓ moves focus to the same control in the next row; Alt+↑/↓ reorders and keeps focus on the moved row.
        keys(e, id) {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
            if (e.target.closest('[role=combobox]')) return;
            e.preventDefault();
            const k = e.target.dataset.fokus ?? 'nama';
            const up = e.key === 'ArrowUp';
            const row = e.target.closest('[data-row]');
            const next = up ? row.previousElementSibling : row.nextElementSibling;
            if (e.altKey) { if (next) { this.tujuan = { id, k }; $wire[up ? 'naik' : 'turun'](id) } return; }
            if (next) this.fokus(next.dataset.row, k);
        },
        fokus(id, k) {
            requestAnimationFrame(() => {
                const row = document.querySelector(`[data-row='${id}']`);
                (row?.querySelector(k === 'peran' ? '[data-fokus=peran]:checked' : `[data-fokus=${k}]`) ?? row?.querySelector('[data-fokus=peran]'))?.focus();
            });
        },
    }"
    x-on:input="typed = true" x-on:change="typed = true"
    x-on:personel-dipindah.window="if (tujuan) { fokus(tujuan.id, tujuan.k); tujuan = null }"
    x-on:tersimpan.window="typed = false" x-on:gagal-simpan.window="leaving = false"
    data-pesan-tinggalkan="Perubahan belum disimpan. Tinggalkan halaman?">

    <x-ui.page-header :title="$sptId ? 'Ubah Pengajuan SPT' : 'Ajukan Nomor SPT'"
        description="Isi data dasar, waktu dan susunan personel. Simpan draf kapan saja; Ajukan Nomor mengirim pengajuan ke Bagian Umum.">
        @if ($spt)
            <x-slot:meta><div class="mt-2"><x-app.status-badge :status="$spt->status()" /></div></x-slot:meta>
        @endif
    </x-ui.page-header>

    {{-- Enter in a text field must not submit "Ajukan Nomor" by accident; comboboxes handle their own Enter first. --}}
    <form id="form-spt" wire:submit="ajukan" novalidate class="max-w-5xl space-y-6" x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()">
        @if ($catatanRevisi)
            <x-ui.alert variant="warning" title="Perlu revisi dari Bagian Umum">
                <p class="mt-1 whitespace-pre-line">{{ $catatanRevisi }}</p>
            </x-ui.alert>
        @endif
        @include('auth.partials.error-summary', ['class' => '', 'fields' => $fields])

        {{-- 1. Dasar --}}
        <section aria-labelledby="h-dasar" class="max-w-3xl space-y-5">
            <h2 id="h-dasar" class="{{ $h3 }}">Dasar</h2>
            <fieldset>
                <legend class="mb-1.5 text-sm font-medium text-neutral-900">Klasifikasi<span class="text-danger-700" aria-hidden="true"> *</span><span class="sr-only"> (wajib)</span></legend>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($klasifikasiList as $k)
                        <label class="flex min-h-12 cursor-pointer items-center gap-2 rounded-md border border-border-strong bg-white px-3 text-sm font-medium text-neutral-900 hover:bg-neutral-25 has-checked:border-mint-700 has-checked:bg-mint-50 has-checked:shadow-[inset_0_0_0_1px_var(--color-mint-700)]">
                            <input type="radio" name="klasifikasi_id" value="{{ $k->id }}" wire:model.live="klasifikasi_id" @if ($loop->first) id="klasifikasi_id" @endif
                                @if ($errors->has('klasifikasi_id')) aria-invalid="true" aria-describedby="klasifikasi_id-error" @endif
                                class="size-5 border-border-strong text-mint-700 focus:ring-2 focus:ring-mint-600 focus:ring-offset-2">
                            {{ $k->nama }}
                        </label>
                    @endforeach
                </div>
                <x-ui.field-meta part="meta" id="klasifikasi_id" :error="$errors->first('klasifikasi_id')" />
            </fieldset>

            <x-ui.select label="Kode arsip" name="kode_arsip_id" wire:model="kode_arsip_id" :options="$kodeArsipOptions" placeholder="Pilih kode arsip" required
                :value="$kode_arsip_id" helper="Tampil pada nomor SPT, sebelum kode unit." :error="$errors->first('kode_arsip_id')" />

            <div>
                @unless ($non_pkpt)
                    <x-ui.combobox name="pkpt_item_id" label="Item PKPT" :options="$itemOptions" wire:model.live="pkpt_item_id" :required="$wajib"
                        placeholder="Cari jenis penugasan" :error="$errors->first('pkpt_item_id')"
                        :helper="'Item PKPT '.$tahun.' milik irban ini.'.($wajib ? ' Wajib untuk klasifikasi '.$klasifikasi->nama.'.' : '')" />
                    @if ($item)
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-neutral-600">
                            <x-app.type-chip :color="$item->jenisPenugasan->warna" :label="$item->jenisPenugasan->nama" />
                            <span class="tabular-nums">Jadwal PKPT: {{ $itemRentang }}</span>
                            @php $tag = $item->pkpt->status->tag(); @endphp
                            <x-app.tag :icon="$tag['icon']" :variant="$tag['variant']">PKPT {{ $item->pkpt->status->label() }}</x-app.tag>
                        </div>
                        @if ($pkptDraf)
                            <x-ui.alert variant="warning" class="mt-2">PKPT belum disahkan.</x-ui.alert>
                        @endif
                        @if ($ditandai)
                            <x-ui.alert variant="warning" class="mt-2">{{ $ditandai }}</x-ui.alert>
                        @endif
                    @endif
                @endunless
                <div class="mt-2">
                    <x-ui.checkbox name="non_pkpt" label="Non-PKPT (tidak terkait item PKPT)" wire:model.live="non_pkpt" :disabled="$wajib"
                        aria-describedby="non_pkpt-helper" />
                    <p id="non_pkpt-helper" class="text-xs text-neutral-500">
                        {{ $wajib ? 'Klasifikasi '.$klasifikasi->nama.' wajib memakai item PKPT.' : 'Centang bila penugasan ini tidak ada dalam PKPT.' }}
                    </p>
                    @if ($non_pkpt)
                        <div class="mt-2 flex flex-wrap items-center gap-2 rounded-md border border-neutral-200 bg-white p-3 text-sm text-neutral-900">
                            <x-app.flag jenis="non_pkpt" />
                            <span>SPT ini akan ditandai Non-PKPT dan tidak dihitung sebagai realisasi item PKPT.</span>
                        </div>
                    @endif
                </div>
            </div>

            @if ($item)
                <div>
                    <p class="mb-1.5 text-sm font-medium text-neutral-900">Jenis penugasan</p>
                    <p class="flex min-h-10 flex-wrap items-center gap-2 text-sm text-neutral-600">
                        <x-app.type-chip :color="$item->jenisPenugasan->warna" :label="$item->jenisPenugasan->nama" /> <span>· otomatis dari item PKPT</span>
                    </p>
                </div>
            @else
                {{-- 1.2 K3: non-PKPT gets the last option "Lainnya" (SptForm::JENIS_LAINNYA) and types the jenis; shown without animation (DESIGN §7.5). --}}
                <x-ui.select label="Jenis penugasan" name="jenis_penugasan_id" :options="$jenisOptions" placeholder="Pilih jenis penugasan" required
                    :helper="$bolehLainnya ? 'Pilih “Lainnya” bila jenis penugasan tidak ada di daftar.' : null"
                    wire:model.live="jenis_penugasan_id" :value="$jenis_penugasan_id" :error="$errors->first('jenis_penugasan_id')" />
                @if ($jenisLainnya)
                    <x-ui.input label="Jenis penugasan lainnya" name="jenis_penugasan_lainnya" wire:model.live.blur="jenis_penugasan_lainnya" required maxlength="150"
                        helper="Contoh: Pendampingan pemeriksaan BPK" :error="$errors->first('jenis_penugasan_lainnya')" />
                @endif
            @endif

            <x-ui.combobox name="objek_ids" label="Wilayah/Objek" multiple :options="$objekOptions" wire:model.live="objek_ids" :required="! $bolehLainnya"
                placeholder="Cari kecamatan, desa atau OPD"
                :helper="'Bisa lebih dari satu. Desa dan unit di bawah kecamatan tampil sebagai “Kecamatan › Desa”.'.($bolehLainnya ? ' Isi Objek lainnya bila objek tidak ada di daftar.' : '')"
                :error="$errors->first('objek_ids')" />
            @if ($bolehLainnya)
                <x-ui.input label="Objek lainnya" name="objek_lainnya" wire:model.live.blur="objek_lainnya" maxlength="255"
                    helper="Wajib bila tidak ada objek dari daftar; boleh diisi bersama objek dari daftar." :error="$errors->first('objek_lainnya')" />
            @endif
        </section>

        {{-- 2. Waktu + periode --}}
        <section aria-labelledby="h-waktu" class="{{ $section }} max-w-3xl space-y-5">
            <h2 id="h-waktu" class="{{ $h3 }}">Waktu</h2>
            <x-ui.date-range legend="Tanggal pelaksanaan" required live :durasi="$durasi"
                :error-mulai="$errors->first('tanggal_mulai')" :error-selesai="$errors->first('tanggal_selesai')" />
            @if ($diLuar)
                <x-ui.alert variant="warning">{{ $diLuar }}</x-ui.alert>
            @endif
            <x-ui.input label="Periode diperiksa" name="periode_diperiksa" wire:model.blur="periode_diperiksa" required maxlength="255"
                helper="Contoh: Tahun Anggaran 2025" :error="$errors->first('periode_diperiksa')" />
        </section>

        {{-- 3. Untuk --}}
        <section aria-labelledby="h-untuk" class="{{ $section }} max-w-3xl">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 id="h-untuk" class="text-lg font-semibold text-neutral-900">Untuk</h2>
                <x-ui.button type="button" variant="ghost" size="sm" icon="arrow-path" wire:click="gunakanSaran" :disabled="! $saran">Gunakan saran</x-ui.button>
            </div>
            <x-ui.textarea label="Uraian penugasan" name="uraian_untuk" wire:model="uraian_untuk" rows="3" maxlength="1000" required
                :helper="$saran ? 'Saran: '.$saran : 'Saran muncul setelah jenis penugasan dan objek dipilih.'" :error="$errors->first('uraian_untuk')" />
            <x-ui.penghitung-karakter model="uraian_untuk" :value="$uraian_untuk" />
        </section>

        {{-- 4. PPJ --}}
        <section aria-labelledby="h-ppj" class="{{ $section }} max-w-3xl">
            <h2 id="h-ppj" class="{{ $h3 }}">Penanggung jawab</h2>
            <x-ui.combobox name="ppj_pegawai_id" label="PPJ" :options="$pegawaiOptions" wire:model="ppj_pegawai_id" required placeholder="Cari nama atau NIP"
                helper="Terisi otomatis dari Komposisi Irban tahun ini; dapat diganti." :error="$errors->first('ppj_pegawai_id')" />
        </section>

        {{-- 5. Tim Pemeriksa: two panes ≥1024, stacked below --}}
        <section aria-labelledby="h-tim" class="{{ $section }}">
            <h2 id="h-tim" class="{{ $h3 }}">Tim Pemeriksa</h2>
            <div class="grid gap-6 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
                <fieldset class="min-w-0">
                    <legend class="mb-2 text-sm font-semibold text-neutral-900">Pilih Tim</legend>
                    @forelse ($tims as $t)
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-md px-2 text-sm text-neutral-900 hover:bg-neutral-50 lg:min-h-10">
                            <input type="checkbox" value="{{ $t->id }}" wire:click="toggleTim({{ $t->id }})" @checked(in_array($t->id, $timDipilih, true))
                                wire:key="tim-{{ $t->id }}-{{ in_array($t->id, $timDipilih, true) ? 1 : 0 }}"
                                class="size-5 rounded-sm border-border-strong text-mint-700 focus:ring-2 focus:ring-mint-600 focus:ring-offset-2">
                            <span>{{ $t->nama }} · {{ $t->anggota_count }} orang</span>
                        </label>
                    @empty
                        <p class="text-sm text-neutral-600">Belum ada tim untuk irban ini tahun {{ $tahun }}. Tambahkan personel satu per satu di sebelah.</p>
                    @endforelse
                </fieldset>

                <div class="min-w-0">
                    <h3 id="personel" tabindex="-1" class="mb-2 text-sm font-semibold text-neutral-900">Susunan Personel</h3>
                    <ul aria-live="polite" class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-sm" aria-label="Pemeriksaan susunan personel">
                        @foreach ([['ketua', '1 Ketua Tim'], ['supervisor', 'Maks. 1 Supervisor/Dalnis'], ['duplikat', 'Tidak ada duplikat']] as [$k, $label])
                            <li @class(['flex items-center gap-1.5', 'text-neutral-900' => $cek[$k], 'text-danger-700' => ! $cek[$k]])>
                                {{ svg($cek[$k] ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle', 'size-5 shrink-0 '.($cek[$k] ? 'text-success-700' : 'text-danger-700'), ['aria-hidden' => 'true']) }}
                                {{ $label }}<span class="sr-only">: {{ $cek[$k] ? 'terpenuhi' : 'belum terpenuhi' }}</span>
                            </li>
                        @endforeach
                        <li class="font-medium text-neutral-900 tabular-nums">{{ $cek['jumlah'] }} personel dipilih</li>
                    </ul>
                    @error('personel')
                        <div id="personel-error" class="mb-3 space-y-1 text-sm text-danger-700">
                            @foreach ($errors->get('personel') as $m)
                                <p class="flex items-start gap-1.5">{{ svg('heroicon-m-exclamation-circle', 'size-5 shrink-0') }}<span>{{ $m }}</span></p>
                            @endforeach
                        </div>
                    @enderror

                    @if ($personel)
                        <ol wire:sort="pindah" wire:sort:config="{ animation: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 150 }"
                            aria-label="Susunan personel, urutan sesuai SPT" aria-describedby="personel-bantuan" class="space-y-2">
                            @foreach ($personel as $row)
                                @php
                                    $p = $pegawai[$row['pegawai_id']] ?? null;
                                    $nama = $p?->nama_bergelar ?? 'Pegawai';
                                @endphp
                                <li wire:key="p-{{ $row['pegawai_id'] }}" wire:sort:item="{{ $row['pegawai_id'] }}" data-row="{{ $row['pegawai_id'] }}"
                                    x-data="{ flash: false }"
                                    x-on:personel-dipindah.window="if ($event.detail.id == {{ $row['pegawai_id'] }}) { flash = true; setTimeout(() => flash = false, 150) }"
                                    x-on:keydown="keys($event, {{ $row['pegawai_id'] }})"
                                    x-bind:class="flash && 'motion-safe:bg-mint-50'"
                                    @class(['flex min-h-14 flex-wrap items-center gap-x-2 gap-y-1 rounded-md border border-neutral-200 px-2 py-1.5',
                                        'bg-white' => $row['ikut'], 'bg-neutral-100' => ! $row['ikut']])>
                                    <span wire:sort:handle class="hidden cursor-grab text-neutral-600 sm:inline-flex" title="Seret untuk memindah" aria-hidden="true">{{ svg('heroicon-m-bars-3', 'size-5') }}</span>
                                    <span class="inline-flex">
                                        <button type="button" wire:click="naik({{ $row['pegawai_id'] }})" data-fokus="naik" class="{{ $iconBtn }}"
                                            aria-label="Pindahkan {{ $nama }} ke atas" @if ($loop->first) aria-disabled="true" @endif>{{ svg('heroicon-m-chevron-up', 'size-5') }}</button>
                                        <button type="button" wire:click="turun({{ $row['pegawai_id'] }})" data-fokus="turun" class="{{ $iconBtn }}"
                                            aria-label="Pindahkan {{ $nama }} ke bawah" @if ($loop->last) aria-disabled="true" @endif>{{ svg('heroicon-m-chevron-down', 'size-5') }}</button>
                                    </span>
                                    <label class="inline-flex size-11 shrink-0 cursor-pointer items-center justify-center lg:size-8">
                                        <input type="checkbox" @checked($row['ikut']) wire:change="aturIkut({{ $row['pegawai_id'] }}, $event.target.checked)" data-fokus="ikut"
                                            class="size-5 rounded-sm border-border-strong text-mint-700 focus:ring-2 focus:ring-mint-600 focus:ring-offset-2">
                                        <span class="sr-only">Ikutkan {{ $nama }}</span>
                                    </label>
                                    <div class="min-w-0 flex-1 basis-32">
                                        <p @class(['truncate text-sm font-semibold', 'text-neutral-900' => $row['ikut'], 'text-neutral-600 line-through' => ! $row['ikut']])>
                                            <span class="tabular-nums text-neutral-600">{{ $loop->iteration }}.</span> {{ $nama }}
                                        </p>
                                        <p class="truncate text-xs text-neutral-600 tabular-nums">
                                            @unless ($row['ikut'])Tidak diikutkan · @endunless NIP {{ $p?->nip }}
                                        </p>
                                    </div>
                                    <x-ui.segmented :name="'peran-'.$row['pegawai_id']" :legend="'Peran untuk '.$nama" :options="$peranLabel" :value="$row['peran']"
                                        wire:change="aturPeran({{ $row['pegawai_id'] }}, $event.target.value)" data-fokus="peran" class="max-sm:order-last" />
                                    <button type="button" wire:click="hapus({{ $row['pegawai_id'] }})" data-fokus="hapus" class="{{ $iconBtn }} ml-auto"
                                        aria-label="Hapus {{ $nama }} dari susunan">{{ svg('heroicon-m-x-mark', 'size-5') }}</button>
                                </li>
                            @endforeach
                        </ol>
                        <p id="personel-bantuan" class="mt-2 text-xs text-neutral-500">Urutan mengikuti SPT. Tombol panah atau Alt+↑/↓ memindah baris; ↑/↓ berpindah antarbaris.</p>
                    @else
                        <p class="rounded-md border border-dashed border-border-strong bg-white p-4 text-sm text-neutral-600">Belum ada personel. Centang tim di “Pilih Tim” atau tambahkan pegawai di bawah.</p>
                    @endif
                    <p class="sr-only" aria-live="polite">{{ $pengumuman }}</p>

                    <div class="mt-4">
                        <x-ui.combobox name="tambahId" id="tambah-pegawai" label="+ Tambahkan anggota lainnya" :options="$pegawaiOptions" wire:model.live="tambahId"
                            placeholder="Cari nama atau NIP" helper="Pegawai aktif; ditambahkan sebagai Anggota." />
                    </div>
                </div>
            </div>
        </section>

        {{-- 6. Lampiran + catatan --}}
        <section aria-labelledby="h-lampiran" class="{{ $section }} max-w-3xl space-y-5">
            <h2 id="h-lampiran" class="{{ $h3 }}">Lampiran dan catatan</h2>
            @php
                $nama = $lampiran?->getClientOriginalName() ?? ($hapusLampiranLama ? null : $dokumen?->nama_asli);
                $ukuran = $lampiran?->getSize() ?? ($hapusLampiranLama ? null : $dokumen?->ukuran);
            @endphp
            <x-ui.file-upload name="lampiran" label="Lampiran draf SPT (opsional)" accept=".pdf,application/pdf" wire:model="lampiran"
                helper="PDF, maksimal 5 MB." :error="$errors->first('lampiran')" :filename="$nama"
                :size="$ukuran ? number_format($ukuran / 1024, 0, ',', '.').' KB' : null" remove-action="hapusLampiran"
                :href="! $lampiran && $nama && $dokumen ? route('spt.dokumen', [$spt, $dokumen]) : null" />
            <x-ui.textarea label="Catatan untuk Bagian Umum (opsional)" name="catatan_bagian_umum" wire:model="catatan_bagian_umum" rows="2" maxlength="1000"
                :error="$errors->first('catatan_bagian_umum')" />
        </section>

        {{-- Sticky footer (_shared.md §4): Diinput oleh left; Batal · Simpan draf · Ajukan Nomor right. --}}
        <div data-sticky-footer class="sticky bottom-0 z-20 -mx-4 -mb-6 flex flex-col gap-3 border-t border-neutral-200 bg-white px-4 py-3 shadow-[0_-6px_16px_-4px_rgb(8_43_35/.12)] md:-mx-6 md:px-6 lg:-mx-8 lg:flex-row lg:items-end lg:justify-between lg:px-8">
            <x-app.diinput-oleh :pegawai="$penginput" :value="$diinput_oleh" :ganti="$ganti" :error="$errors->first('diinput_oleh')" drop-up
                wire:model.live="diinput_oleh" class="min-w-0 lg:w-96" />
            {{-- One row even at 360 (no icons) so the sticky bar stays short on phones. --}}
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($tersimpanPada)
                    <p class="mr-auto text-xs text-neutral-500 tabular-nums lg:mr-2" role="status">Draf tersimpan {{ $tersimpanPada }}</p>
                @endif
                <a href="{{ route('irban.spt') }}" wire:navigate class="inline-flex h-11 items-center justify-center rounded-md px-3 text-sm font-medium text-mint-700 hover:bg-mint-50 lg:h-10 lg:px-4">Batal</a>
                <x-ui.button type="button" variant="secondary" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan,ajukan,lampiran" class="max-sm:px-3">Simpan draf</x-ui.button>
                <x-ui.button form="form-spt" x-on:click="leaving = true" wire:loading.attr="disabled" wire:target="simpan,ajukan,lampiran" class="max-sm:px-3">Ajukan Nomor</x-ui.button>
            </div>
        </div>
    </form>
</div>
