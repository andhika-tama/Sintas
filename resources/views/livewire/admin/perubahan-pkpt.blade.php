{{-- Admin page of one Perubahan PKPT (Fase 9.2 Task 7; admin.md PKPT, _shared.md §1 page header, §4 forms, §5 modals/toasts; DESIGN §8).
     Order: rejection alert → jenis/alasan → operasi (before → after) → validation strip (PerubahanPkpt::cek) → SK →
     actions; riwayat in the side column (≥1024, stacked below on smaller screens). Status is the rectangular x-app.tag (pills stay for SPT).
     When an action removes its trigger, `fokus-ke` moves focus to the h1 or the operasi heading. No animation (DESIGN §7.5); modal transitions only. --}}
@php
    $tag = $p->status->tag();
    $tgl = fn ($d) => $d?->timezone('Asia/Jakarta')->translatedFormat('d F Y');
    $waktu = fn ($d) => $d?->timezone('Asia/Jakarta')->translatedFormat('d F Y H.i').' WIB';
    $judulOp = ['tambah' => 'Tambah item', 'ubah' => 'Ubah item', 'hapus' => 'Hapus item'][$aksi] ?? 'Operasi';
    $kejadian = ['dibuat' => 'Dibuat', 'diajukan' => 'Diajukan ke Pimpinan', 'disetujui' => 'Disetujui Pimpinan', 'ditolak' => 'Ditolak Pimpinan',
        'sk_diubah' => 'SK diubah', 'pengingat_dimatikan' => 'Pengingat SK dimatikan', 'pengingat_dinyalakan' => 'Pengingat SK dinyalakan'];
    $pengingatAktif = $p->pengingat_sk_dimatikan_pada === null;
    $aturPengingat = $p->perluSk();
