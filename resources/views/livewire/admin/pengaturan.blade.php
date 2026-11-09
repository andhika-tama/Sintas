{{-- admin.md "Pengaturan Sistem": grouped cards, each with its own explicit "Simpan perubahan" (_shared.md §4 forms, §5 toast on save). --}}
@php
    $err = fn ($key) => $errors->first("v.$key");
    $save = fn ($group) => view('livewire.admin.partials.simpan', ['group' => $group]);
@endphp
<div>
    <x-ui.page-header title="Pengaturan Sistem" description="Aturan penomoran, penanda, rekap, publik, dan unggahan. Setiap kartu disimpan terpisah." />

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <x-ui.card>
            <form novalidate wire:submit="save('penomoran')" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Penomoran SPT</h2>
                    <p class="mt-1 text-sm text-neutral-600">Nomor terbit saat SPT disetujui.</p>
                </div>
                <x-ui.input label="Format nomor" name="penomoran_format" wire:model="v.penomoran.format" required :error="$err('penomoran.format')"
                    helper="Wajib memuat {nomor_urut} dan {tahun}. Penanda lain: {kode_arsip}, {kode_irban}." />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Kode arsip" name="penomoran_kode_arsip" wire:model="v.penomoran.kode_arsip" required :error="$err('penomoran.kode_arsip')" />
                </div>
                <x-ui.select label="Cakupan nomor urut" name="penomoran_scope" wire:model="v.penomoran.scope" required :error="$err('penomoran.scope')"
                    :options="['global' => 'Satu urutan untuk semua Irban', 'per_irban' => 'Urutan terpisah per Irban']" />
                <x-ui.switch label="Atur ulang nomor urut tiap tahun" name="penomoran_reset_tahunan" wire:model="v.penomoran.reset_tahunan" />
                <x-ui.switch label="Sertakan klasifikasi dalam nomor" name="penomoran_klasifikasi_di_nomor" wire:model="v.penomoran.klasifikasi_di_nomor" />
                {{ $save('penomoran') }}
            </form>
        </x-ui.card>

        <x-ui.card>
            <form novalidate wire:submit="save('penugasan')" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Penugasan &amp; penanda</h2>
                    <p class="mt-1 text-sm text-neutral-600">Cara menghitung lama penugasan dan kapan sebuah SPT ditandai terlambat.</p>
                </div>
                <x-ui.select label="Hitungan hari penugasan" name="penugasan_hitungan_hari" wire:model="v.penugasan.hitungan_hari" required :error="$err('penugasan.hitungan_hari')"
                    :options="['kalender' => 'Hari kalender', 'kerja' => 'Hari kerja']" />
                <x-ui.input label="Batas terlambat (hari)" name="penanda_batas_terlambat_hari" type="number" inputmode="numeric" min="1" wire:model="v.penanda.batas_terlambat_hari" required
                    :error="$err('penanda.batas_terlambat_hari')" helper="SPT berstatus Penyusunan Laporan lebih lama dari ini ditandai Terlambat." />
                {{ $save('penugasan') }}
            </form>
        </x-ui.card>

        <x-ui.card>
            <form novalidate wire:submit="save('rekap')" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Rekap</h2>
                    <p class="mt-1 text-sm text-neutral-600">Menentukan SPT masuk triwulan yang mana.</p>
                </div>
                <x-ui.select label="Dasar triwulan" name="rekap_dasar_triwulan" wire:model="v.rekap.dasar_triwulan" required :error="$err('rekap.dasar_triwulan')"
                    :options="['tanggal_selesai' => 'Tanggal selesai pelaksanaan', 'tanggal_mulai' => 'Tanggal mulai pelaksanaan', 'tanggal_lhp' => 'Tanggal LHP']" />
                {{ $save('rekap') }}
            </form>
        </x-ui.card>

        <x-ui.card>
            <form novalidate wire:submit="save('pkpt')" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">PKPT</h2>
                    <p class="mt-1 text-sm text-neutral-600">Perlakuan SPT terhadap PKPT yang belum disahkan.</p>
                </div>
                <x-ui.switch label="PKPT draf memblokir pengajuan SPT" name="pkpt_draf_memblokir" wire:model="v.pkpt.draf_memblokir"
                    description="Jika aktif, SPT tidak bisa diajukan sebelum PKPT disahkan." />
                {{ $save('pkpt') }}
            </form>
        </x-ui.card>

        <x-ui.card>
            <form novalidate wire:submit="save('publik')" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Halaman publik</h2>
                    <p class="mt-1 text-sm text-neutral-600">Nama pegawai, NIP, kendala, komentar, dan dokumen tidak pernah tampil di publik.</p>
                </div>
                <x-ui.switch label="Tampilkan nomor SPT" name="publik_tampilkan_nomor_spt" wire:model="v.publik.tampilkan_nomor_spt" />
                <x-ui.switch label="Tampilkan nama objek" name="publik_tampilkan_nama_objek" wire:model="v.publik.tampilkan_nama_objek"
                    description="Hanya sampai tingkat kecamatan, dinas, atau badan." />
                {{ $save('publik') }}
            </form>
        </x-ui.card>

        <x-ui.card>
            <form novalidate wire:submit="save('unggahan')" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Unggahan</h2>
                    <p class="mt-1 text-sm text-neutral-600">Batas ukuran berkas yang diunggah pengguna.</p>
                </div>
                <x-ui.input label="Ukuran maksimum (MB)" name="upload_maks_mb" type="number" inputmode="numeric" min="1" wire:model="v.upload.maks_mb" required :error="$err('upload.maks_mb')" />
                {{ $save('unggahan') }}
            </form>
        </x-ui.card>

        <x-ui.card class="lg:col-span-2">
            <form novalidate wire:submit="save('kendala')" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Kategori kendala</h2>
                    <p class="mt-1 text-sm text-neutral-600">Pilihan kategori saat Irban melaporkan kendala. Minimal satu kategori; "Lainnya" selalu ada.</p>
                </div>
                @if ($errors->has('v.kendala.kategori'))
                    <x-ui.alert variant="danger">{{ $errors->first('v.kendala.kategori') }}</x-ui.alert>
                @endif
                <ul role="list" class="space-y-3">
                    @foreach ($v['kendala']['kategori'] as $i => $nama)
                        @php $locked = $nama === 'Lainnya'; $e = $errors->first("v.kendala.kategori.$i"); @endphp
                        <li wire:key="kategori-{{ $kategoriIds[$i] ?? $i }}">
                            <div class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <input type="text" wire:model="v.kendala.kategori.{{ $i }}" aria-label="Nama kategori {{ $i + 1 }}" @readonly($locked)
                                        @if ($e) aria-invalid="true" aria-describedby="kategori-{{ $i }}-error" @endif
                                        class="block h-12 w-full rounded-md bg-white px-3 text-base text-neutral-900 read-only:bg-neutral-50 focus:border-mint-700 focus:ring-2 focus:ring-mint-600 lg:h-10 lg:text-sm {{ $e ? 'border-danger-700' : 'border-border-strong' }}">
                                    @if ($e)
                                        <p id="kategori-{{ $i }}-error" class="mt-1.5 flex items-start gap-1.5 text-sm text-danger-700">
                                            {{ svg('heroicon-m-exclamation-circle', 'size-5 shrink-0') }}<span>{{ $e }}</span>
                                        </p>
                                    @endif
                                </div>
                                @if ($locked)
                                    <span class="inline-flex h-12 shrink-0 items-center gap-1 px-2 text-xs text-neutral-600 lg:h-10">{{ svg('heroicon-m-lock-closed', 'size-4') }}Tetap</span>
                                @else
                                    <button type="button" wire:click="removeKategori({{ $i }})" aria-label="Hapus kategori {{ $nama !== '' ? $nama : $i + 1 }}"
                                        class="inline-flex size-12 shrink-0 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900 lg:size-10">
                                        {{ svg('heroicon-o-trash', 'size-5') }}
                                    </button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <x-ui.button type="button" variant="secondary" icon="plus" wire:click="addKategori">Tambah kategori</x-ui.button>
                    {{ $save('kendala') }}
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
