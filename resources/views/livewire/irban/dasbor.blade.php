{{-- Dasbor Irban (design/dashboard/irban.md Dashboard hierarchy, Perlu Tindakan, Layout, Motion; _shared.md §1; DESIGN §3.6, §7.2–7.4, §8, §9, §10).
     Read-only. DOM order follows the hierarchy (greeting, Perlu Tindakan + mini chart, stat cards, SPT Saya) at every width, so tab order matches
     the visual order. Motion: count-up on the cards and the chart draw-in only, both off under prefers-reduced-motion. --}}
@use('App\Support\Tanggal')
@php
    $judul = fn ($s) => $s->nomor_lengkap ?: 'Draf: '.(collect([$s->namaJenis(), implode(', ', $s->namaObjek())])->filter()->implode(' ') ?: \Illuminate\Support\Str::limit($s->uraian_untuk ?? '#'.$s->id, 60));
    $tanggal = fn ($s) => $s->tanggal_mulai && $s->tanggal_selesai ? Tanggal::rentang($s->tanggal_mulai, $s->tanggal_selesai) : '—';
    $ringkasan = 'Grafik batang bertumpuk Status SPT '.$tahun.': '
        .collect($grafik['label'])->map(fn ($l, $i) => $l.' '.$grafik['nilai'][$i])->implode(', ').'.';
