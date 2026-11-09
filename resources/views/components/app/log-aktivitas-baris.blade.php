{{-- One activity-log row of the compact table (admin.md "Log Aktivitas"): Waktu with a disclosure button, Pengguna, Diinput oleh, Aksi, Objek, IP, plus a detail row
     with the diff `Kolom | Lama | Baru` in the mono font (or one readable line for an impor). Read-only; no motion (DESIGN §7.5). Goes inside <x-ui.table grouped>. --}}
@props(['a'])

@php
    use App\Models\Spt;
    use App\Support\LabelAktivitas;

    $props = $a->properties;
    $lama = (array) $props->get('old', []);
    $baru = (array) $props->get('attributes', []);
    $kolom = array_values(array_unique([...array_keys($lama), ...array_keys($baru)]));
    $impor = $a->log_name === 'impor' && $props->has('jumlah');
    $adaDetail = $impor || $kolom;
    $nilai = fn ($v, $k = '') => $v === null ? '—' : (is_array($v) ? (in_array($k, ['objek', 'anggota'], true) ? ($v === [] ? '—' : implode(', ', $v)) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v));
    $idDetail = 'log-detail-'.$a->id;
    $label = LabelAktivitas::objek($a->subject_type, $a->log_name);
    $adalahSpt = $a->subject_type === (new Spt)->getMorphClass() && $a->subject_id;
    $nomor = ($adalahSpt ? $a->subject?->nomor_lengkap : null) ?? $baru['nomor_lengkap'] ?? $lama['nomor_lengkap'] ?? null;
    $waktu = LabelAktivitas::waktu($a->created_at);
@endphp
<tbody x-data="{ buka: false }" wire:key="log-{{ $a->id }}">
    <tr>
        <td class="whitespace-nowrap">
            <span class="flex items-center gap-1">
                @if ($adaDetail)
                    <button type="button" x-on:click="buka = !buka" x-bind:aria-expanded="buka.toString()" aria-expanded="false" aria-controls="{{ $idDetail }}"
                        aria-label="Tampilkan rincian aktivitas {{ $a->id }}"
                        class="-ml-2 inline-flex size-11 shrink-0 items-center justify-center rounded-md text-mint-700 hover:bg-mint-50 lg:size-8">
                        <span class="inline-flex" x-bind:class="buka || '-rotate-90'">{{ svg('heroicon-m-chevron-down', 'size-5') }}</span>
                    </button>
                @else
                    <span aria-hidden="true" class="-ml-2 size-11 shrink-0 lg:size-8"></span>
                @endif
                <span>{{ $waktu }}</span>
            </span>
        </td>
        <td class="min-w-36">{{ $a->causer?->name ?? 'Sistem' }}</td>
        <td class="min-w-36">{{ $a->diinputOleh?->nama_bergelar ?? '—' }}</td>
        <td class="whitespace-nowrap">{{ LabelAktivitas::aksi($a->event) }}</td>
        <td class="min-w-40">
            {{ $label }}
            @if ($adalahSpt)
                <a href="{{ route('spt.show', $a->subject_id) }}" wire:navigate class="font-medium text-mint-700 underline-offset-2 hover:underline [overflow-wrap:anywhere]">{{ $nomor ?? '#'.$a->subject_id }}</a>
            @elseif ($a->subject_id)
                <span>#{{ $a->subject_id }}</span>
            @endif
        </td>
        <td class="whitespace-nowrap">{{ $a->ip ?? '—' }}</td>
    </tr>
    @if ($adaDetail)
        <tr id="{{ $idDetail }}" x-show="buka" x-cloak class="bg-mint-50! hover:bg-mint-50!">
            <td colspan="6" class="!h-auto bg-mint-50! !py-3">
                <div class="sticky left-0 max-w-[calc(100vw-3rem)] font-mono text-xs">
                    @if ($impor)
                        <p>{{ LabelAktivitas::jenisImpor((string) $props->get('jenis')) }}: {{ number_format((int) $props->get('jumlah'), 0, ',', '.') }} baris dari {{ $props->get('berkas') }}</p>
                    @else
                        <table class="w-full table-fixed border-collapse text-left [&_td]:h-auto! [&_td]:pl-0! [&_td]:pr-3! [&_td]:py-0.5! [&_tbody_tr]:bg-transparent! [&_tbody_tr]:border-0! [&_th]:bg-mint-50! [&_td]:bg-mint-50!">
                            <thead><tr class="text-neutral-600"><th scope="col" class="w-[30%] pr-3 pb-1 font-semibold">Kolom</th><th scope="col" class="w-[30%] pr-3 pb-1 font-semibold">Lama</th><th scope="col" class="pb-1 font-semibold">Baru</th></tr></thead>
                            <tbody>
                                @foreach ($kolom as $k)
                                    <tr class="align-top">
                                        <th scope="row" class="pr-3 pb-1 text-left font-semibold [overflow-wrap:break-word]">{!! str_replace('_', '_<wbr>', e($k)) !!}</th>
                                        <td class="pb-1 break-words">{{ $nilai($lama[$k] ?? null, $k) }}</td>
                                        <td class="pb-1 break-words">{{ $nilai($baru[$k] ?? null, $k) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </td>
        </tr>
    @endif
</tbody>
