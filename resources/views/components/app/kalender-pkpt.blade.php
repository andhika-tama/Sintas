{{-- PKPT calendar in the Sistem B grid (spec Tugas B.3): legend, then month blocks × Minggu 1–5 (MingguPkpt, from KalenderPkpt::untuk).
     ≥1280 two blocks per row, 768–1279 one per row (5 columns kept; 2 columns at 1024 leave ~40px cells beside the sidebar), <768 accordion month cards with vertical week sections.
     A card opens the item detail: popover ≥768, x-ui.drawer bottom sheet <768 (Alpine `kalenderPkpt`, resources/js/kalender.js).
     `aksi`: Livewire method of the host page called with the item id for "Ajukan Perubahan Penugasan" (null = read-only).
     `riwayat`: PerubahanPkpt::riwayatItemUntuk() keyed by item id, shown in the detail (x-app.riwayat-perubahan).
     `id`: prefix of every DOM id, so two calendars on one page do not collide.
     No animation (DESIGN §7.5) apart from the drawer's own transition; the accordion and popover switch instantly. --}}
@props(['kalender', 'tahun', 'aksi' => null, 'riwayat' => [], 'id' => 'kalender'])

@php
    $semua = collect($kalender)->flatMap(fn ($b) => collect($b['minggu'])->flatMap(fn ($m) => $m['item']))->unique('id')->values();
    $jenis = $semua->pluck('jenis')->unique('id')->values();
    // Week label Monday–Friday: "6–10 Apr", "30 Mar–3 Apr".
    $label = fn ($m) => $m['mulai']->month === $m['selesai']->month
        ? $m['mulai']->format('j').'–'.$m['selesai']->translatedFormat('j M')
        : $m['mulai']->translatedFormat('j M').'–'.$m['selesai']->translatedFormat('j M');
    $arsir = 'arsir-minggu';
    $bulanIni = collect($kalender)->first(fn ($b) => collect($b['minggu'])->contains('ini', true))['bulan'] ?? 1;
@endphp

<div x-data="kalenderPkpt" data-kalender-pkpt {{ $attributes }}>
    <div class="flex flex-col gap-3">
        <h2 id="judul-{{ $id }}" x-ref="judul" tabindex="-1" class="font-display text-[22px] leading-[30px] font-bold text-neutral-900 focus:outline-none">Kalender {{ $tahun }}</h2>
        <ul role="list" aria-label="Legenda jenis penugasan" class="flex flex-wrap gap-x-4 gap-y-2">
            @foreach ($jenis as $j)
                <li><x-app.type-chip :color="$j['warna']" :label="$j['nama']" /></li>
            @endforeach
        </ul>
    </div>

    {{-- ≥768: month blocks with a 5-column week table. --}}
    <div class="mt-4 grid gap-4 max-md:hidden xl:grid-cols-2">
        @foreach ($kalender as $b)
            <section wire:key="kal-{{ $tahun }}-{{ $b['bulan'] }}" aria-labelledby="{{ $id }}-bulan-g-{{ $b['bulan'] }}" class="min-w-0 rounded-lg border border-neutral-200 bg-white shadow-xs">
                <h3 id="{{ $id }}-bulan-g-{{ $b['bulan'] }}" class="px-4 py-2.5 text-base font-semibold text-neutral-900">{{ $b['nama'] }} {{ $tahun }}</h3>
                <table class="w-full table-fixed border-t border-neutral-200 text-sm">
                    <caption class="sr-only">Penugasan {{ $b['nama'] }} {{ $tahun }} per minggu</caption>
                    <thead>
                        <tr>
                            @foreach ($b['minggu'] as $n => $m)
                                @if ($m['ada'])
                                    <th scope="col" @class(['border-l border-neutral-200 px-2 py-1.5 text-left align-top text-xs font-semibold first:border-l-0', $m['ini'] ? 'bg-mint-50' : 'bg-neutral-50'])>
                                        <span class="block text-neutral-900">Minggu {{ $n }}</span>
                                        <span class="block font-normal text-neutral-500 tabular-nums">{{ $label($m) }}</span>
                                        @if ($m['ini'])<span class="block text-mint-700">Minggu ini</span>@endif
                                    </th>
                                @else
                                    <td aria-hidden="true" data-minggu-kosong class="border-l border-neutral-200 {{ $arsir }}"></td>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-t border-neutral-200">
                            @foreach ($b['minggu'] as $n => $m)
                                @if ($m['ada'])
                                    <td @class(['relative border-l border-neutral-200 p-1 align-top first:border-l-0', 'bg-mint-50' => $m['ini']])>
                                        @if ($m['item'])
                                            <ul role="list" class="space-y-1.5">
                                                @foreach ($m['item'] as $i)
                                                    <li>@include('components.app.partials.kalender-kartu', ['i' => $i])</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <span aria-hidden="true" class="block px-1 text-neutral-500">–</span><span class="sr-only">Tidak ada penugasan</span>
                                        @endif
                                    </td>
                                @else
                                    <td aria-hidden="true" data-minggu-kosong class="border-l border-neutral-200 {{ $arsir }}"></td>
                                @endif
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </section>
        @endforeach
    </div>

    {{-- <768: accordion month cards, the current month (else January) open; weeks stacked vertically. --}}
    <div class="mt-4 space-y-3 md:hidden">
        @foreach ($kalender as $b)
            @php
                $buka = $b['bulan'] === $bulanIni;
                $jumlah = collect($b['minggu'])->flatMap(fn ($m) => $m['item'])->unique('id')->count();
            @endphp
            <section wire:key="kal-m-{{ $tahun }}-{{ $b['bulan'] }}" x-data="{ terbuka: @js($buka) }" class="rounded-lg border border-neutral-200 bg-white px-4 py-2 shadow-xs">
                <h3>
                    <button type="button" x-on:click="terbuka = ! terbuka" aria-expanded="{{ $buka ? 'true' : 'false' }}" x-bind:aria-expanded="terbuka.toString()"
                        aria-controls="{{ $id }}-bulan-m-{{ $b['bulan'] }}" class="flex min-h-11 w-full items-center justify-between gap-3 rounded-md text-left">
                        <span class="text-base font-semibold text-neutral-900 tabular-nums">{{ $b['nama'] }} {{ $tahun }} · {{ $jumlah }} penugasan</span>
                        <span x-bind:class="terbuka && 'rotate-180'" @class(['shrink-0 text-neutral-600', 'rotate-180' => $buka])>{{ svg('heroicon-m-chevron-down', 'size-5', ['aria-hidden' => 'true']) }}</span>
                    </button>
                </h3>
                <div id="{{ $id }}-bulan-m-{{ $b['bulan'] }}" x-show="terbuka" @unless ($buka) style="display: none" @endunless class="space-y-2 pt-2 pb-2">
                    @foreach ($b['minggu'] as $n => $m)
                        @continue(! $m['ada'])
                        <div @class(['rounded-md', 'bg-mint-50 p-2 -mx-2' => $m['ini']])>
                            <h4 class="text-xs font-semibold text-neutral-600 tabular-nums">Minggu {{ $n }} · {{ $label($m) }}@if ($m['ini']) <span class="text-mint-700">· Minggu ini</span>@endif</h4>
                            @if ($m['item'])
                                <ul role="list" class="mt-1.5 space-y-1.5">
                                    @foreach ($m['item'] as $i)
                                        <li>@include('components.app.partials.kalender-kartu', ['i' => $i, 'mobile' => true])</li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-0.5 text-sm text-neutral-500"><span aria-hidden="true">–</span><span class="sr-only">Tidak ada penugasan</span></p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    {{-- Popover ≥768: non-modal (outside click closes it, the page behind stays live), so aria-modal="false"; x-trap.noreturn keeps Tab inside and tutup() returns focus to the card. --}}
    <div x-ref="popover" x-show="aktif !== null && ! drawer" x-cloak x-trap.noreturn="aktif !== null && ! drawer" role="dialog" aria-modal="false"
        x-bind:aria-labelledby="'detail-{{ $id }}-p-' + aktif" x-bind:style="`top:${pos.top}px;left:${pos.left}px`"
        x-on:keydown.escape.window="aktif !== null && ! drawer && tutup()" x-on:resize.window="posisi()" x-on:scroll.window.passive="posisi()"
        x-on:click.outside="luar($event)"
        class="fixed z-50 max-h-[calc(100dvh-5rem)] w-80 overflow-y-auto rounded-lg border border-neutral-200 bg-white p-4 text-sm shadow-md">
        @foreach ($semua as $i)
            <div wire:key="kal-p-{{ $i['id'] }}" x-show="aktif === {{ $i['id'] }}"><x-app.kalender-pkpt-detail :item="$i" :aksi="$aksi" :riwayat="$riwayat[$i['id']] ?? []" v="{{ $id }}-p" /></div>
        @endforeach
        <div class="mt-4 flex justify-end">
            <x-ui.button type="button" size="sm" variant="ghost" x-on:click="tutup()">Tutup</x-ui.button>
        </div>
    </div>

    {{-- Drawer <768 (bottom sheet). --}}
    <x-ui.drawer name="{{ $id }}-detail" title="Detail penugasan" posisi="bawah" x-model="drawerBuka">
        @foreach ($semua as $i)
            <div wire:key="kal-d-{{ $i['id'] }}" x-show="aktif === {{ $i['id'] }}"><x-app.kalender-pkpt-detail :item="$i" :aksi="$aksi" :riwayat="$riwayat[$i['id']] ?? []" v="{{ $id }}-d" /></div>
        @endforeach
    </x-ui.drawer>
</div>
