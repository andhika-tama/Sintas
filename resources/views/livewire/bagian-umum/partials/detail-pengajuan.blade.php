{{-- Detail pane of the Kotak Masuk ($spt, eager-loaded): header, summary <dl> (GOV.UK summary list, _shared.md §7), personel in order, riwayat,
     sticky action bar for menunggu_nomor. Expects $spt. --}}
@use('App\Support\Tanggal')
@use('App\Actions\Spt\SimpanSpt')
@use('App\Enums\StatusPengajuan')
@php
    $item = $spt->pkptItem;
    $kesesuaian = app(\App\Services\KesesuaianPkpt::class);
    $rentang = $item ? $kesesuaian->rentang($item) : null;
    $sesuai = $item && $spt->tanggal_mulai && $spt->tanggal_selesai ? $kesesuaian->sesuai($item, $spt->tanggal_mulai, $spt->tanggal_selesai) : true;
    $menunggu = $spt->status_pengajuan === StatusPengajuan::MenungguNomor;
    $baris = 'grid gap-1 py-3 sm:grid-cols-[10rem_1fr] sm:gap-4';
@endphp

<div class="flex items-start justify-between gap-3 border-b border-neutral-200 px-4 py-3 lg:px-5">
    <div class="min-w-0">
        <h2 id="detail-judul" class="font-display text-lg font-bold text-neutral-900">
            @if ($spt->nomor_lengkap)
                <span class="tabular-nums">{{ $spt->nomor_lengkap }}</span>
            @else
                <span class="font-sans text-base font-normal italic text-neutral-500">Nomor belum terbit</span>
            @endif
            <span class="sr-only"> · {{ $spt->irban->nama }}</span>
        </h2>
        <div class="mt-1 flex flex-wrap items-center gap-2">
            <x-app.status-badge :status="$spt->status()" />
            @if ($item && ! $sesuai)<x-app.flag jenis="tidak_sesuai_pkpt" />@endif
            @if (! $item)<x-app.flag jenis="non_pkpt" />@endif
            <span class="text-sm text-neutral-600">{{ $spt->irban->nama }} · {{ $spt->klasifikasi->nama }}</span>
        </div>
        <a href="{{ route('spt.show', $spt) }}" wire:navigate class="mt-1 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-mint-700 underline-offset-2 hover:underline lg:min-h-6">
            Buka halaman detail{{ svg('heroicon-m-arrow-right', 'size-4') }}
        </a>
    </div>
    <button type="button" x-on:click="tutup()" aria-label="Tutup rincian" x-show="!xl" x-cloak
        class="-mr-2 inline-flex size-11 shrink-0 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900 max-md:hidden">
        {{ svg('heroicon-o-x-mark', 'size-6') }}
    </button>
    <button type="button" x-on:click="tutup()" class="inline-flex h-11 shrink-0 items-center gap-1 rounded-md px-2 text-sm font-medium text-mint-700 hover:bg-mint-50 md:hidden">
        {{ svg('heroicon-m-arrow-left', 'size-5') }}Daftar
    </button>
</div>

