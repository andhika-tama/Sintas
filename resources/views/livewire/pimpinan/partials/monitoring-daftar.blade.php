{{-- Monitoring "Per SPT": flat table (≥768) or one card per SPT (<768), no group rows. $daftar = paginator of MonitoringSpt rows. Read-only, no animation (DESIGN §7.5). --}}
@php use App\Support\Tanggal; @endphp
<x-ui.table label="Monitoring SPT {{ $this->tahun }}" class="hidden md:block [&_td]:pr-3! [&_td+td]:pl-3! [&_thead_th]:px-3!">
    <x-slot:head>
        <x-ui.th>Nomor SPT</x-ui.th>
        <x-ui.th>Realisasi Periode</x-ui.th>
        <x-ui.th class="min-w-36 whitespace-normal!">Kesesuaian dengan Jadwal PKPT</x-ui.th>
        <x-ui.th>Status</x-ui.th>
        <x-ui.th class="whitespace-normal!">Permasalahan/Kendala</x-ui.th>
    </x-slot:head>
    @foreach ($daftar as $r)
        <tr wire:key="r-{{ $r['id'] }}-{{ $kunci }}">
            <td class="min-w-48">
                <a href="{{ $r['url'] }}" wire:navigate class="font-medium whitespace-nowrap text-mint-700 underline-offset-2 hover:underline">{{ $r['nomor_lengkap'] }}</a>
                <span class="block text-xs text-neutral-600 [overflow-wrap:anywhere]">{{ $r['jenis']['nama'] ?? 'Tanpa jenis penugasan' }} · {{ $r['irban'] }}</span>
                @if ($r['tanggal_spt'])
                    <span class="block text-xs text-neutral-600">Terbit {{ $r['tanggal_spt']->translatedFormat('d F Y') }}</span>
                @endif
            </td>
            <td class="min-w-32">{{ $r['mulai'] && $r['selesai'] ? Tanggal::rentang($r['mulai'], $r['selesai']) : '–' }}</td>
            @include('livewire.pimpinan.partials.spt-sel')
        </tr>
    @endforeach
</x-ui.table>

<ul role="list" class="space-y-3 md:hidden" aria-label="Monitoring SPT {{ $this->tahun }}">
    @foreach ($daftar as $r)
        <li wire:key="rk-{{ $r['id'] }}-{{ $kunci }}" class="space-y-1.5 rounded-lg border border-neutral-200 bg-white p-4 shadow-xs">
            <span class="flex flex-wrap items-center gap-1.5">
                <x-app.status-badge :status="$r['status']" />
                @if (in_array('terlambat', $r['penanda'], true))
                    <x-app.flag jenis="terlambat" />
                @endif
            </span>
            <p class="text-sm text-neutral-600 tabular-nums">{{ $r['mulai'] && $r['selesai'] ? Tanggal::rentang($r['mulai'], $r['selesai']) : '–' }}</p>
            <a href="{{ $r['url'] }}" wire:navigate class="inline-flex min-h-11 items-center text-sm font-medium [overflow-wrap:anywhere] text-mint-700 underline-offset-2 hover:underline">{{ $r['nomor_lengkap'] }}</a>
            <p class="text-xs text-neutral-600 [overflow-wrap:anywhere]">{{ $r['jenis']['nama'] ?? 'Tanpa jenis penugasan' }} · {{ $r['irban'] }}</p>
            @if ($r['tanggal_spt'])
                <p class="text-xs text-neutral-600">Terbit {{ $r['tanggal_spt']->translatedFormat('d F Y') }}</p>
            @endif
        </li>
    @endforeach
</ul>

<x-ui.pagination :paginator="$daftar" perPageModel="perPage" :perPageOptions="[20]" class="mt-4" />