@endphp
<div>
    <x-ui.page-header :title="$irban->nama.' · Tahun '.$tahun" description="Dasbor Irban: pengajuan dan pelaksanaan SPT irban ini.">
        <x-slot:actions>
            <a href="{{ route('irban.spt.ajukan') }}" wire:navigate class="inline-flex h-12 items-center justify-center gap-2 rounded-md bg-mint-700 px-5 text-base font-medium text-white hover:bg-mint-800">
                {{ svg('heroicon-m-plus-circle', 'size-5', ['aria-hidden' => 'true']) }}Ajukan Nomor SPT
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 xl:grid-cols-12">
        <section aria-labelledby="tindakan-judul" class="min-w-0 xl:col-span-7">
            <h2 id="tindakan-judul" class="font-display text-[22px] leading-[30px] font-bold text-neutral-900">Perlu Tindakan</h2>
            @if ($tindakan === [])
                <x-ui.card class="mt-4">
                    <div class="flex flex-col items-center px-4 py-8 text-center">
                        {{ svg('heroicon-o-check-badge', 'size-12 text-mint-600', ['aria-hidden' => 'true']) }}
                        <p class="mt-4 text-base font-semibold text-neutral-900">Tidak ada yang perlu ditindaklanjuti. Kerja bagus!</p>
                    </div>
                </x-ui.card>
            @else
                <ul class="mt-4 space-y-3">
                    @foreach ($tindakan as $t)
                        <li wire:key="tindakan-{{ $t['jenis'] }}-{{ $t['spt']->id }}-{{ $loop->index }}"
                            class="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-lg border border-neutral-200 bg-white p-4 text-sm text-neutral-900 shadow-xs">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($t['jenis'] === 'permintaan')
                                        <x-app.tag icon="question-mark-circle" variant="warning">Permintaan Penjelasan</x-app.tag>
                                    @elseif ($t['jenis'] === 'perlu_revisi')
                                        <x-app.status-badge :status="\App\Enums\StatusSpt::PerluRevisi" />
                                    @else
                                        <x-app.flag jenis="terlambat" />
                                    @endif
                                    <span class="text-xs font-medium text-neutral-600">{{ $t['umur'] }} hari</span>
                                </div>
                                <p class="mt-2 break-words font-medium {{ $t['spt']->nomor_lengkap ? 'tabular-nums' : '' }}">{{ $judul($t['spt']) }}</p>
                            </div>
                            <a href="{{ $t['href'] }}" wire:navigate aria-label="Tinjau {{ $judul($t['spt']) }}"
                                class="inline-flex h-11 shrink-0 items-center rounded-md border border-border-strong bg-white px-3 text-sm font-medium text-mint-700 hover:bg-mint-50 max-sm:w-full max-sm:justify-center lg:h-8">Tinjau</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="grafik-status-judul" class="min-w-0 self-start rounded-lg border border-neutral-200 bg-white p-4 shadow-xs lg:p-5 xl:col-span-5">
            <h2 id="grafik-status-judul" class="font-display text-[22px] leading-[30px] font-bold text-neutral-900">Status SPT</h2>
            <p class="mt-1 text-sm text-neutral-600">Tahun {{ $tahun }}</p>
            <div role="img" aria-label="{{ $ringkasan }}" class="mt-4">
                <div wire:ignore x-data="grafikStatus(@js($grafik))" class="relative h-40">
                    <canvas x-ref="kanvas" aria-hidden="true"></canvas>
                </div>
            </div>
            <div x-data="{ tabel: false }" class="mt-3">
                <button type="button" x-on:click="tabel = ! tabel" x-bind:aria-expanded="tabel ? 'true' : 'false'" aria-controls="grafik-status-tabel"
                    class="inline-flex min-h-11 items-center gap-1 rounded-sm text-sm font-medium text-mint-700 hover:underline lg:min-h-8">
                    <span x-text="tabel ? 'Sembunyikan tabel' : 'Tampilkan tabel'">Tampilkan tabel</span>
                    {{ svg('heroicon-m-chevron-down', 'size-4', ['aria-hidden' => 'true']) }}
                </button>
                {{-- Always in the DOM: sr-only until disclosed (DESIGN §9, §10). `relative` keeps sr-only from widening the page. --}}
                <div id="grafik-status-tabel" class="relative" x-bind:class="tabel ? 'mt-2' : 'sr-only'">
                    <table class="w-full border-collapse text-sm tabular-nums text-neutral-900">
                        <caption class="sr-only">Status SPT tahun {{ $tahun }}</caption>
                        <thead class="bg-neutral-50 text-xs font-semibold">
                            <tr>
                                <th scope="col" class="h-9 px-3 text-left">Status</th>
                                <th scope="col" class="h-9 px-3 text-right">Jumlah SPT</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($grafik['label'] as $i => $label)
                                <tr class="border-t border-neutral-200">
                                    <th scope="row" class="h-9 px-3 text-left font-normal">{{ $label }}</th>
                                    <td class="px-3 text-right">{{ number_format($grafik['nilai'][$i], 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <section aria-label="Ringkasan status SPT {{ $tahun }}" class="mt-6">
        <ul class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            @foreach ($kartu as $k)
                <li class="flex" wire:key="kartu-{{ $loop->index }}">
                    <x-ui.stat-card class="w-full" :label="$k['label']" :value="$k['nilai']" :href="$k['href']" :variant="$k['alert'] ? 'alert' : 'default'" :count-up="true" />
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="terbaru-judul" class="mt-6">
        <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
            <h2 id="terbaru-judul" class="font-display text-[22px] leading-[30px] font-bold text-neutral-900">SPT Saya</h2>
            <p class="flex flex-wrap gap-x-4">
                <a href="{{ route('irban.spt') }}" wire:navigate class="inline-flex min-h-11 items-center text-sm font-medium text-mint-700 hover:underline lg:min-h-8">Lihat semua</a>
                <a href="{{ route('irban.rekap') }}" wire:navigate class="inline-flex min-h-11 items-center text-sm font-medium text-mint-700 hover:underline lg:min-h-8">Rekap Saya</a>
            </p>
        </div>
        @if ($terbaru->isEmpty())
            <x-ui.card class="mt-3">
                <x-ui.empty-state icon="document-text" :title="'Belum ada SPT tahun '.$tahun.'.'" description="Ajukan nomor SPT baru; draf dan pengajuan Anda akan tampil di sini." />
            </x-ui.card>
        @else
            <x-ui.table :label="'10 SPT terbaru tahun '.$tahun" class="mt-3 max-md:hidden">
                <x-slot:head>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th>Nomor SPT</x-ui.th>
                    <x-ui.th>Jenis Penugasan</x-ui.th>
                    <x-ui.th>Tanggal</x-ui.th>
                </x-slot:head>
                @foreach ($terbaru as $s)
                    <tr wire:key="t-{{ $s->id }}">
                        <td class="whitespace-nowrap"><x-app.status-badge :status="$s->status()" /></td>
                        <td class="whitespace-nowrap">@include('livewire.irban.partials.nomor-spt')</td>
                        <td>@if ($s->namaJenis())<x-app.type-chip :color="$s->jenisPenugasan?->warna" :label="$s->namaJenis()" />@else<span class="text-neutral-500">—</span>@endif</td>
                        <td class="whitespace-nowrap tabular-nums">{{ $tanggal($s) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            <ul class="mt-3 space-y-3 md:hidden" aria-label="10 SPT terbaru tahun {{ $tahun }}">
                @foreach ($terbaru as $s)
                    <li wire:key="c-{{ $s->id }}" class="rounded-lg border border-neutral-200 bg-white p-4 text-sm text-neutral-900 shadow-xs">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <x-app.status-badge :status="$s->status()" />
                            <span class="tabular-nums text-neutral-600">{{ $tanggal($s) }}</span>
                        </div>
                        <p class="mt-2 break-words">@include('livewire.irban.partials.nomor-spt')</p>
                        @if ($s->namaJenis())<x-app.type-chip class="mt-2" :color="$s->jenisPenugasan?->warna" :label="$s->namaJenis()" />@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
