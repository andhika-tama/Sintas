{{-- Dasbor Bagian Umum (design/dashboard/bagian-umum.md "Dashboard (minimal)", _shared.md §1, DESIGN §8). Two stat cards and the compact list
     "5 pengajuan terlama" (oldest diajukan first). No chart, no count-up, no motion (bagian-umum.md Motion, DESIGN §7.5). --}}
<div>
    <x-ui.page-header title="Dasbor Bagian Umum" description="Pengajuan nomor SPT yang menunggu dan nomor yang terbit bulan ini." />

    <section aria-label="Ringkasan pengajuan">
        <ul class="grid gap-3 sm:grid-cols-2 xl:max-w-3xl">
            @foreach ($kartu as $k)
                <li class="flex" wire:key="kartu-{{ $loop->index }}">
                    <x-ui.stat-card class="w-full" :label="$k['label']" :value="$k['nilai']" :href="$k['href']" />
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="terlama-judul" class="mt-6 xl:max-w-3xl">
        <h2 id="terlama-judul" class="font-display text-[22px] leading-[30px] font-bold text-neutral-900">5 pengajuan terlama</h2>
        @if ($terlama->isEmpty())
            <x-ui.card class="mt-3">
                <x-ui.empty-state icon="inbox" title="Semua pengajuan sudah diproses." />
            </x-ui.card>
        @else
            <ul class="mt-3 divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white text-sm text-neutral-900 shadow-xs">
                @foreach ($terlama as $s)
                    @php $umur = (int) $s->diajukan_pada->copy()->startOfDay()->diffInDays($hariIni); @endphp
                    <li wire:key="terlama-{{ $s->id }}">
                        <a href="{{ route('bagian-umum.pengajuan', ['pilih' => $s->id]) }}" wire:navigate
                            class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 hover:bg-mint-50">
                            <span class="font-semibold text-mint-700">{{ $s->irban->nama }}</span>
                            <span class="min-w-0 flex-1 truncate text-neutral-600">{{ $s->namaJenis() ?? 'Tanpa jenis penugasan' }}</span>
                            <span class="whitespace-nowrap text-xs font-medium tabular-nums text-neutral-600">{{ $umur }} hari</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
