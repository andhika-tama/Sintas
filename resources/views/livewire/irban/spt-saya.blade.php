{{-- Irban "SPT Saya" (irban.md, _shared.md §3 tables). Table ≥1024 (scrolls inside its own region), cards below; jenis + uraian share one column so the table fits at 1280. Status = x-app.status-badge.
     No animation on the table or cards (DESIGN §7.5); only the 60% wire:loading dim. --}}
@use('App\Support\Tanggal')
@php
    $tanggal = fn ($s) => $s->tanggal_mulai && $s->tanggal_selesai ? Tanggal::rentang($s->tanggal_mulai, $s->tanggal_selesai) : null;
    $bisaUbah = fn ($s) => auth()->user()->can('update', $s);
@endphp
<div>
    <x-ui.page-header title="SPT Saya" description="Pengajuan dan SPT milik irban ini.">
        <x-slot:actions>
            <div class="w-full sm:w-48"><x-ui.select label="Status" name="status" wire:model.live="status" :options="$statusOptions" placeholder="Semua status" :value="$this->status" /></div>
            <div class="w-full sm:w-32"><x-ui.select label="Tahun" name="tahun" wire:model.live="tahun" :options="$tahunList" :value="$this->tahun" /></div>
            <a href="{{ route('irban.spt.ajukan') }}" wire:navigate class="inline-flex h-11 items-center justify-center gap-2 self-end rounded-md bg-mint-700 px-4 text-sm font-medium text-white hover:bg-mint-800 lg:h-10">
                {{ svg('heroicon-m-plus-circle', 'size-5') }}Ajukan Nomor SPT
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div wire:loading.class="opacity-60" wire:target="status,tahun,gotoPage,previousPage,nextPage,perPage">
        @if ($spt->isEmpty())
            <x-ui.card>
                <x-ui.empty-state icon="document-text" :title="$filtered ? 'Belum ada SPT dengan status ini tahun '.$this->tahun.'.' : 'Belum ada SPT tahun '.$this->tahun.'.'"
                    description="Ajukan nomor SPT baru; draf dan pengajuan Anda akan tampil di sini.">
                    <a href="{{ route('irban.spt.ajukan') }}" wire:navigate class="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-mint-700 px-4 text-sm font-medium text-white hover:bg-mint-800 lg:h-10">
                        {{ svg('heroicon-m-plus-circle', 'size-5') }}Ajukan Nomor SPT
                    </a>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <x-ui.table :label="'SPT Saya '.$this->tahun" class="max-lg:hidden [&_td]:px-3! [&_thead_th]:px-3!">
                <x-slot:head>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th>Nomor SPT</x-ui.th>
                    <x-ui.th>Penugasan</x-ui.th>
                    <x-ui.th>Tanggal</x-ui.th>
                    <x-ui.th>Penanda</x-ui.th>
                    <x-ui.th><span class="sr-only">Aksi</span></x-ui.th>
                </x-slot:head>
                @foreach ($spt as $s)
                    <tr wire:key="t-{{ $s->id }}">
                        <td class="whitespace-nowrap"><x-app.status-badge :status="$s->status()" /></td>
                        <td class="whitespace-nowrap">@include('livewire.irban.partials.nomor-spt')</td>
                        <td class="min-w-48 !py-2">
                            @if ($s->namaJenis())<x-app.type-chip :color="$s->jenisPenugasan?->warna" :label="$s->namaJenis()" />@endif
                            <span class="line-clamp-2 text-neutral-600" title="{{ $s->uraian_untuk }}">{{ $s->uraian_untuk ?: '—' }}</span>
                        </td>
                        <td class="min-w-28 tabular-nums">{{ $tanggal($s) ?? '—' }}</td>
                        <td class="min-w-40 !py-2">
                            @if ($penanda[$s->id])<span class="flex flex-wrap gap-1">@foreach ($penanda[$s->id] as $p)<x-app.flag :jenis="$p" />@endforeach</span>@else<span class="text-neutral-500">—</span>@endif
                        </td>
                        <td class="text-right">
                            @if ($bisaUbah($s))
                                <a href="{{ route('irban.spt.ubah', $s) }}" wire:navigate class="relative inline-flex h-11 items-center gap-1.5 rounded-md px-3 text-sm font-medium text-mint-700 hover:bg-mint-50 lg:h-9 lg:px-2">
                                    {{ svg('heroicon-m-pencil-square', 'size-5') }}Ubah<span class="sr-only"> pengajuan {{ $s->uraian_untuk ? \Illuminate\Support\Str::limit($s->uraian_untuk, 40) : '#'.$s->id }}</span>
                                </a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            <ul class="space-y-3 lg:hidden" aria-label="SPT Saya {{ $this->tahun }}">
                @foreach ($spt as $s)
                    <li wire:key="c-{{ $s->id }}" class="rounded-lg border border-neutral-200 bg-white p-4 text-sm text-neutral-900 shadow-xs">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <x-app.status-badge :status="$s->status()" />
                            <span class="tabular-nums text-neutral-600">{{ $tanggal($s) ?? '—' }}</span>
                        </div>
                        <p class="mt-2">@include('livewire.irban.partials.nomor-spt')</p>
                        @if ($penanda[$s->id])<p class="mt-2 flex flex-wrap gap-1">@foreach ($penanda[$s->id] as $p)<x-app.flag :jenis="$p" />@endforeach</p>@endif
                        @if ($s->namaJenis())<x-app.type-chip class="mt-2" :color="$s->jenisPenugasan?->warna" :label="$s->namaJenis()" />@endif
                        <p class="mt-2 line-clamp-2 text-neutral-600">{{ $s->uraian_untuk ?: '—' }}</p>
                        @if ($bisaUbah($s))
                            <a href="{{ route('irban.spt.ubah', $s) }}" wire:navigate class="mt-3 inline-flex h-11 w-full items-center justify-center gap-1.5 rounded-md border border-border-strong text-sm font-medium text-mint-700 hover:bg-mint-50">
                                {{ svg('heroicon-m-pencil-square', 'size-5') }}Ubah
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>

            <x-ui.pagination :paginator="$spt" class="mt-4" />
        @endif
    </div>
</div>
