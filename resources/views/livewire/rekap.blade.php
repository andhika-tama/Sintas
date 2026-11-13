{{-- Rekap Triwulanan / Rekap Saya (pimpinan.md "Rekap Triwulanan", irban.md "Rekap Saya", _shared.md §3 tables + §6 filter bar/export).
     Compact tables with a bold Total row (neutral-300 top border), numbers right-aligned tabular. Sections come from PembangunRekap::bagian(), shared with the exports.
     Tab "Detail Realisasi" (spec Tugas A): RealisasiItemPkpt rows per irban; SPT cells shared with Monitoring. No animation (DESIGN §7.5). --}}
@php use App\Support\Tanggal; @endphp
<div x-data="{ buka: false }" x-effect="const v = $wire.pilihEkspor; if (buka && ! v) $nextTick(() => $refs.ekspor?.querySelector('[data-ekspor]')?.focus()); buka = v">
    <x-ui.page-header :title="$judul" description="Rencana dan realisasi SPT per triwulan.">
        <x-slot:actions>
            <div x-ref="ekspor" class="contents"><x-app.export-buttons aksi="bukaEkspor" /></div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 1.3: Klasifikasi / Kode Arsip / Jenis sit inline from 768; below that they fold into the "Filter lainnya" disclosure (open on load when one is set).
         wire:ignore.self keeps the Alpine-toggled class across morphs; the selects inside still update. --}}
    @php $jumlahLainnya = count(array_filter([$this->klasifikasi, $this->kode_arsip, $this->jenis], fn ($v) => $v !== '')); @endphp
    <div class="mb-4 flex flex-wrap items-end gap-3" x-data="{ lainnya: @js($saringAktif) }">
        <div class="w-28"><x-ui.select label="Tahun" name="tahun" id="rekap-tahun" wire:model.live="tahun" :options="$tahunList" :value="$this->tahun" /></div>
        <div class="w-40"><x-ui.select label="Triwulan" name="tw" id="rekap-tw" wire:model.live="tw" :options="\App\Services\Ekspor\PembangunRekap::TW" placeholder="Semua TW" :value="$this->tw" /></div>
        @if ($pimpinan)
            <div class="w-full min-w-0 sm:w-48"><x-ui.select label="Irban" name="irban" id="rekap-irban" wire:model.live="irban" :options="$irbanOptions" placeholder="Semua irban" :value="$this->irban" /></div>
        @endif
        <x-ui.button type="button" variant="secondary" icon="adjustments-horizontal" class="md:hidden" aria-controls="rekap-filter-lainnya"
            x-on:click="lainnya = ! lainnya" x-bind:aria-expanded="lainnya.toString()">Filter lainnya{{ $jumlahLainnya ? " ({$jumlahLainnya})" : '' }}</x-ui.button>
        <div id="rekap-filter-lainnya" wire:ignore.self class="flex w-full flex-col gap-3 md:contents {{ $saringAktif ? '' : 'max-md:hidden' }}" x-bind:class="{ 'max-md:hidden': ! lainnya }">
            <div class="min-w-0 md:w-52"><x-ui.select label="Klasifikasi" name="klasifikasi" id="rekap-klasifikasi" wire:model.live="klasifikasi" :options="$saringOpsi['klasifikasi']" placeholder="Semua klasifikasi" :value="$this->klasifikasi" /></div>
            <div class="min-w-0 md:w-56"><x-ui.select label="Kode Arsip" name="kode_arsip" id="rekap-kode_arsip" wire:model.live="kode_arsip" :options="$saringOpsi['kode_arsip']" placeholder="Semua kode arsip" :value="$this->kode_arsip" /></div>
            <div class="min-w-0 md:w-52"><x-ui.select label="Jenis Penugasan" name="jenis" id="rekap-jenis" wire:model.live="jenis" :options="$saringOpsi['jenis']" placeholder="Semua jenis" :value="$this->jenis" /></div>
        </div>
        @if ($filterAktif)
            <x-ui.button type="button" variant="ghost" wire:click="aturUlang">Atur ulang</x-ui.button>
        @endif
    </div>

    @unless ($rekap['pkpt_disahkan'])
        <x-ui.alert variant="warning" class="mb-4">PKPT {{ $rekap['tahun'] }} belum disahkan; rencana belum dapat dihitung.</x-ui.alert>
    @endunless

    <x-ui.tabs :tabs="$pimpinan ? ['irban' => 'Per Irban', 'gabungan' => 'Gabungan', 'detail' => 'Detail Realisasi'] : ['irban' => 'Ringkasan', 'detail' => 'Detail Realisasi']"
        :active="$this->tab" model="tab" label="Tampilan rekap" panel="rekap-panel" />

    <div id="rekap-panel" role="tabpanel" aria-labelledby="rekap-panel-tab-{{ $this->tab }}"
        wire:loading.delay.class="opacity-60" wire:loading.delay.attr="aria-busy" wire:target="tahun,tw,tab,irban,klasifikasi,kode_arsip,jenis,aturUlang"
        class="space-y-8">
        @foreach ($bagian as $i => $s)
            <section wire:key="bagian-{{ $i }}-{{ $this->tab }}" aria-labelledby="rekap-bagian-{{ $i }}">
                <h2 id="rekap-bagian-{{ $i }}" class="mb-3 font-display text-lg font-semibold text-neutral-900">{{ $s['judul'] }}</h2>
                <div class="grid gap-4 {{ count($s['tabel']) > 1 ? 'xl:grid-cols-2' : '' }}">
                    @foreach ($s['tabel'] as $t)
                        <div wire:key="tabel-{{ $i }}-{{ $loop->index }}" class="min-w-0">
                            @if ($t['judul'])
                                <h3 class="mb-2 text-sm font-semibold text-neutral-900">{{ $t['judul'] }}</h3>
                            @endif
                            <x-ui.table density="compact" label="{{ $s['judul'] }}{{ $t['judul'] ? ' '.$t['judul'] : '' }}">
                                <x-slot:head>
                                    <x-ui.th>{{ $i === 0 ? 'Triwulan' : ($i === 1 ? 'Klasifikasi' : 'Jenis Penugasan') }}</x-ui.th>
                                    @foreach ($s['kolom'] as $k)
                                        <x-ui.th numeric>{{ $k }}</x-ui.th>
                                    @endforeach
                                </x-slot:head>
                                @foreach ($t['baris'] as $b)
                                    <tr @class(['font-bold border-t-neutral-300!' => $b['total']])>
                                        <th scope="row" class="bg-white px-4 text-left text-sm {{ $b['total'] ? 'font-bold' : 'font-medium' }}">{{ $i === 0 ? $b['periode'] : $b['label'] }}</th>
                                        @foreach ($b['sel'] as $j => $n)
                                            @if ($b['lebih'] === $j)
                                                {{-- Task 6 1.1.4: above 100% = icon + "*" + sr-only text, explained by the footnote under the section. --}}
                                                <td class="relative text-right whitespace-nowrap">
                                                    <span class="inline-flex items-center gap-1">{{ svg('heroicon-m-exclamation-triangle', 'size-4 shrink-0 text-accent-700') }}{{ number_format($n, 0, ',', '.') }}<span aria-hidden="true">*</span><span class="sr-only">, di atas 100%, lihat catatan</span></span>
                                                </td>
                                            @else
                                                <td class="text-right">{{ is_int($n) ? number_format($n, 0, ',', '.') : $n }}</td>
                                            @endif
                                        @endforeach
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        </div>
                    @endforeach
                </div>
                @if ($s['kode'] === 'rr' && $catatanTarget)
                    <x-ui.alert variant="info" class="mt-3">{{ \App\Services\Ekspor\PembangunRekap::CATATAN_TARGET }}</x-ui.alert>
                @endif
                {{-- 1.3: C1 ("–" cells) or C2, decided by RekapTriwulan::aturanRencana, under each section with a Rencana column. --}}
                @if ($catatan = \App\Services\Ekspor\PembangunRekap::catatanRencana($rekap, $s['kode']))
                    <x-ui.alert variant="info" class="mt-3">{{ $catatan }}</x-ui.alert>
                @endif
                @if (collect($s['tabel'])->pluck('baris')->flatten(1)->contains(fn ($b) => $b['lebih'] !== null))
                    <p class="mt-2 text-sm text-neutral-600">{{ \App\Services\Ekspor\PembangunRekap::CATATAN_LEBIH }}</p>
                @endif
            </section>
        @endforeach
        @foreach ($detail ?? [] as $d)
            @php $judulIrban = $d['irban']['nama']; @endphp
            <section wire:key="detail-{{ $d['irban']['id'] }}" aria-labelledby="detail-irban-{{ $d['irban']['id'] }}">
                <h2 id="detail-irban-{{ $d['irban']['id'] }}" class="mb-3 font-display text-lg font-semibold text-neutral-900">{{ $judulIrban }}</h2>
                @if (! $d['item'])
                    <x-ui.card>
                        <x-ui.empty-state icon="table-cells" title="Tidak ada item PKPT pada periode ini." description="Coba ubah tahun atau triwulan." class="py-8!" />
                    </x-ui.card>
                @else
                    {{-- ≥768: one <tbody> per item; Jenis and Periode PKPT span the item's rows. Hovering any row of an item tints the whole item (the rowspan cells belong to the first row only). Continuation rows un-stick their first cell
                         (x-ui.table makes every row's first cell sticky) so only the Jenis column stays put when scrolling sideways. --}}
                    <x-ui.table grouped density="compact" label="Detail Realisasi {{ $judulIrban }}" class="hidden md:block">
                        <x-slot:head>
                            <x-ui.th>Jenis Penugasan</x-ui.th>
                            <x-ui.th class="whitespace-normal!">Periode Penugasan (PKPT)</x-ui.th>
                            <x-ui.th>Realisasi Periode</x-ui.th>
                            <x-ui.th class="min-w-36 whitespace-normal!">Kesesuaian dengan Jadwal PKPT</x-ui.th>
                            <x-ui.th>Status</x-ui.th>
                            <x-ui.th class="whitespace-normal!">Permasalahan/Kendala</x-ui.th>
                        </x-slot:head>
                        @foreach ($d['item'] as $it)
                            <tbody wire:key="dt-{{ $it['item_id'] }}" class="group/item">
                                @foreach ($it['spt'] ?: [null] as $r)
                                    <tr wire:key="dt-{{ $it['item_id'] }}-{{ $r['id'] ?? 0 }}" class="group-hover/item:bg-neutral-50!">
                                        @if ($loop->first)
                                            <th scope="rowgroup" rowspan="{{ $loop->count }}" class="z-1 min-w-52 bg-white px-4 py-2 text-left align-top font-normal group-hover/item:bg-neutral-50!">
                                                <x-app.type-chip :color="$it['jenis']['warna']" :label="$it['jenis']['nama']" />
                                                @if ($it['keterangan'])
                                                    <span class="mt-0.5 block text-xs text-neutral-600">{{ $it['keterangan'] }}</span>
                                                @endif
                                            </th>
                                            <td rowspan="{{ $loop->count }}" class="relative min-w-40 py-2 align-top whitespace-nowrap group-hover/item:bg-neutral-50!">
                                                {{ Tanggal::rentang($it['mulai'], $it['selesai']) }}
                                                @if ($it['direvisi'])
                                                    <span title="Jadwal direvisi" class="ml-1 inline-flex align-middle text-neutral-600">{{ svg('heroicon-m-arrow-path', 'size-4', ['aria-hidden' => 'true']) }}<span class="sr-only">Jadwal direvisi</span></span>
                                                @endif
                                            </td>
                                        @endif
                                        @if ($r)
                                            <td @class(['min-w-44 py-2', 'static!' => ! $loop->first])>
                                                <span class="block">{{ Tanggal::rentang($r['mulai'], $r['selesai']) }}</span>
                                                <a href="{{ $r['url'] }}" wire:navigate class="inline-flex min-h-6 items-center text-xs font-medium whitespace-nowrap text-mint-700 underline-offset-2 hover:underline">{{ $r['nomor_lengkap'] }}</a>
                                            </td>
                                            @include('livewire.pimpinan.partials.spt-sel')
                                        @else
                                            <td class="text-neutral-600">Belum ada SPT</td>
                                            <td class="text-neutral-600">–</td>
                                            <td class="text-neutral-600">–</td>
                                            <td class="text-neutral-600">–</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </x-ui.table>

                    {{-- Below 768: one card per item with nested SPT mini-rows (Monitoring mobile pattern, always expanded). --}}
                    <ul role="list" class="space-y-3 md:hidden" aria-label="Detail Realisasi {{ $judulIrban }}">
                        @foreach ($d['item'] as $it)
                            <li wire:key="dk-{{ $it['item_id'] }}" class="rounded-lg border border-neutral-200 bg-white shadow-xs">
                                <div class="space-y-1 p-4">
                                    <x-app.type-chip :color="$it['jenis']['warna']" :label="$it['jenis']['nama']" class="font-semibold" />
                                    <p class="relative text-sm text-neutral-600 tabular-nums">
                                        {{ Tanggal::rentang($it['mulai'], $it['selesai']) }}{{ $it['keterangan'] ? ' · '.$it['keterangan'] : '' }}
                                        @if ($it['direvisi'])
                                            <span title="Jadwal direvisi" class="ml-1 inline-flex align-middle">{{ svg('heroicon-m-arrow-path', 'size-4', ['aria-hidden' => 'true']) }}<span class="sr-only">Jadwal direvisi</span></span>
                                        @endif
                                    </p>
                                </div>
                                <ul role="list" class="divide-y divide-neutral-200 border-t border-neutral-200">
                                    @forelse ($it['spt'] as $r)
                                        <li class="relative space-y-1.5 py-3 pr-4 pl-8 before:absolute before:inset-y-0 before:left-4 before:w-0.5 before:bg-neutral-200">
                                            <span class="flex flex-wrap items-center gap-1.5">
                                                <x-app.status-badge :status="$r['status']" />
                                                @if (in_array('terlambat', $r['penanda'], true))
                                                    <x-app.flag jenis="terlambat" />
                                                @endif
                                                @if ($r['kesesuaian'] === 'tidak_sesuai')
                                                    <x-app.flag jenis="tidak_sesuai_pkpt" />
                                                @endif
                                            </span>
                                            <p class="text-sm text-neutral-600 tabular-nums">{{ Tanggal::rentang($r['mulai'], $r['selesai']) }}</p>
                                            @if ($r['kendala'])
                                                <div class="text-sm text-neutral-900">@include('livewire.pimpinan.partials.kendala', ['pre' => 'kendala-kartu'])</div>
                                            @endif
                                            <a href="{{ $r['url'] }}" wire:navigate class="inline-flex min-h-11 items-center text-sm font-medium [overflow-wrap:anywhere] text-mint-700 underline-offset-2 hover:underline">{{ $r['nomor_lengkap'] }}</a>
                                        </li>
                                    @empty
                                        <li class="py-3 pr-4 pl-8 text-sm text-neutral-600">Belum ada SPT</li>
                                    @endforelse
                                </ul>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>

    {{-- Export section picker (spec Task 2 §2.1). Focus: first checkbox on open (autofocus, honoured by x-trap), the export button on close/Esc (the menu item that opened it is gone). --}}
    <div>
        <x-ui.modal name="rekap-ekspor" title="Pilih isi ekspor" wire:model="pilihEkspor">
            <div x-ref="bagian" class="space-y-4">
                <fieldset>
                    <legend class="mb-1 text-sm font-semibold text-neutral-900">Ringkasan</legend>
                    <div class="flex flex-col">
                        @foreach (['rr' => 'Rencana vs Realisasi', 'klasifikasi' => 'Per Klasifikasi', 'jenis' => 'Per Jenis Penugasan'] as $kode => $teks)
                            <x-ui.checkbox :label="$teks" name="bagianEkspor[]" id="ekspor-{{ $kode }}" :value="$kode" wire:model="bagianEkspor" :autofocus="$loop->first" />
                        @endforeach
                    </div>
                </fieldset>
                <fieldset>
                    <legend class="mb-1 text-sm font-semibold text-neutral-900">Detail</legend>
                    <x-ui.checkbox label="Detail Realisasi" name="bagianEkspor[]" id="ekspor-detail" value="detail" wire:model="bagianEkspor" />
                </fieldset>
                @error('bagianEkspor')
                    <p id="ekspor-galat" role="alert" class="flex items-center gap-1.5 text-sm text-danger-700">{{ svg('heroicon-m-exclamation-circle', 'size-5 shrink-0', ['aria-hidden' => 'true']) }}{{ $message }}</p>
                @enderror
            </div>
            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close-modal', {name: 'rekap-ekspor'})">Batal</x-ui.button>
                <x-ui.button type="button" wire:click="unduhEkspor" wire:loading.class="opacity-60" wire:loading.attr="aria-busy" wire:target="unduhEkspor">
                    <span wire:loading.remove wire:target="unduhEkspor">{{ $this->formatEkspor === 'pdf' ? 'Unduh PDF' : 'Unduh Excel' }}</span><span wire:loading wire:target="unduhEkspor">Memproses…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</div>
