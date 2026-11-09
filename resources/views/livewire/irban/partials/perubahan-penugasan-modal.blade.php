{{-- "Ajukan Perubahan Penugasan" modal (1.2 Task 7, K5/K7; irban.md "Ajukan Revisi Jadwal": current dates read-only + new dates + required reason;
     _shared.md §5, login.md §5). Item summary (Jadwal PKPT) → "Jadwal baru" (Tanggal mulai baru / Tanggal selesai baru) → "Alasan" with a
     character counter. "Diinput oleh" in the footer. No field animation (§7.5). --}}
@use('App\Support\Tanggal')
<x-ui.modal name="perubahan-penugasan" title="Ajukan Perubahan Penugasan" size="md" wire:model="perubahanOpen">
    @if ($perubahanOpen && $aktif)
        @php [$mulai, $selesai] = $rentang[$aktif->id]; @endphp
        <form id="form-perubahan-penugasan" wire:submit="kirimPerubahan" novalidate class="space-y-5">
            @include('auth.partials.error-summary', ['class' => '', 'fields' => [
                'tanggal_mulai' => 'Tanggal mulai baru', 'tanggal_selesai' => 'Tanggal selesai baru', 'alasan' => 'Alasan', 'diinput_oleh' => 'Diinput oleh',
            ]])
            <dl class="grid grid-cols-1 gap-x-4 gap-y-1 rounded-md border border-neutral-200 bg-neutral-25 p-3 text-sm sm:grid-cols-[auto_1fr]">
                <dt class="text-neutral-600">Jenis penugasan</dt>
                <dd class="max-sm:mb-2"><x-app.type-chip :color="$aktif->jenisPenugasan->warna" :label="$aktif->jenisPenugasan->nama" /></dd>
                <dt class="text-neutral-600">Jadwal PKPT</dt>
                <dd class="tabular-nums">{{ Tanggal::rentang($mulai, $selesai) }} · {{ (int) $mulai->diffInDays($selesai) + 1 }} hari</dd>
            </dl>

            <x-ui.date-range legend="Jadwal baru" required :min="$pkpt->tahun.'-01-01'" :max="$pkpt->tahun.'-12-31'"
                label-mulai="Tanggal mulai baru" label-selesai="Tanggal selesai baru"
                :error-mulai="$errors->first('tanggal_mulai')" :error-selesai="$errors->first('tanggal_selesai')" />

            <div>
                <x-ui.textarea label="Alasan" name="alasan" wire:model="alasan" rows="3" maxlength="1000" required
                    helper="Jelaskan mengapa jadwal pelaksanaan perlu bergeser. Dibaca Pimpinan." :error="$errors->first('alasan')" />
                <x-ui.penghitung-karakter model="alasan" :value="$alasan" />
            </div>
            <p class="text-xs text-neutral-500">Jadwal PKPT tidak berubah. Bila disetujui, SPT pada item ini ditandai Tidak Sesuai PKPT dan Kendala wajib diisi.</p>
        </form>
    @endif
    <x-slot:footer>
        {{-- login.md §5: Diinput oleh lives in the modal's footer, full width above the buttons. --}}
        @if ($perubahanOpen)
            <x-app.diinput-oleh :pegawai="$penginput" :value="$diinput_oleh" :ganti="$ganti" :error="$errors->first('diinput_oleh')" drop-up
                wire:model.live="diinput_oleh" class="w-full text-left" />
        @endif
        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', { name: 'perubahan-penugasan' })">Batal</x-ui.button>
        @php $nama = $diinput_oleh ? $penginput->firstWhere('id', $diinput_oleh)?->nama_bergelar : null; @endphp
        <x-ui.button form="form-perubahan-penugasan" icon="paper-airplane" wire:loading.attr="disabled" wire:target="kirimPerubahan" class="max-sm:w-full">
            {{ $nama ? 'Kirim sebagai '.$nama : 'Kirim pengajuan' }}
        </x-ui.button>
    </x-slot:footer>
</x-ui.modal>
