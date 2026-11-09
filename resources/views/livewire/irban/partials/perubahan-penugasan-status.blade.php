{{-- Status of one Perubahan Penugasan ($p): x-app.tag, the decision date, and the catatan penolakan when rejected. --}}
@php $tag = $p->status->tag(); @endphp
<x-app.tag :icon="$tag['icon']" :variant="$tag['variant']">{{ $p->status->label() }}</x-app.tag>
@if ($p->diputuskan_pada)
    <p class="mt-1 text-neutral-600 tabular-nums">{{ $p->diputuskan_pada->timezone('Asia/Jakarta')->translatedFormat('d F Y') }}</p>
@endif
@if ($p->status === \App\Enums\StatusPerubahanPenugasan::Ditolak && $p->catatan_putusan)
    <p class="mt-1 text-xs text-neutral-500">Catatan penolakan</p>
    <p class="whitespace-pre-line break-words text-neutral-900">{{ $p->catatan_putusan }}</p>
@endif
