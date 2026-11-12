{{-- admin.md "Klasifikasi & Aturan" (1.2.1 Task 6, K7; master pattern of Kode Arsip): table in urutan order + drawer form + confirm modal. No animation (DESIGN §7.5).
     Kode is typed only when adding. Status Aktif/Nonaktif is icon + label (as Pegawai). A klasifikasi used by any SPT keeps a disabled "Hapus" item whose reason
     is written in the menu and linked by aria-describedby. "Warna Rekap" (spec 1.1.4 Task 4) is data, not a design token: swatch and ribbon preview use
     --type/--type-fg (DESIGN §13) and always show the hex as text. Names are user text: [overflow-wrap:anywhere]. --}}
@php $yesNo = fn (bool $v) => $v ? ['check-circle', 'Ya', 'text-success-700'] : ['minus-circle', 'Tidak', 'text-neutral-600']; @endphp
<div>
    <x-ui.page-header title="Klasifikasi & Aturan" title-id="judul-klasifikasi"
        description="Klasifikasi SPT dan aturannya, berurutan sesuai kolom Urutan. Klasifikasi yang sudah dipakai SPT tidak bisa dihapus; nonaktifkan bila tidak dipakai lagi.">
        <x-slot:actions>
            <x-ui.button type="button" icon="plus" wire:click="create" class="max-sm:w-full">Tambah klasifikasi</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table label="Daftar klasifikasi SPT">
        <x-slot:head>
            <x-ui.th>Klasifikasi</x-ui.th>
            <x-ui.th>Urutan</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Wajib item PKPT</x-ui.th>
            <x-ui.th>Syarat selesai</x-ui.th>
            <x-ui.th>Tampil di publik</x-ui.th>
            <x-ui.th>Warna Rekap Tahunan</x-ui.th>
            <x-ui.th>Dipakai SPT</x-ui.th>
            <x-ui.th><span class="sr-only">Aksi</span></x-ui.th>
        </x-slot:head>
        @foreach ($rows as $row)
            <tr wire:key="klasifikasi-{{ $row->id }}">
                <td class="min-w-40 [overflow-wrap:anywhere]">
                    <span class="font-medium">{{ $row->label() }}</span>
                    <span class="block text-xs text-neutral-600 tabular-nums">{{ $row->kode }}</span>
                </td>
                <td class="whitespace-nowrap tabular-nums">{{ $row->urutan }}</td>
                <td class="whitespace-nowrap">
                    @if ($row->aktif)
                        <span class="inline-flex items-center gap-1.5 text-success-700">{{ svg('heroicon-m-check-circle', 'size-5') }}Aktif</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-neutral-600">{{ svg('heroicon-m-minus-circle', 'size-5') }}Nonaktif</span>
                    @endif
                </td>
                @php [$icon, $text, $color] = $yesNo($row->wajib_item_pkpt); @endphp
                <td><span class="inline-flex items-center gap-1.5 {{ $color }}">{{ svg('heroicon-m-'.$icon, 'size-5') }}{{ $text }}</span></td>
                <td class="min-w-56">{{ $row->syarat_selesai->label() }}</td>
                @php [$icon, $text, $color] = $yesNo($row->tampil_publik); @endphp
                <td><span class="inline-flex items-center gap-1.5 {{ $color }}">{{ svg('heroicon-m-'.$icon, 'size-5') }}{{ $text }}</span></td>
                <td class="whitespace-nowrap">
                    @if ($p = \App\Livewire\Admin\Klasifikasi::pratinjau($row->warna))
                        <span class="inline-flex items-center gap-2 tabular-nums"><span aria-hidden="true" class="size-5 shrink-0 rounded-sm border border-neutral-300 bg-[var(--type)]" style="--type: {{ $p['warna'] }}"></span>{{ $p['warna'] }}</span>
                    @else
                        <span class="text-neutral-600">Belum diatur</span>
                    @endif
                </td>
                <td class="whitespace-nowrap tabular-nums">{{ $row->spt_count }}</td>
                <td class="whitespace-nowrap text-right">
                    <div class="flex items-center justify-end gap-2">
                        <x-ui.button type="button" size="sm" variant="secondary" wire:click="edit({{ $row->id }})" aria-label="Ubah klasifikasi {{ $row->nama }}" class="max-lg:h-11">Ubah</x-ui.button>
                        <x-ui.dropdown label="Aksi untuk klasifikasi {{ $row->nama }}">
                            <button type="button" role="menuitem" wire:click="toggleAktif({{ $row->id }})">{{ svg('heroicon-m-power', 'size-5 text-neutral-600') }}{{ $row->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                            @if ($row->spt_count)
                                <button type="button" role="menuitem" aria-disabled="true" aria-describedby="hapus-alasan-{{ $row->id }}" class="!cursor-not-allowed !flex-wrap !gap-x-2 !gap-y-0.5 !py-2 !text-neutral-600 hover:!bg-white">
                                    {{ svg('heroicon-m-trash', 'size-5') }}Hapus
                                    <span id="hapus-alasan-{{ $row->id }}" class="w-full pl-7 text-xs whitespace-normal">{{ \App\Actions\MasterData\DeleteKlasifikasi::DIPAKAI }}</span>
                                </button>
                            @else
                                <button type="button" role="menuitem" wire:click="confirmDelete({{ $row->id }})" class="!text-danger-700">{{ svg('heroicon-m-trash', 'size-5') }}Hapus</button>
                            @endif
                        </x-ui.dropdown>
                    </div>
                </td>
            </tr>
        @endforeach
    </x-ui.table>

    <x-ui.drawer name="klasifikasi-form" :title="$editingId ? 'Ubah klasifikasi' : 'Tambah klasifikasi'" size="md" wire:model="open">
        <form novalidate id="klasifikasi-form" wire:submit="save" class="space-y-5">
            @if ($editingId)
                <div>
                    <p class="text-sm font-medium text-neutral-900">Kode</p>
                    <p class="mt-1 text-sm font-semibold text-neutral-900 tabular-nums [overflow-wrap:anywhere]">{{ $kode }}</p>
                    <p class="mt-1 text-xs text-neutral-600">Kode tidak bisa diubah.</p>
                </div>
            @else
                <x-ui.input label="Kode" name="kode" wire:model="kode" required autocomplete="off" maxlength="30" class="tabular-nums"
                    helper="Huruf besar, angka, dan garis bawah (_), maksimal 30 karakter. Tidak bisa diubah setelah disimpan." :error="$errors->first('kode')" />
            @endif
            <x-ui.input label="Nama" name="nama" wire:model="nama" required autocomplete="off" maxlength="100"
                helper="Tampil di form, rekap, dan nomor SPT bila klasifikasi disertakan dalam nomor. Dicocokkan saat impor." :error="$errors->first('nama')" />
            <x-ui.input label="Urutan" name="urutan" type="number" inputmode="numeric" min="1" max="999" wire:model="urutan" required autocomplete="off" class="tabular-nums"
                helper="1–999. Urutan tampil di form, rekap, dan legenda Rekap Tahunan." :error="$errors->first('urutan')" />
            <x-ui.switch label="Wajib item PKPT" name="wajibItemPkpt" wire:model="wajibItemPkpt" description="SPT klasifikasi ini harus terhubung ke item PKPT." />
            <x-ui.select label="Syarat selesai" name="syaratSelesai" wire:model="syaratSelesai" required placeholder="Pilih syarat selesai" :error="$errors->first('syaratSelesai')"
                :options="\App\Enums\SyaratSelesai::options()" />
            <x-ui.switch label="Tampil di publik" name="tampilPublik" wire:model="tampilPublik" description="Hanya SPT berstatus Selesai yang tampil." />
            <div>
                <x-ui.input label="Warna Rekap Tahunan (hex)" name="warna" wire:model.live.debounce.400ms="warna" autocomplete="off" maxlength="7" placeholder="BDD7EE" class="tabular-nums uppercase"
                    helper="6 digit heksadesimal tanpa #. Mewarnai pita tanggal dan legenda di Excel Rekap Tahunan. Kosongkan bila tidak diwarnai." :error="$errors->first('warna')" />
                <p class="mt-3 mb-2 text-sm font-medium text-neutral-900">Pratinjau pita</p>
                @if ($pratinjau)
                    <span class="flex h-7 w-full max-w-72 items-center justify-center rounded-sm border border-neutral-300 bg-[var(--type)] px-2 text-sm font-semibold text-[var(--type-fg)] tabular-nums"
                        style="--type: {{ $pratinjau['warna'] }}; --type-fg: {{ $pratinjau['teks'] }}">03 s.d 07 Februari 2025</span>
                    <p class="mt-1.5 text-xs text-neutral-600 tabular-nums">{{ $pratinjau['warna'] }} · teks {{ $pratinjau['teks'] === '#FFFFFF' ? 'putih' : 'hitam' }}</p>
                @else
                    <p class="text-sm text-neutral-600">{{ trim($warna) === '' ? 'Tanpa warna: pita tidak diwarnai.' : 'Pratinjau muncul setelah warna valid.' }}</p>
                @endif
            </div>
            @if ($editingId)
                <x-ui.alert variant="info">Nomor SPT yang sudah terbit tidak berubah bila nama diubah.</x-ui.alert>
            @endif
        </form>
        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'klasifikasi-form' })">Batal</x-ui.button>
            <x-ui.button form="klasifikasi-form" wire:loading.attr="disabled" wire:target="save">Simpan</x-ui.button>
        </x-slot:footer>
    </x-ui.drawer>

    <x-ui.modal name="klasifikasi-hapus" title="Hapus klasifikasi?" size="sm" wire:model="deleteOpen">
        <p class="[overflow-wrap:anywhere]">Klasifikasi <strong class="font-semibold">{{ $deleting?->kode }} · {{ $deleting?->nama }}</strong> akan dihapus permanen.</p>
        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'klasifikasi-hapus' })">Batal</x-ui.button>
            <x-ui.button type="button" variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">Hapus</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
