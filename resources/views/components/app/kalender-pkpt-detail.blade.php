{{-- Detail of one KalenderPkpt item, shown in the x-app.kalender-pkpt popover (≥768) and drawer (<768). `aksi`: Livewire method called with the
     item id for "Ajukan Perubahan Penugasan" (null = no button). `riwayat`: this item's entries for x-app.riwayat-perubahan. `v` keeps ids unique.
     An item added by an approved Perubahan PKPT carries the tag "Tambahan · Perubahan ke-N" (icon + text, never colour alone). --}}
@use('App\Support\Tanggal')
@props(['item', 'aksi' => null, 'riwayat' => [], 'v'])

@php $rentang = Tanggal::rentang($item['mulai'], $item['selesai']); @endphp
<h3 id="detail-{{ $v }}-{{ $item['id'] }}"><x-app.type-chip :color="$item['jenis']['warna']" :label="$item['jenis']['nama']" class="text-base font-semibold" /></h3>
@if ($item['tambahan_ke'] ?? null)
    <x-app.tag icon="plus-circle" variant="info" class="mt-2">Tambahan · Perubahan ke-{{ $item['tambahan_ke'] }}</x-app.tag>
@endif
<dl class="mt-3 space-y-2 text-sm">
    <div><dt class="text-xs text-neutral-500">Jadwal berlaku</dt><dd class="text-neutral-900 tabular-nums">{{ $rentang }}</dd></div>
    @if ($item['semula'])
        <div>
            <dt class="text-xs text-neutral-500">Jadwal semula</dt>
            <dd class="flex items-center gap-1.5 text-neutral-600 tabular-nums">
                {{ svg('heroicon-m-arrow-path', 'size-4 shrink-0', ['aria-hidden' => 'true']) }}<span class="sr-only">Jadwal direvisi, semula </span>{{ Tanggal::rentang(...$item['semula']) }}
            </dd>
        </div>
    @endif
    @if ($item['keterangan'])
        <div><dt class="text-xs text-neutral-500">Keterangan</dt><dd class="text-neutral-900">{{ $item['keterangan'] }}</dd></div>
    @endif
</dl>
<x-app.riwayat-perubahan :riwayat="$riwayat" class="mt-2" />
<x-ui.progress class="mt-4" :nilai="$item['selesai_spt']" :maks="$item['target']" :label="$item['selesai_spt'].' dari '.$item['target'].' SPT selesai'" />
@if ($aksi)
    @php $label = 'Ajukan Perubahan Penugasan: '.$item['jenis']['nama'].', '.$rentang; @endphp
    <div class="mt-4">
        <x-ui.button type="button" size="sm" variant="secondary" wire:click="{{ $aksi }}({{ $item['id'] }})" x-on:click="tutup()" aria-label="{{ $label }}" class="max-md:h-11 max-md:w-full">Ajukan Perubahan Penugasan</x-ui.button>
    </div>
@endif
