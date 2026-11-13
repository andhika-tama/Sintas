{{-- Rupiah input (versi 1.3 K5): shows "Rp600.000" while typing, can be emptied, accepts pasted `600000`, `Rp 600.000`, `600.000,00`.
     wire:model sits on the x-modelable wrapper; its `digit` holds pure digits or '' and the visible text is rewritten from it, so a model reset by the server (modal reopened after Batal/Esc) never leaves stale text.
     Parsing rules: resources/js/input-rupiah.js, same as App\Support\Rupiah::baca (dots only as thousands groups).
     Usage: <x-ui.input-rupiah label="Realisasi dana" name="realisasi_dana" id="…" wire:model="realisasi_dana" :value="$realisasi_dana" :error="…" />. Same label/helper/error markup as x-ui.input. --}}
@use('App\Support\Rupiah')
@props(['label', 'name', 'id' => null, 'value' => '', 'required' => false, 'error' => null, 'helper' => null])

@php
    $value = (string) $value;
    $tampil = ctype_digit($value) ? Rupiah::format((int) $value) : $value;
    $ke = $attributes->whereStartsWith('wire:model');
@endphp

<div x-data="inputRupiah" x-modelable="digit" x-effect="selaras(digit)" {{ $ke }} {{ $attributes->only('class') }}>
    <x-ui.input :label="$label" :name="$name.'_tampil'" :id="$id ?? $name" :value="$tampil" :required="$required" :error="$error" :helper="$helper"
        type="text" inputmode="numeric" autocomplete="off" class="tabular-nums" x-on:input="saat($event)" {{ $attributes->except(['class', 'wire:model', 'wire:model.live', 'wire:model.blur', 'wire:model.defer']) }} />
</div>