<div class="min-h-0 flex-1 overflow-y-auto px-4 pb-4 text-sm text-neutral-900 lg:px-5">
    @if ($this->konflik)
        <x-ui.alert variant="warning" title="Pengajuan ini sudah diproses oleh pengguna lain." class="mt-4">
            <p class="text-neutral-600">Muat ulang untuk melihat status terbarunya.</p>
            <x-ui.button type="button" variant="secondary" size="sm" icon="arrow-path" wire:click="muatUlang" class="mt-2">Muat ulang</x-ui.button>
        </x-ui.alert>
    @endif

    <dl class="divide-y divide-neutral-200">
        <div class="{{ $baris }}"><dt class="text-neutral-600">Klasifikasi</dt><dd>{{ $spt->klasifikasi->nama }}</dd></div>
        <div class="{{ $baris }}"><dt class="text-neutral-600">Kode Arsip</dt><dd>{{ $spt->kodeArsip ? $spt->kodeArsip->kode.' · '.$spt->kodeArsip->nama : '—' }}</dd></div>
        <div class="{{ $baris }}">
            <dt class="text-neutral-600">Item PKPT</dt>
            <dd>
                @if ($item)
                    <p class="font-medium">{{ $item->jenisPenugasan->nama }} · PKPT {{ $item->pkpt->tahun }}@if ($item->pkpt->isDraf()) (Draf)@endif</p>
                    <x-app.jadwal-berlaku :item="$item" :mulai="$rentang[0]" :selesai="$rentang[1]" />
                    @unless ($sesuai)
                        <p class="mt-1 text-neutral-600">{{ $item->perubahan_penugasan_id ? SimpanSpt::ALASAN_PERUBAHAN_PENUGASAN : 'Di luar jadwal PKPT ('.Tanggal::rentang($rentang[0], $rentang[1]).'). SPT akan ditandai Tidak Sesuai PKPT.' }}</p>
                    @endunless
                @else
                    <p class="text-neutral-600">Non-PKPT (tanpa item PKPT)</p>
                @endif
            </dd>
        </div>
        <div class="{{ $baris }}">
            <dt class="text-neutral-600">Jenis penugasan</dt>
            <dd>@if ($spt->namaJenis())<x-app.type-chip :color="$spt->jenisPenugasan?->warna" :label="$spt->namaJenis()" />@else — @endif</dd>
        </div>
        <div class="{{ $baris }}">
            <dt class="text-neutral-600">Objek</dt>
            <dd class="[overflow-wrap:anywhere]">{{ implode(', ', $spt->namaObjek(true)) ?: '—' }}</dd>
        </div>
        <div class="{{ $baris }}"><dt class="text-neutral-600">Periode diperiksa</dt><dd>{{ $spt->periode_diperiksa ?: '—' }}</dd></div>
        <div class="{{ $baris }}"><dt class="text-neutral-600">Untuk</dt><dd class="whitespace-pre-line">{{ $spt->uraian_untuk ?: '—' }}</dd></div>
        <div class="{{ $baris }}">
            <dt class="text-neutral-600">Tanggal</dt>
            <dd class="tabular-nums">
                @if ($spt->tanggal_mulai && $spt->tanggal_selesai)
                    {{ Tanggal::rentang($spt->tanggal_mulai, $spt->tanggal_selesai) }} · Durasi: {{ $spt->durasi() }} hari
                @else — @endif
            </dd>
        </div>
        <div class="{{ $baris }}"><dt class="text-neutral-600">PPJ</dt><dd>{{ $spt->ppj?->nama_bergelar ?? '—' }}</dd></div>
        <div class="{{ $baris }}">
            <dt class="text-neutral-600">Diajukan</dt>
            <dd class="tabular-nums">{{ $spt->diajukan_pada?->setTimezone('Asia/Jakarta')->translatedFormat('d F Y H.i') }} WIB</dd>
        </div>
        <div class="{{ $baris }}"><dt class="text-neutral-600">Catatan Irban</dt><dd class="whitespace-pre-line">{{ $spt->catatan_bagian_umum ?: '—' }}</dd></div>
        <div class="{{ $baris }}">
            <dt class="text-neutral-600">Lampiran</dt>
            <dd>
                @forelse ($spt->dokumen->where('jenis', 'draf') as $d)
                    <a href="{{ route('spt.dokumen', [$spt, $d]) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center gap-2 font-medium text-mint-700 underline-offset-2 hover:underline lg:min-h-6">
                        {{ svg('heroicon-m-document-text', 'size-5 shrink-0') }}<span class="break-all">{{ $d->nama_asli }}</span>
                        <span class="font-normal text-neutral-600 tabular-nums">({{ number_format($d->ukuran / 1024, 0, ',', '.') }} KB)</span><span class="sr-only"> — Lihat</span>
                    </a>
                @empty — @endforelse
            </dd>
        </div>
    </dl>

    <h3 class="mt-4 text-base font-semibold text-neutral-900">Personel</h3>
    @if ($spt->personel->isEmpty())
        <p class="mt-1 text-neutral-600">Belum ada personel.</p>
    @else
        <div class="mt-2 overflow-x-auto rounded-md border border-neutral-200">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Susunan personel sesuai urutan</caption>
                <thead class="bg-neutral-50 text-neutral-900">
                    <tr><th scope="col" class="w-10 px-3 py-2 text-right font-semibold">No.</th><th scope="col" class="px-3 py-2 font-semibold">Nama</th><th scope="col" class="px-3 py-2 font-semibold">Peran</th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200">
                    @foreach ($spt->personel as $p)
                        <tr>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $p->urutan }}</td>
                            <td class="px-3 py-2">{{ $p->pegawai->nama_bergelar }}@if ($p->pegawai->nip)<span class="block text-xs text-neutral-500 tabular-nums">NIP {{ $p->pegawai->nip }}</span>@endif</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $p->peran->label() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h3 class="mt-4 text-base font-semibold text-neutral-900">Riwayat</h3>
    <ol class="mt-2 space-y-3 border-l-2 border-neutral-200 pl-4">
        @forelse ($spt->riwayat as $r)
            <li>
                <p class="font-medium">{{ \App\Enums\KejadianSpt::label($r->kejadian) }}</p>
                <p class="text-xs text-neutral-500 tabular-nums">{{ $r->created_at->setTimezone('Asia/Jakarta')->translatedFormat('d F Y H.i') }} WIB · {{ $r->olehUser?->name }}</p>
                @if ($r->alasan)<p class="mt-1 whitespace-pre-line text-neutral-600">{{ $r->alasan }}</p>@endif
            </li>
        @empty
            <li class="text-neutral-600">Belum ada riwayat.</li>
        @endforelse
    </ol>
</div>

@if ($menunggu)
    <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-white px-4 py-3 shadow-[0_-1px_3px_rgb(8_43_35/.08)] lg:px-5 xl:rounded-b-lg">
        <x-ui.button type="button" variant="danger" icon="x-circle" wire:click="bukaCatatan('tolak')" aria-keyshortcuts="x" class="max-sm:flex-1">
            Tolak<kbd x-show="pintasan" class="hidden text-xs font-normal opacity-90 lg:inline" aria-hidden="true">X</kbd>
        </x-ui.button>
        <x-ui.button type="button" variant="secondary" icon="arrow-uturn-left" wire:click="bukaCatatan('revisi')" aria-keyshortcuts="r" class="max-sm:flex-1">
            Perlu Revisi<kbd x-show="pintasan" class="hidden text-xs font-normal lg:inline" aria-hidden="true">R</kbd>
        </x-ui.button>
        <x-ui.button type="button" icon="check" wire:click="bukaTerima" aria-keyshortcuts="t" class="max-sm:basis-full">
            Terima<kbd x-show="pintasan" class="hidden text-xs font-normal opacity-90 lg:inline" aria-hidden="true">T</kbd>
        </x-ui.button>
    </div>
@endif
