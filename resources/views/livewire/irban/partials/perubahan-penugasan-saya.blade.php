{{-- "Perubahan Penugasan Saya" (?tampilan=perubahan, 1.2 Task 7): this irban's requests for items of the year's PKPT, newest first.
     Table ≥768, cards below (DESIGN §11). Status = x-app.tag (icon + label); the catatan penolakan under a rejected one. No animation (§7.5). --}}
@use('App\Support\Tanggal')
@php
    $tgl = fn ($d) => $d?->timezone('Asia/Jakarta')->translatedFormat('d F Y');
@endphp

<h2 class="mb-3 font-display text-[22px] leading-[30px] font-bold text-neutral-900">Perubahan Penugasan Saya</h2>
@if ($daftar->isEmpty())
    <x-ui.card>
        <x-ui.empty-state icon="inbox" :title="'Belum ada Perubahan Penugasan untuk PKPT '.$tahun.'.'"
            description="Pilih &quot;Ajukan Perubahan Penugasan&quot; pada item di Kalender atau Tabel." />
    </x-ui.card>
@else
    {{-- ≥768: table --}}
    <x-ui.table :label="'Perubahan Penugasan '.$irbanNama.' '.$tahun" class="max-md:hidden">
        <x-slot:head>
            <x-ui.th>Diajukan</x-ui.th>
            <x-ui.th>Jenis Penugasan</x-ui.th>
            <x-ui.th>Jadwal PKPT</x-ui.th>
            <x-ui.th>Jadwal Baru</x-ui.th>
            <x-ui.th>Alasan</x-ui.th>
            <x-ui.th>Status</x-ui.th>
        </x-slot:head>
        @foreach ($daftar as $p)
            <tr wire:key="p-t-{{ $p->id }}">
                <td class="whitespace-nowrap align-top !py-3">{{ $tgl($p->created_at) }}</td>
                <td class="min-w-40 align-top !py-3"><x-app.type-chip :color="$p->item->jenisPenugasan->warna" :label="$p->item->jenisPenugasan->nama" /></td>
                <td class="whitespace-nowrap align-top !py-3">{{ Tanggal::rentang($p->item->tanggal_mulai, $p->item->tanggal_selesai) }}</td>
                <td class="whitespace-nowrap align-top !py-3 font-medium">{{ Tanggal::rentang($p->tanggal_mulai, $p->tanggal_selesai) }}</td>
                <td class="min-w-56 max-w-96 align-top !py-3"><p class="whitespace-pre-line break-words">{{ $p->alasan }}</p></td>
                <td class="min-w-44 align-top !py-3">@include('livewire.irban.partials.perubahan-penugasan-status')</td>
            </tr>
        @endforeach
    </x-ui.table>

    {{-- <768: cards --}}
    <ul role="list" class="space-y-3 md:hidden" aria-label="Perubahan Penugasan {{ $irbanNama }} {{ $tahun }}">
        @foreach ($daftar as $p)
            <li wire:key="p-c-{{ $p->id }}" class="rounded-lg border border-neutral-200 bg-white p-4 text-sm text-neutral-900 shadow-xs">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <x-app.type-chip :color="$p->item->jenisPenugasan->warna" :label="$p->item->jenisPenugasan->nama" />
                    <p class="text-xs text-neutral-500 tabular-nums">{{ $tgl($p->created_at) }}</p>
                </div>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 tabular-nums">
                    <dt class="text-neutral-600">Jadwal PKPT</dt><dd>{{ Tanggal::rentang($p->item->tanggal_mulai, $p->item->tanggal_selesai) }}</dd>
                    <dt class="text-neutral-600">Jadwal Baru</dt><dd class="font-medium">{{ Tanggal::rentang($p->tanggal_mulai, $p->tanggal_selesai) }}</dd>
                </dl>
                <p class="mt-2 whitespace-pre-line break-words">{{ $p->alasan }}</p>
                <div class="mt-3">@include('livewire.irban.partials.perubahan-penugasan-status')</div>
            </li>
        @endforeach
    </ul>
@endif
