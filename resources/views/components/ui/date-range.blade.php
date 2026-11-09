{{-- DESIGN §8 x-ui.date-range: two native type="date" inputs (reliable on Android) + live "Durasi: N hari" (inclusive).
     `mulai` / `selesai` name the host Livewire properties; they are entangled (deferred, sent with the next action), so the duration is computed in the browser.
     `live` syncs each change to the server (for server-side warnings). `durasi` (int|null) replaces the browser's calendar count with the server's
     (e.g. working days per `penugasan.hitungan_hari`); leave it unset to count in the browser. `labelMulai`/`labelSelesai` rename the inputs. No animation (forms, §7.5). --}}
@props(['legend', 'mulai' => 'tanggal_mulai', 'selesai' => 'tanggal_selesai', 'min' => null, 'max' => null, 'required' => false, 'errorMulai' => null, 'errorSelesai' => null,
    'live' => false, 'durasi' => false, 'labelMulai' => 'Tanggal mulai', 'labelSelesai' => 'Tanggal selesai'])

<fieldset x-data="{
        m: $wire.$entangle(@js($mulai), @js((bool) $live)), s: $wire.$entangle(@js($selesai), @js((bool) $live)),
        get durasi() { const d = (Date.parse(this.s) - Date.parse(this.m)) / 864e5 + 1; return Number.isFinite(d) && d >= 1 ? d : null },
    }" {{ $attributes }}>
    <legend class="mb-1.5 text-sm font-medium text-neutral-900">
        {{ $legend }}@if ($required)<span class="text-danger-700" aria-hidden="true"> *</span><span class="sr-only"> (wajib)</span>@endif
    </legend>
    <div class="grid gap-3 sm:grid-cols-2">
        <x-ui.input type="date" :label="$labelMulai" :name="$mulai" x-model="m" :min="$min" :max="$max" :required="$required" :error="$errorMulai" class="tabular-nums" />
        <x-ui.input type="date" :label="$labelSelesai" :name="$selesai" x-model="s" :min="$min" :max="$max" :required="$required" :error="$errorSelesai" class="tabular-nums" />
    </div>
    @if ($durasi === false)
        <p class="mt-1.5 min-h-4 text-xs text-neutral-500 tabular-nums" aria-live="polite" x-text="durasi ? `Durasi: ${durasi} hari` : ''"></p>
    @else
        <p class="mt-1.5 min-h-4 text-xs text-neutral-500 tabular-nums" aria-live="polite">{{ $durasi ? "Durasi: {$durasi} hari" : '' }}</p>
    @endif
</fieldset>
