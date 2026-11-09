{{-- Pimpinan: Perubahan Penugasan (1.2 Task 7; pimpinan.md Approval list + Accessibility, _shared.md §1 page header, §3 tables, §5 modals/toasts;
     DESIGN §3.4, §3.6, §7.5, §11). Status tabs (?status=diajukan default | disetujui | ditolak), table ≥1024, cards below (no page scroll at 360).
     Setujui opens a confirm modal, Tolak a modal with a required catatan (pimpinan.md: danger-outline). After a decision the row leaves the
     Diajukan list, so `fokus-ke` moves focus to the h1. Status = rectangular x-app.tag (icon + label). No animation on the table (§7.5). --}}
@use('App\Enums\StatusPerubahanPenugasan')
@use('App\Support\Tanggal')
@php
    $tgl = fn ($d) => $d?->timezone('Asia/Jakarta')->translatedFormat('d F Y');
    $diajukan = $this->status === StatusPerubahanPenugasan::Diajukan->value;
    $konteks = fn ($p) => $p->item->jenisPenugasan->nama.' '.$p->irban->nama;
@endphp
<div>
    <x-ui.page-header title="Perubahan Penugasan" title-id="judul-perubahan-penugasan"
        description="Pengajuan Irban untuk mengubah jadwal pelaksanaan item PKPT. Jadwal PKPT tidak berubah; SPT pada item yang disetujui ditandai Tidak Sesuai PKPT." />

    <x-ui.tabs :tabs="['diajukan' => 'Diajukan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak']" :active="$this->status" model="status"
        label="Filter status" panel="panel-perubahan-penugasan" :badges="['diajukan' => [$jumlahDiajukan, $jumlahDiajukan.' menunggu keputusan']]" />

    <div id="panel-perubahan-penugasan" role="tabpanel" aria-labelledby="panel-perubahan-penugasan-tab-{{ $this->status }}"
        wire:loading.class="opacity-60" wire:target="status,gotoPage,previousPage,nextPage">
        @if ($daftar->isEmpty())
            <x-ui.card>
                <x-ui.empty-state :icon="$diajukan ? 'check-circle' : 'inbox'"
                    :title="$diajukan ? 'Tidak ada Perubahan Penugasan yang menunggu keputusan.' : 'Belum ada Perubahan Penugasan '.mb_strtolower(StatusPerubahanPenugasan::from($this->status)->label()).'.'" />
            </x-ui.card>
        @else
            {{-- ≥1024: table --}}
            <x-ui.table label="Perubahan Penugasan {{ StatusPerubahanPenugasan::from($this->status)->label() }}" class="max-lg:hidden">
                <x-slot:head>
                    <x-ui.th>Irban</x-ui.th>
                    <x-ui.th>Jenis Penugasan</x-ui.th>
                    <x-ui.th>Jadwal PKPT</x-ui.th>
                    <x-ui.th>Jadwal Baru</x-ui.th>
                    <x-ui.th>Alasan</x-ui.th>
                    <x-ui.th>Diajukan</x-ui.th>
                    <x-ui.th>Status</x-ui.th>
                </x-slot:head>
                @foreach ($daftar as $p)
                    @php $tag = $p->status->tag(); @endphp
                    <tr wire:key="t-{{ $p->id }}">
                        <td class="whitespace-nowrap align-top !py-3 font-medium">{{ $p->irban->nama }}</td>
                        <td class="min-w-36 align-top !py-3"><x-app.type-chip :color="$p->item->jenisPenugasan->warna" :label="$p->item->jenisPenugasan->nama" /></td>
                        <td class="min-w-32 align-top !py-3">{{ Tanggal::rentang($p->item->tanggal_mulai, $p->item->tanggal_selesai) }}</td>
                        <td class="min-w-32 align-top !py-3 font-medium">{{ Tanggal::rentang($p->tanggal_mulai, $p->tanggal_selesai) }}</td>
                        <td class="min-w-48 max-w-80 align-top !py-3"><p class="whitespace-pre-line break-words">{{ $p->alasan }}</p></td>
                        <td class="min-w-32 align-top !py-3">
                            <p>{{ $tgl($p->created_at) }}</p>
                            @if ($p->diinputOleh)<p class="text-neutral-600">oleh {{ $p->diinputOleh->nama_bergelar }}</p>@endif
                        </td>
                        <td class="min-w-32 align-top !py-3">
                            <x-app.tag :icon="$tag['icon']" :variant="$tag['variant']">{{ $p->status->label() }}</x-app.tag>
                            @if ($p->diputuskan_pada)<p class="mt-1 text-neutral-600">{{ $tgl($p->diputuskan_pada) }}</p>@endif
                            @if ($p->catatan_putusan)<p class="mt-1 whitespace-pre-line break-words">{{ $p->catatan_putusan }}</p>@endif
                            {{-- Actions sit under the tag (no extra column) so they stay in view at 1280 beside the sidebar. --}}
                            @if ($diajukan)
                                <div class="mt-2 flex flex-col gap-2">
                                    <x-ui.button type="button" size="sm" wire:click="bukaSetujui({{ $p->id }})" aria-label="Setujui Perubahan Penugasan {{ $konteks($p) }}">Setujui</x-ui.button>
                                    <x-ui.button type="button" size="sm" variant="danger-outline" wire:click="bukaTolak({{ $p->id }})" aria-label="Tolak Perubahan Penugasan {{ $konteks($p) }}">Tolak</x-ui.button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            {{-- <1024: cards --}}
            <ul role="list" class="space-y-3 lg:hidden" aria-label="Perubahan Penugasan {{ StatusPerubahanPenugasan::from($this->status)->label() }}">
                @foreach ($daftar as $p)
                    @php $tag = $p->status->tag(); @endphp
                    <li wire:key="c-{{ $p->id }}" class="rounded-lg border border-neutral-200 bg-white p-4 text-sm text-neutral-900 shadow-xs">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <p class="font-semibold">{{ $p->irban->nama }}</p>
                            <x-app.tag :icon="$tag['icon']" :variant="$tag['variant']">{{ $p->status->label() }}</x-app.tag>
                        </div>
                        <x-app.type-chip :color="$p->item->jenisPenugasan->warna" :label="$p->item->jenisPenugasan->nama" class="mt-2" />
                        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 tabular-nums">
                            <dt class="text-neutral-600">Jadwal PKPT</dt><dd>{{ Tanggal::rentang($p->item->tanggal_mulai, $p->item->tanggal_selesai) }}</dd>
                            <dt class="text-neutral-600">Jadwal Baru</dt><dd class="font-medium">{{ Tanggal::rentang($p->tanggal_mulai, $p->tanggal_selesai) }}</dd>
                            <dt class="text-neutral-600">Diajukan</dt><dd>{{ $tgl($p->created_at) }}@if ($p->diinputOleh) oleh {{ $p->diinputOleh->nama_bergelar }}@endif</dd>
                            @if ($p->diputuskan_pada)<dt class="text-neutral-600">Diputuskan</dt><dd>{{ $tgl($p->diputuskan_pada) }}</dd>@endif
                        </dl>
                        <p class="mt-3 text-xs text-neutral-500">Alasan</p>
                        <p class="whitespace-pre-line break-words">{{ $p->alasan }}</p>
                        @if ($p->catatan_putusan)
                            <p class="mt-2 text-xs text-neutral-500">Catatan Pimpinan</p>
                            <p class="whitespace-pre-line break-words">{{ $p->catatan_putusan }}</p>
                        @endif
                        @if ($diajukan)
                            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
                                <x-ui.button type="button" variant="danger-outline" wire:click="bukaTolak({{ $p->id }})" aria-label="Tolak Perubahan Penugasan {{ $konteks($p) }}" class="max-sm:w-full">Tolak</x-ui.button>
                                <x-ui.button type="button" wire:click="bukaSetujui({{ $p->id }})" aria-label="Setujui Perubahan Penugasan {{ $konteks($p) }}" class="max-sm:w-full">Setujui</x-ui.button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            <x-ui.pagination :paginator="$daftar" :per-page-options="[]" class="mt-4" />
        @endif
    </div>

    <x-ui.modal name="perubahan-penugasan-setujui" title="Setujui Perubahan Penugasan?" size="md" wire:model="setujuiOpen">
        @if ($pilih)
            <p>Jadwal baru <span class="font-medium tabular-nums">{{ Tanggal::rentang($pilih->tanggal_mulai, $pilih->tanggal_selesai) }}</span> untuk {{ $konteks($pilih) }} dicatat.
                Jadwal PKPT tetap <span class="tabular-nums">{{ Tanggal::rentang($pilih->item->tanggal_mulai, $pilih->item->tanggal_selesai) }}</span>, dan SPT pada item ini ditandai Tidak Sesuai PKPT dengan Kendala wajib diisi.</p>
        @endif
        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'perubahan-penugasan-setujui' })">Batal</x-ui.button>
            <x-ui.button type="button" icon="check" wire:click="setujui" wire:loading.attr="disabled" wire:target="setujui" class="max-sm:w-full">Setujui</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="perubahan-penugasan-tolak" title="Tolak Perubahan Penugasan?" size="md" wire:model="tolakOpen">
        @if ($pilih)
            <p class="mb-4 text-neutral-600">{{ $konteks($pilih) }} · jadwal baru <span class="tabular-nums">{{ Tanggal::rentang($pilih->tanggal_mulai, $pilih->tanggal_selesai) }}</span></p>
        @endif
        <form id="form-tolak-perubahan-penugasan" wire:submit="tolak" novalidate>
            <x-ui.textarea name="catatan" id="catatan-tolak" label="Catatan penolakan" required wire:model="catatan" rows="4" maxlength="1000"
                helper="Dibaca Irban pengaju." :error="$errors->first('catatan')" />
            <x-ui.penghitung-karakter model="catatan" :value="$catatan" />
        </form>
        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'perubahan-penugasan-tolak' })">Batal</x-ui.button>
            <x-ui.button form="form-tolak-perubahan-penugasan" variant="danger" icon="x-circle" wire:loading.attr="disabled" wire:target="tolak" class="max-sm:w-full">Tolak</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
