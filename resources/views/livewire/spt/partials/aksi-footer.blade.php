{{-- Modal footer start (login.md §5): "Diinput oleh" full width above the buttons, then "Batal" (or `$tutup`). The caller adds its primary button after this. --}}
<x-app.diinput-oleh :pegawai="$penginput" :value="$diinput_oleh" :ganti="$ganti" :error="$errors->first('diinput_oleh')" drop-up
    wire:model.live="diinput_oleh" class="w-full text-left" />
<x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', { name: 'aksi-{{ $modal }}' })">{{ $tutup ?? 'Batal' }}</x-ui.button>
