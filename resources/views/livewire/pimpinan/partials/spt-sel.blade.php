{{-- SPT cells Kesesuaian · Status (+ Terlambat flag) · Permasalahan/Kendala ("Selengkapnya" toggle), shared by Monitoring and Rekap Detail Realisasi. $r = a MonitoringSpt row. Monitoring passes `dana` to add the Realisasi Dana cell (1.3) between Status and Kendala. --}}
<td class="whitespace-nowrap">
    @if ($r['kesesuaian'] === 'sesuai')
        <span class="inline-flex items-center gap-1 text-neutral-900">{{ svg('heroicon-m-check', 'size-4 text-neutral-600', ['aria-hidden' => 'true']) }}Sesuai</span>
    @elseif ($r['kesesuaian'] === 'tidak_sesuai')
        <x-app.flag jenis="tidak_sesuai_pkpt" />
    @else
        <span class="text-neutral-600">Non-PKPT</span>
    @endif
</td>
<td>
    <span class="flex flex-wrap items-center gap-1.5">
        <x-app.status-badge :status="$r['status']" />
        @if (in_array('terlambat', $r['penanda'], true))
            <x-app.flag jenis="terlambat" />
        @endif
    </span>
</td>
@if ($dana ?? false)
    <td class="text-right whitespace-nowrap tabular-nums">{{ \App\Support\Rupiah::format($r['dana']) }}</td>
@endif
<td class="max-w-72 min-w-40 py-2">
    @if ($r['kendala'])
        @include('livewire.pimpinan.partials.kendala')
    @else
        <span class="text-neutral-600">–</span>
    @endif
</td>