@endphp
<div>
    <x-ui.page-header :title="$judul" title-id="judul-perubahan">
        <x-slot:meta>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-neutral-600">
                <x-app.tag :icon="$tag['icon']" :variant="$tag['variant']">{{ $p->status->label() }}</x-app.tag>
                <span>{{ $p->jenis->label() }}</span>
                <span>Dibuat {{ $tgl($p->created_at) }}</span>
            </div>
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('admin.pkpt.show', ['pkpt' => $pkpt, 'tab' => 'perubahan']) }}" wire:navigate class="inline-flex h-11 items-center justify-center gap-2 rounded-md px-4 text-sm font-medium text-mint-700 hover:bg-mint-50 lg:h-10">
                {{ svg('heroicon-m-arrow-left', 'size-5') }}Kembali ke PKPT {{ $pkpt->tahun }}
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            @if ($p->status === \App\Enums\StatusPerubahan::Ditolak)
                <x-ui.alert variant="danger" title="Ditolak Pimpinan">
                    <p class="mt-1 whitespace-pre-line">{{ $p->komentar_putusan }}</p>
                    <p class="mt-1 text-neutral-600">{{ $p->pemutus?->name ?? 'Pimpinan' }} · {{ $tgl($p->diputuskan_pada) }}. Perbaiki lalu ajukan lagi.</p>
                </x-ui.alert>
            @endif

            <x-ui.card>
                <h2 class="font-display text-[22px] leading-[30px] font-bold text-neutral-900">Jenis dan alasan</h2>
                @if ($bisaUbah)
                    <form wire:submit="simpanForm" novalidate class="mt-4 space-y-4">
                        <fieldset>
                            <legend class="mb-1.5 text-sm font-medium text-neutral-900">Jenis<span class="text-danger-700" aria-hidden="true"> *</span><span class="sr-only"> (wajib)</span></legend>
                            <div class="flex flex-wrap gap-x-6">
                                @foreach (\App\Enums\JenisPerubahan::cases() as $j)
                                    <x-ui.radio name="jenis" :value="$j->value" :label="$j->label()" wire:model="jenis" />
                                @endforeach
                            </div>
                            @error('jenis')<p class="mt-1.5 text-sm text-danger-700">{{ $message }}</p>@enderror
                        </fieldset>
                        <x-ui.textarea name="alasan" label="Alasan" required wire:model="alasan" maxlength="2000" :error="$errors->first('alasan')" helper="Maksimal 2000 karakter." />
                        <div class="flex justify-end">
                            <x-ui.button variant="secondary" icon="check" wire:loading.attr="disabled" wire:target="simpanForm" class="max-sm:w-full">Simpan</x-ui.button>
                        </div>
                    </form>
                @else
                    <dl class="mt-4 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[8rem_1fr]">
                        <dt class="text-neutral-600">Jenis</dt><dd>{{ $p->jenis->label() }}</dd>
                        <dt class="text-neutral-600">Alasan</dt><dd class="whitespace-pre-line break-words">{{ $p->alasan }}</dd>
                    </dl>
                @endif
            </x-ui.card>

            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="judul-operasi" tabindex="-1" class="font-display text-[22px] leading-[30px] font-bold text-neutral-900">Operasi <span class="font-sans text-base font-medium text-neutral-600 tabular-nums">({{ count($operasi) }})</span></h2>
                    @if ($bisaUbah)
                        <div class="flex flex-wrap gap-2 max-sm:w-full max-sm:[&>*]:flex-1">
                            <x-ui.button type="button" size="sm" variant="secondary" icon="plus" wire:click="bukaOperasi('tambah')" class="max-lg:h-11">Tambah item</x-ui.button>
                            <x-ui.button type="button" size="sm" variant="secondary" icon="pencil" wire:click="bukaOperasi('ubah')" class="max-lg:h-11">Ubah item</x-ui.button>
                            <x-ui.button type="button" size="sm" variant="secondary" icon="trash" wire:click="bukaOperasi('hapus')" class="max-lg:h-11">Hapus item</x-ui.button>
                        </div>
                    @endif
                </div>
                <div class="mt-4">
                    @if (count($operasi))
                        <x-app.operasi-perubahan :operasi="$operasi" :bisa-ubah="$bisaUbah" />
                    @else
                        <p class="rounded-md border border-dashed border-neutral-300 p-4 text-sm text-neutral-600">{{ $bisaUbah ? 'Belum ada operasi. Pilih "Tambah item", "Ubah item", atau "Hapus item".' : 'Tidak ada operasi.' }}</p>
                    @endif
                </div>
            </x-ui.card>

            @if ($p->status->terbuka())
                <div id="strip-validasi" aria-live="polite">
                    @if ($cek)
                        <x-ui.alert variant="warning" title="Belum dapat diajukan">
                            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                @foreach ($cek as $m)<li class="break-words">{{ $m }}</li>@endforeach
                            </ul>
                        </x-ui.alert>
                    @else
                        <x-ui.alert variant="success" title="Siap diajukan">
                            <p>Semua operasi lolos pemeriksaan.</p>
                        </x-ui.alert>
                    @endif
                </div>
            @endif

            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-display text-[22px] leading-[30px] font-bold text-neutral-900">SK Perubahan</h2>
                    @if ($p->skLengkap())
                        <x-app.tag icon="check" variant="mint">SK lengkap</x-app.tag>
                    @else
                        <x-app.tag icon="document-arrow-up" variant="warning">SK belum dilengkapi</x-app.tag>
                    @endif
                </div>
                <p class="mt-1 text-sm text-neutral-600">Boleh diisi sejak draf dan dilengkapi setelah disetujui. Setiap perubahan SK tercatat di riwayat.</p>
                <form wire:submit="simpanSk" novalidate class="mt-4 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input name="nomor_sk" label="Nomor SK" wire:model="nomor_sk" maxlength="255" :error="$errors->first('nomor_sk')" />
                        <x-ui.input type="date" name="tanggal_sk" label="Tanggal SK" wire:model="tanggal_sk" :error="$errors->first('tanggal_sk')" class="tabular-nums" />
                    </div>
                    @if ($p->berkas_sk)
                        <div class="flex flex-wrap items-center gap-2 rounded-md border border-neutral-200 p-3 text-sm">
                            {{ svg('heroicon-m-document', 'size-5 shrink-0 text-neutral-600') }}
                            <span class="min-w-0 flex-1 font-medium">Berkas SK tersimpan</span>
                            <a href="{{ route('perubahan-pkpt.sk', $p) }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center rounded-md px-3 font-medium text-mint-700 hover:bg-mint-50 lg:h-8">Lihat<span class="sr-only"> berkas SK (tab baru)</span></a>
                            <a href="{{ route('perubahan-pkpt.sk', ['perubahan' => $p, 'unduh' => 1]) }}" class="inline-flex h-11 items-center rounded-md px-3 font-medium text-mint-700 hover:bg-mint-50 lg:h-8">Unduh<span class="sr-only"> berkas SK</span></a>
                        </div>
                    @endif
                    <x-ui.file-upload name="berkas_sk" :label="$p->berkas_sk ? 'Ganti berkas SK (PDF)' : 'Berkas SK (PDF)'" accept=".pdf,application/pdf" wire:model="berkas_sk"
                        :helper="'PDF, maksimal '.$maksMb.' MB'" :error="$errors->first('berkas_sk')" :filename="$berkas_sk?->getClientOriginalName()"
                        :size="$berkas_sk ? number_format(max(1, round($berkas_sk->getSize() / 1024)), 0, ',', '.').' KB' : null" />
                    <div class="flex justify-end">
                        <x-ui.button icon="check" wire:loading.attr="disabled" wire:target="simpanSk,berkas_sk" class="max-sm:w-full">Simpan SK</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            @if ($bisaUbah || $p->status === \App\Enums\StatusPerubahan::Diajukan || $aturPengingat)
                <x-ui.card>
                    <h2 class="font-display text-[22px] leading-[30px] font-bold text-neutral-900">Tindakan</h2>
                    @if ($bisaUbah)
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse sm:items-start sm:justify-between">
                            <div class="flex flex-col gap-1.5 sm:items-end">
                                <x-ui.button type="button" icon="paper-airplane" wire:click="ajukan" wire:loading.attr="disabled" wire:target="ajukan"
                                    :disabled="(bool) $cek" :aria-describedby="$cek ? 'alasan-ajukan-nonaktif' : null" class="max-sm:w-full">Ajukan ke Pimpinan</x-ui.button>
                                @if ($cek)
                                    <p id="alasan-ajukan-nonaktif" class="text-sm text-neutral-600 sm:text-right">Selesaikan dulu hal pada "Belum dapat diajukan" di atas.</p>
                                @endif
                            </div>
                            @if ($bisaBuang)
                                <x-ui.button type="button" variant="danger-outline" icon="trash" wire:click="$set('buangOpen', true)" class="max-sm:w-full">Buang perubahan</x-ui.button>
                            @endif
                        </div>
                    @elseif ($p->status === \App\Enums\StatusPerubahan::Diajukan)
                        <p class="mt-2 text-sm text-neutral-600">Menunggu keputusan Pimpinan sejak {{ $tgl($p->diajukan_pada) }}. Operasi tidak dapat diubah selama menunggu; SK tetap bisa dilengkapi.</p>
                    @else
                        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-neutral-600">
                                @if ($pengingatAktif)
                                    Pengingat harian SK aktif sampai nomor, tanggal, dan berkas SK lengkap.
                                @else
                                    Pengingat harian SK dimatikan {{ $tgl($p->pengingat_sk_dimatikan_pada) }}.
                                @endif
                            </p>
                            <x-ui.button type="button" variant="secondary" :icon="$pengingatAktif ? 'bell-slash' : 'bell'" wire:click="$set('pengingatOpen', true)" class="shrink-0 max-sm:w-full">
                                {{ $pengingatAktif ? 'Matikan pengingat SK' : 'Nyalakan pengingat SK' }}
                            </x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <aside class="min-w-0" aria-labelledby="judul-riwayat">
            <x-ui.card>
                <h2 id="judul-riwayat" class="font-display text-[22px] leading-[30px] font-bold text-neutral-900">Riwayat</h2>
                <ol role="list" class="mt-4 space-y-4 border-l border-neutral-200 pl-4">
                    @foreach ($p->riwayat as $r)
                        <li wire:key="riwayat-{{ $r->id }}" class="relative">
                            <span aria-hidden="true" class="absolute top-1.5 -left-[21px] size-2.5 rounded-full border-2 border-white bg-mint-700"></span>
                            <p class="text-sm font-semibold text-neutral-900">{{ $kejadian[$r->kejadian] ?? $r->kejadian }}</p>
                            <p class="text-xs text-neutral-600 tabular-nums">{{ $waktu($r->created_at) }} · {{ $r->pengguna?->name ?? 'Sistem' }}</p>
                            @if ($r->catatan)
                                <p class="mt-1 whitespace-pre-line break-words text-sm text-neutral-900">{{ $r->catatan }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </aside>
    </div>

    @if ($bisaUbah)
        <x-ui.modal name="perubahan-operasi" :title="$judulOp" size="lg" wire:model="opOpen">
            {{-- Room for the combobox list (max-h-60) inside the modal's scroll area until an item is picked. --}}
            <form id="form-operasi" wire:submit="simpanOperasi" novalidate @class(['space-y-4', 'min-h-80' => $aksi !== 'tambah' && ! $pkpt_item_id])>
                @error('operasi')<x-ui.alert variant="warning">{{ $message }}</x-ui.alert>@enderror
                @error('aksi')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
                @if ($aksi !== 'tambah')
                    <x-ui.combobox name="pkpt_item_id" label="Item PKPT" :options="$itemOptions" wire:model.live="pkpt_item_id" required
                        placeholder="Cari jenis, irban, atau tanggal" :error="$errors->first('pkpt_item_id')"
                        helper="Hanya item aktif yang belum ada di perubahan ini." />
                @endif
                @if ($aksi === 'hapus' && $pilihanPunyaSpt)
                    <x-ui.alert variant="danger">{{ \App\Services\PerubahanPkpt::K7_HAPUS }}</x-ui.alert>
                @endif
                @if ($aksi === 'tambah' || ($aksi === 'ubah' && $pkpt_item_id))
                    @php $kunci = $aksi === 'ubah' && $pilihanPunyaSpt; @endphp
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.select name="irban_id" label="Irban" required wire:model="irban_id" :options="$irbans" :value="$irban_id" placeholder="Pilih irban"
                            :error="$errors->first('irban_id')" :disabled="$kunci" :helper="$kunci ? 'Terkunci: item sudah dipakai SPT.' : null" />
                        <x-ui.select name="jenis_penugasan_id" label="Jenis penugasan" required wire:model="jenis_penugasan_id" :options="$jenisOptions" :value="$jenis_penugasan_id"
                            placeholder="Pilih jenis" :error="$errors->first('jenis_penugasan_id')" :disabled="$kunci" :helper="$kunci ? 'Terkunci: item sudah dipakai SPT.' : null" />
                    </div>
                    <x-ui.date-range legend="Jadwal" required :min="$pkpt->tahun.'-01-01'" :max="$pkpt->tahun.'-12-31'"
                        :error-mulai="$errors->first('tanggal_mulai')" :error-selesai="$errors->first('tanggal_selesai')" />
                    <div class="grid gap-4 sm:grid-cols-[1fr_10rem]">
                        <x-ui.input name="keterangan" label="Keterangan" wire:model="keterangan" :error="$errors->first('keterangan')" />
                        <x-ui.input type="number" name="target_spt" label="Target SPT" wire:model="target_spt" min="1" inputmode="numeric" :error="$errors->first('target_spt')" class="tabular-nums" />
                    </div>
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'perubahan-operasi' })">Batal</x-ui.button>
                <x-ui.button form="form-operasi" icon="check" wire:loading.attr="disabled" wire:target="simpanOperasi" :disabled="$aksi === 'hapus' && $pilihanPunyaSpt">Simpan operasi</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal name="operasi-hapus" title="Hapus operasi?" size="sm" wire:model="hapusOpen">
            <p>Operasi ini dikeluarkan dari perubahan. Item PKPT-nya tidak berubah.</p>
            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'operasi-hapus' })">Batal</x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="hapusOperasi" wire:loading.attr="disabled" wire:target="hapusOperasi">Hapus</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal name="perubahan-buang" title="Buang perubahan?" size="md" wire:model="buangOpen">
            <p>Perubahan ini beserta semua operasinya dihapus, dan berkas SK yang sudah diunggah ikut dihapus. Tindakan ini tidak dapat dibatalkan.</p>
            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'perubahan-buang' })">Batal</x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="buang" wire:loading.attr="disabled" wire:target="buang">Buang perubahan</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($aturPengingat)
        <x-ui.modal name="perubahan-pengingat" :title="$pengingatAktif ? 'Matikan pengingat SK?' : 'Nyalakan pengingat SK?'" size="md" wire:model="pengingatOpen">
            <p>
                @if ($pengingatAktif)
                    Admin tidak lagi menerima pengingat harian untuk melengkapi SK perubahan ini. Tindakan ini tercatat di riwayat.
                @else
                    Admin kembali menerima pengingat harian sampai SK perubahan ini lengkap. Tindakan ini tercatat di riwayat.
                @endif
            </p>
            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'perubahan-pengingat' })">Batal</x-ui.button>
                <x-ui.button type="button" wire:click="aturPengingat" wire:loading.attr="disabled" wire:target="aturPengingat">{{ $pengingatAktif ? 'Matikan pengingat SK' : 'Nyalakan pengingat SK' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
