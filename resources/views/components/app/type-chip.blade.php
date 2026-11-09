{{-- DESIGN §8 x-app.type-chip: 10px color dot + neutral-900 text, never colored text. The dot is decorative; the name carries the meaning. No color (jenis "Lainnya", 1.2) = neutral-400 dot. --}}
@props(['color' => null, 'label'])

<span {{ $attributes->class('inline-flex items-center gap-2 text-sm font-medium text-neutral-900 [overflow-wrap:anywhere]') }}>
    <span aria-hidden="true" @class(['size-2.5 shrink-0 rounded-full', 'bg-[var(--type)]' => $color, 'bg-neutral-400' => ! $color]) @if ($color) style="--type: {{ $color }}" @endif></span>
    {{ $label }}
</span>
