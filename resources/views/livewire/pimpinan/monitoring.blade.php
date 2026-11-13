{{-- Monitoring SPT (design/dashboard/pimpinan.md "Monitoring table", _shared.md §3 tables + §6 filter bar/export). Comfortable density, one <tbody> per PKPT item
     with a <th scope="rowgroup"> group row; groups start collapsed unless a filter other than Tahun is active. Below 768: one card per group.
     No animation: expand/collapse is instant (DESIGN §7.5). Rows and rules come from MonitoringSpt; this view only formats them. --}}
@php
    use App\Support\Tanggal;
    $terbuka = $filterAktif > 0;
    $periode = fn ($g) => $g['mulai'] ? Tanggal::rentang($g['mulai'], $g['selesai']) : null;
    $spt = $this->tampilan === 'spt';
    $hal = $spt ? $daftar : $grup;
    $chip = ['penanda' => 'Terlambat (semua tahun)', 'penjelasan' => 'Permintaan Penjelasan Terbuka'];
@endphp
<div>
    <x-ui.page-header title="Monitoring SPT" description="Realisasi SPT per item PKPT atau per SPT.">
        <x-slot:actions>
            <x-app.export-buttons />
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 space-y-3">
        <button type="button" x-data x-on:click="$dispatch('open-modal', { name: 'filter-monitoring' })"
            class="inline-flex h-11 items-center gap-2 rounded-md border border-border-strong bg-white px-4 text-sm font-medium text-mint-700 hover:bg-mint-50 md:hidden">
            {{ svg('heroicon-m-funnel', 'size-5') }}Filter{{ $filterAktif ? " ({$filterAktif})" : '' }}
        </button>
        <div class="hidden md:flex md:flex-wrap md:items-end md:gap-3">
            @include('livewire.pimpinan.partials.monitoring-filter', ['p' => 'f'])
            @if ($filterAktif)
                <x-ui.button type="button" variant="ghost" wire:click="aturUlang">Atur ulang</x-ui.button>
            @endif
        </div>
        @if ($this->penanda || $this->penjelasan)
            <ul role="list" aria-label="Filter aktif" class="flex flex-wrap gap-2">
                @foreach ($chip as $k => $teks)
                    @if ($this->{$k})
                        <li class="inline-flex h-11 items-center rounded-md border border-mint-200 bg-mint-50 pl-3 text-sm font-medium text-neutral-900 lg:h-8">
                            {{ $teks }}
                            <button type="button" wire:click="$set('{{ $k }}', '')" aria-label="Hapus filter {{ $teks }}"
                                class="inline-flex size-11 items-center justify-center rounded-md text-neutral-600 hover:text-neutral-900 lg:size-8">{{ svg('heroicon-m-x-mark', 'size-4') }}</button>
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif
    </div>

    <x-ui.drawer name="filter-monitoring" title="Filter" size="sm">
        <div class="space-y-4">
            @include('livewire.pimpinan.partials.monitoring-filter', ['p' => 'd'])
        </div>
        <x-slot:footer>
            @if ($filterAktif)
                <x-ui.button type="button" variant="ghost" wire:click="aturUlang">Atur ulang</x-ui.button>
            @endif
            <x-ui.button type="button" x-on:click="$dispatch('close-modal', { name: 'filter-monitoring' })">Tutup</x-ui.button>
        </x-slot:footer>
    </x-ui.drawer>

    <div wire:loading.delay.class="opacity-60" wire:loading.delay.attr="aria-busy"
        wire:target="cari,tampilan,urut,tahun,tw,irban,status,klasifikasi,kode_arsip,jenis,kesesuaian,penanda,penjelasan,aturUlang,gotoPage,previousPage,nextPage">
        <div class="mb-3 flex flex-wrap items-end gap-3">
            <x-ui.segmented name="tampilan" legend="Tampilan Monitoring" wire:model.live="tampilan" :value="$this->tampilan"
                :options="['pkpt' => 'Per PKPT', 'spt' => 'Per SPT']" :icons="['pkpt' => 'heroicon-m-rectangle-group', 'spt' => 'heroicon-m-list-bullet']" />
            @if ($spt)
                <div class="w-full sm:w-48"><x-ui.select label="Urutkan" name="urut" id="urut" wire:model.live="urut" :options="['tenggat' => 'Tenggat terdekat', 'terbit' => 'Terbit terbaru']" :value="$this->urut" /></div>
            @elseif ($hal->total() > 0)
                <div x-data class="flex flex-wrap gap-2">
                    <x-ui.button type="button" variant="secondary" size="sm" icon="chevron-down" class="max-lg:h-11" x-on:click="$dispatch('monitoring-grup', true)">Buka semua</x-ui.button>
                    <x-ui.button type="button" variant="secondary" size="sm" icon="chevron-up" class="max-lg:h-11" x-on:click="$dispatch('monitoring-grup', false)">Tutup semua</x-ui.button>
                </div>
            @endif
        </div>

        @if ($hal->total() === 0)
            <x-ui.card>
                <x-ui.empty-state icon="table-cells" title="Tidak ada SPT sesuai filter." description="Coba ubah tahun atau atur ulang filter.">
                    @if ($filterAktif)
                        <x-ui.button type="button" variant="secondary" wire:click="aturUlang">Atur ulang filter</x-ui.button>
                    @endif
                </x-ui.empty-state>
            </x-ui.card>
        @elseif ($spt)
            @include('livewire.pimpinan.partials.monitoring-daftar')
        @else
            {{-- px-3 cells (the first cell keeps its 40px indent) and narrower min widths: the expanded table fits beside the open sidebar at 1280 (no sideways scroll there). --}}
            <x-ui.table grouped label="Monitoring SPT {{ $this->tahun }}" class="hidden md:block [&_td]:pr-3! [&_td+td]:pl-3! [&_thead_th]:px-3!">
                <x-slot:head>
                    <x-ui.th>Jenis Penugasan</x-ui.th>
                    <x-ui.th class="whitespace-normal!">Periode Penugasan (PKPT)</x-ui.th>
                    <x-ui.th>Realisasi Periode</x-ui.th>
                    <x-ui.th class="min-w-28 whitespace-normal!">Kesesuaian dengan Jadwal PKPT</x-ui.th>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th numeric class="whitespace-normal!">Realisasi Dana</x-ui.th>
                    <x-ui.th class="whitespace-normal!">Permasalahan/Kendala</x-ui.th>
                </x-slot:head>
                @foreach ($grup as $g)
                    @php $p = $periode($g); @endphp
                    <tbody wire:key="g-{{ $g['item_id'] ?? 'luar' }}-{{ $kunci }}" x-data="{ buka: @js($terbuka) }" x-on:monitoring-grup.window="buka = $event.detail">
                        <tr>
                            <th scope="rowgroup" colspan="7" class="h-12 bg-neutral-50 px-2 text-left text-sm font-semibold text-neutral-900">
                                {{-- sticky + w-max: the label and the SPT count stay in view while the table scrolls sideways (the row spans the whole table width). --}}
                                <div class="sticky left-0 flex w-max items-center gap-x-3 gap-y-1">
                                    <button type="button" x-on:click="buka = !buka" x-bind:aria-expanded="buka.toString()" aria-expanded="{{ $terbuka ? 'true' : 'false' }}"
                                        aria-label="Tampilkan SPT {{ $g['label'] }}{{ $p ? ' '.$p : '' }}"
                                        class="inline-flex size-11 shrink-0 items-center justify-center rounded-md text-mint-700 hover:bg-mint-50 lg:size-8">
                                        <span class="inline-flex" x-bind:class="buka || '-rotate-90'">{{ svg('heroicon-m-chevron-down', 'size-5') }}</span>
                                    </button>
                                    @if ($g['jenis'])
                                        <x-app.type-chip :color="$g['jenis']['warna']" :label="$g['label']" class="font-semibold" />
                                    @else
                                        <span>{{ $g['label'] }}</span>
                                    @endif
                                    @if ($p)
                                        <span class="tabular-nums">{{ $p }}</span>
                                    @endif
                                    @if ($g['irban'])
                                        <span class="font-normal text-neutral-500">{{ $g['irban'] }}</span>
                                    @endif
                                    <span class="shrink-0 pr-2 font-normal text-neutral-500 tabular-nums">{{ number_format(count($g['spt']), 0, ',', '.') }} SPT</span>
                                </div>
                            </th>
                        </tr>
                        @foreach ($g['spt'] as $r)
                            <tr wire:key="s-{{ $r['id'] }}-{{ $kunci }}" x-show="buka" @unless ($terbuka) x-cloak @endunless>
                                <td class="min-w-48 !pl-10 before:absolute before:inset-y-0 before:left-6 before:w-0.5 before:bg-neutral-200">
                                    <a href="{{ $r['url'] }}" wire:navigate class="font-medium [overflow-wrap:anywhere] text-mint-700 underline-offset-2 hover:underline">{{ $r['nomor_lengkap'] }}</a>
                                    <span class="block text-xs text-neutral-600 [overflow-wrap:anywhere]">{{ $r['jenis']['nama'] ?? 'Tanpa jenis penugasan' }} · {{ $r['irban'] }}</span>
                                    <span class="block text-xs text-neutral-600 [overflow-wrap:anywhere]">{{ \App\Services\MonitoringSpt::timPpj($r, true) }}</span>
                                </td>
                                <td class="min-w-24 text-neutral-600">{{ $p ?? '–' }}</td>
                                <td class="min-w-28">{{ $r['mulai'] && $r['selesai'] ? Tanggal::rentang($r['mulai'], $r['selesai']) : '–' }}</td>
                                @include('livewire.pimpinan.partials.spt-sel', ['dana' => true])
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </x-ui.table>

            {{-- Below 768: one card per group with nested mini-rows (status badge + period + number link). --}}
            <ul role="list" class="space-y-3 md:hidden" aria-label="Monitoring SPT {{ $this->tahun }}">
                @foreach ($grup as $g)
                    @php $p = $periode($g); @endphp
                    <li wire:key="k-{{ $g['item_id'] ?? 'luar' }}-{{ $kunci }}" x-data="{ buka: @js($terbuka) }" x-on:monitoring-grup.window="buka = $event.detail"
                        class="rounded-lg border border-neutral-200 bg-white shadow-xs">
                        <button type="button" x-on:click="buka = !buka" x-bind:aria-expanded="buka.toString()" aria-expanded="{{ $terbuka ? 'true' : 'false' }}"
                            class="flex min-h-11 w-full items-start gap-3 rounded-lg p-4 text-left">
                            <span class="min-w-0 flex-1 space-y-1">
                                @if ($g['jenis'])
                                    <x-app.type-chip :color="$g['jenis']['warna']" :label="$g['label']" class="font-semibold" />
                                @else
                                    <span class="block text-sm font-semibold text-neutral-900 [overflow-wrap:anywhere]">{{ $g['label'] }}</span>
                                @endif
                                <span class="block text-sm text-neutral-600 tabular-nums">{{ collect([$p, $g['irban'], count($g['spt']).' SPT'])->filter()->implode(' · ') }}</span>
                            </span>
                            <span class="inline-flex shrink-0 text-mint-700" x-bind:class="buka || '-rotate-90'">{{ svg('heroicon-m-chevron-down', 'size-5') }}</span>
                        </button>
                        <ul role="list" x-show="buka" @unless ($terbuka) x-cloak @endunless class="divide-y divide-neutral-200 border-t border-neutral-200">
                            @foreach ($g['spt'] as $r)
                                <li class="relative space-y-1.5 py-3 pr-4 pl-8 before:absolute before:inset-y-0 before:left-4 before:w-0.5 before:bg-neutral-200">
                                    <span class="flex flex-wrap items-center gap-1.5">
                                        <x-app.status-badge :status="$r['status']" />
                                        @if (in_array('terlambat', $r['penanda'], true))
                                            <x-app.flag jenis="terlambat" />
                                        @endif
                                    </span>
                                    <p class="text-sm text-neutral-600 tabular-nums">{{ $r['mulai'] && $r['selesai'] ? Tanggal::rentang($r['mulai'], $r['selesai']) : '–' }}</p>
                                    <a href="{{ $r['url'] }}" wire:navigate class="inline-flex min-h-11 items-center text-sm font-medium [overflow-wrap:anywhere] text-mint-700 underline-offset-2 hover:underline">{{ $r['nomor_lengkap'] }}</a>
                                    <p class="text-xs text-neutral-600 [overflow-wrap:anywhere]">{{ \App\Services\MonitoringSpt::timPpj($r, true) }}</p>
                                    <p class="text-xs text-neutral-600 tabular-nums">Realisasi Dana: {{ \App\Support\Rupiah::format($r['dana']) }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>

            <x-ui.pagination :paginator="$grup" perPageModel="perPage" :perPageOptions="[20]" class="mt-4" />
        @endif
    </div>
</div>
