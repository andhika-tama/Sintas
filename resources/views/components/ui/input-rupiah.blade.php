{{-- Rupiah input (versi 1.3 K5): shows "Rp600.000" while typing, can be emptied, accepts pasted `600000`, `Rp 600.000`, `600.000,00`.
     The visible text input has no wire:model; a hidden one carries it and only ever holds pure digits or '' (resources/js/input-rupiah.js, same rules as App\Support\Rupiah::baca).
     Usage: <x-ui.input-rupiah label="Realisasi dana" name="realisasi_dana" id="…" wire:model="realisasi_dana" :value="$realisasi_dana" :error="…" />. Same label/helper/error markup as x-ui.input. --}}
@use('App\Support\Rupiah')
@props(['label', 'name', 'id' => null, 'value' => '', 'required' => false, 'error' => null, 'helper' => null])

@php
    $value = (string) $value;
    $tampil = ctype_digit($value) ? Rupiah::format((int) $value) : $value;
    $ke = $attributes->whereStartsWith('wire:model');
@endphp

<div x-data="inputRupiah" {{ $attributes->only('class') }}>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" x-ref="nilai" {{ $ke }}>
    <x-ui.input :label="$label" :name="$name.'_tampil'" :id="$id ?? $name" :value="$tampil" :required="$required" :error="$error" :helper="$helper"
        type="text" inputmode="numeric" autocomplete="off" class="tabular-nums" x-on:input="saat($event)" {{ $attributes->except(['class', 'wire:model', 'wire:model.live', 'wire:model.blur', 'wire:model.defer']) }} />
</div>
