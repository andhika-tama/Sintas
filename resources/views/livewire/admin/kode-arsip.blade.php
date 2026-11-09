{{-- admin.md master pattern (copy of Jenis Penugasan without the colour picker): table + drawer form + confirm modal. No animation (DESIGN §7.5). --}}
<div>
    <x-ui.page-header title="Kode Arsip" description="Kode arsip pada nomor SPT. Irban memilihnya saat mengajukan nomor.">
        <x-slot:actions>
            <x-ui.button type="button" icon="plus" wire:click="create">Tambah kode arsip</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($rows->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="archive-box" title="Belum ada kode arsip.">
                <x-ui.button type="button" icon="plus" wire:click="create">Tambah kode arsip</x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.table label="Daftar kode arsip">
            <x-slot:head>
                <x-ui.th>Kode</x-ui.th>
                <x-ui.th>Nama</x-ui.th>
                <x-ui.th>Dipakai SPT</x-ui.th>
                <x-ui.th><span class="sr-only">Aksi</span></x-ui.th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr wire:key="kode-{{ $row->id }}">
                    <td class="whitespace-nowrap font-semibold tabular-nums">{{ $row->kode }}</td>
                    <td class="min-w-48">{{ $row->nama }}</td>
                    <td class="whitespace-nowrap tabular-nums">{{ $row->spt_count }}</td>
                    <td class="whitespace-nowrap text-right">
                        <div class="flex items-center justify-end gap-2">
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="edit({{ $row->id }})" aria-label="Ubah kode arsip {{ $row->kode }}" class="max-lg:h-11">Ubah</x-ui.button>
                            <x-ui.dropdown label="Aksi untuk kode arsip {{ $row->kode }}">
                                <button type="button" role="menuitem" wire:click="confirmDelete({{ $row->id }})" class="!text-danger-700">{{ svg('heroicon-m-trash', 'size-5') }}Hapus</button>
                            </x-ui.dropdown>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.drawer name="kode-form" :title="$editingId ? 'Ubah kode arsip' : 'Tambah kode arsip'" size="md" wire:model="open">
        <form novalidate id="kode-form" wire:submit="save" class="space-y-5">
            <x-ui.input label="Kode arsip" name="kode" wire:model="kode" required autocomplete="off" maxlength="20" class="tabular-nums"
                helper="Huruf, angka, dan titik. Maksimal 20 karakter." :error="$errors->first('kode')" />
            <x-ui.input label="Nama kode arsip" name="nama" wire:model="nama" required autocomplete="off" maxlength="100" :error="$errors->first('nama')" />
            @if ($editingId)
                <x-ui.alert variant="info">Nomor SPT yang sudah terbit tidak berubah bila kode atau nama diubah.</x-ui.alert>
            @endif
        </form>
        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'kode-form' })">Batal</x-ui.button>
            <x-ui.button form="kode-form" wire:loading.attr="disabled" wire:target="save">Simpan</x-ui.button>
        </x-slot:footer>
    </x-ui.drawer>

    <x-ui.modal name="kode-hapus" title="Hapus kode arsip?" size="sm" wire:model="deleteOpen">
        <p>Kode arsip <strong class="font-semibold">{{ $deleting?->kode }} · {{ $deleting?->nama }}</strong> akan dihapus permanen.</p>
        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'kode-hapus' })">Batal</x-ui.button>
            <x-ui.button type="button" variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">Hapus</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
