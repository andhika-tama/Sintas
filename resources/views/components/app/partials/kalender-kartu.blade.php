{{-- One item card of x-app.kalender-pkpt ($i = KalenderPkpt item): 4px left bar in the jenis colour, name, "Tambahan · Perubahan ke-N" for added items, keterangan; revised → arrow-path + sr-only. --}}
<button type="button" data-kartu="{{ $i['id'] }}" data-perubahan-pemicu="{{ $i['id'] }}" aria-haspopup="dialog" x-on:click="buka({{ $i['id'] }}, $el)" style="--type: {{ $i['jenis']['warna'] }}"
    @class(['relative block w-full rounded-md border border-l-4 border-neutral-200 border-l-[var(--type)] bg-white px-1.5 py-1.5 text-left break-words hyphens-auto hover:bg-mint-50', 'min-h-11' => $mobile ?? false])>
    <span class="flex items-start justify-between gap-1">
        <span @class(['min-w-0 font-semibold text-neutral-900', ($mobile ?? false) ? 'text-sm' : 'text-xs'])>{{ $i['jenis']['nama'] }}</span>
        @if ($i['direvisi'])
            {{ svg('heroicon-m-arrow-path', 'mt-px size-3.5 shrink-0 text-neutral-600', ['aria-hidden' => 'true']) }}<span class="sr-only">, Jadwal direvisi</span>
        @endif
    </span>
    @if ($i['tambahan_ke'] ?? null)
        <span class="mt-0.5 flex items-center gap-1 text-xs font-medium text-neutral-600">{{ svg('heroicon-m-plus-circle', 'size-3.5 shrink-0', ['aria-hidden' => 'true']) }}Tambahan · Perubahan ke-{{ $i['tambahan_ke'] }}</span>
    @endif
    @if ($i['keterangan'])
        <span class="mt-0.5 block text-xs text-neutral-600">{{ $i['keterangan'] }}</span>
    @endif
</button>
