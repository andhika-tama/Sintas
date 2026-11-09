{{-- Shared SPT detail page (_shared.md §7). Header (h1 nomor + copy, status + flags, type/klasifikasi/irban, actions), perlu-kendala alert,
     ≥1024 an 8/4 grid; below that the two columns dissolve (`contents`) and `order-*` gives the mobile order Ringkasan, Riwayat, Kendala, Personel, Dokumen, Komentar.
     Komentar is the child component livewire:spt.komentar (last in DOM and visually); Log Aktivitas (Admin/Pimpinan only) is a collapsed card. No animation (§7.5) apart from the modal/toast transitions.
     Action modals (Irban `laksanakan` only) are in the partial; `fokus-ke` (heard by the app layout's <main>) moves focus when a trigger is gone after an action. --}}
@use('App\Support\Tanggal')
@use('App\Enums\KejadianSpt')
@php
    $waktu = fn ($t) => $t->setTimezone('Asia/Jakarta')->translatedFormat('d F Y H.i').' WIB';
    $rentangTeks = $rentang ? Tanggal::rentang($rentang[0], $rentang[1]) : null;
    $ukuran = fn (int $b) => $b >= 1024 * 1024 ? number_format($b / 1048576, 1, ',', '.').' MB' : number_format(max(1, round($b / 1024)), 0, ',', '.').' KB';
    $jenisDokumen = ['draf' => 'Lampiran draf', 'final' => 'SPT final', 'cover_lhp' => 'Cover LHP', 'laporan' => 'Laporan/dokumen penyelesaian'];
    $rentangNilai = fn (?array $v) => ! empty($v['tanggal_mulai']) && ! empty($v['tanggal_selesai'])
        ? Tanggal::rentang(\Carbon\CarbonImmutable::parse($v['tanggal_mulai']), \Carbon\CarbonImmutable::parse($v['tanggal_selesai'])) : '—';
    $baris = 'grid gap-1 py-3 sm:grid-cols-[11rem_1fr] sm:gap-4';
    $judulKartu = 'font-display text-[22px] leading-[30px] font-bold text-neutral-900';
    $kendalaLabel = $spt->kendala ? 'Ubah Kendala' : 'Isi Kendala';
@endphp
<div>
    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div class="min-w-0">
            <div class="flex items-start gap-1">
                <h1 id="judul-spt" tabindex="-1" class="min-w-0 break-words font-display text-2xl font-bold text-neutral-900 lg:text-[28px] lg:leading-9">
                    @if ($spt->nomor_lengkap)
                        <span class="tabular-nums">{{ $spt->nomor_lengkap }}</span>
                    @else
                        <span class="font-sans font-normal italic text-neutral-500">Nomor belum terbit</span>
                    @endif
                </h1>
                @if ($spt->nomor_lengkap)
                    <button type="button" x-data x-on:click="navigator.clipboard?.writeText(@js($spt->nomor_lengkap)).then(() => $dispatch('toast', { type: 'success', message: 'Nomor disalin' }))"
                        aria-label="Salin nomor {{ $spt->nomor_lengkap }}" title="Salin nomor"
                        class="inline-flex size-11 shrink-0 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900 lg:size-9">
                        {{ svg('heroicon-m-clipboard', 'size-5') }}
                    </button>
                @endif
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-app.status-badge :status="$spt->status()" />
                @foreach ($penanda as $p)<x-app.flag :jenis="$p" />@endforeach
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-neutral-600">
                @if ($spt->namaJenis())<x-app.type-chip :color="$spt->jenisPenugasan?->warna" :label="$spt->namaJenis()" />@endif
                <span>{{ $spt->klasifikasi->nama }}</span>
                <span>{{ $spt->irban->nama }}</span>
            </div>
        </div>

        @if ($laksanakan || $bisaUbah)
            <div class="flex flex-wrap items-center gap-2 max-sm:[&>*]:w-full xl:max-w-[26rem] xl:shrink-0 xl:justify-end">
                @if ($laksanakan)
                    <x-ui.button type="button" variant="secondary" icon="calendar-days" wire:click="buka('tanggal')">Ubah Tanggal</x-ui.button>
                    <x-ui.button type="button" variant="secondary" icon="arrow-up-tray" wire:click="buka('final')">Unggah SPT Final</x-ui.button>
                    <x-ui.button type="button" variant="secondary" icon="exclamation-circle" id="aksi-kendala" wire:click="buka('kendala')">{{ $kendalaLabel }}</x-ui.button>
                    <x-ui.button type="button" icon="check-circle" wire:click="buka('selesai')">Tandai Selesai</x-ui.button>
                @endif
                @if ($bisaUbah)
                    <a href="{{ route('irban.spt.ubah', $spt) }}" wire:navigate class="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-mint-700 px-4 text-sm font-medium text-white hover:bg-mint-800 lg:h-10">
                        {{ svg('heroicon-m-pencil-square', 'size-5') }}Ubah
                    </a>
                @endif
            </div>
        @endif
    </div>

    @if ($perluKendala)
        <x-ui.alert variant="warning" class="mb-6">
            <p>SPT ini Tidak Sesuai PKPT. Isi kendala untuk menjelaskan penyebabnya.</p>
            @if ($laksanakan)
                <x-ui.button type="button" variant="secondary" size="sm" icon="exclamation-circle" class="mt-2" wire:click="buka('kendala')">Isi Kendala</x-ui.button>
            @endif
        </x-ui.alert>
    @endif

    <div class="flex flex-col gap-6 lg:grid lg:grid-cols-12 lg:items-start">
        <div class="contents lg:col-span-8 lg:flex lg:flex-col lg:gap-6">
            <x-ui.card class="order-1 lg:order-none">
                <h2 class="{{ $judulKartu }}">Ringkasan</h2>
                <dl class="mt-2 divide-y divide-neutral-200 text-sm text-neutral-900">
                    <div class="{{ $baris }}"><dt class="text-neutral-600">Untuk</dt><dd class="whitespace-pre-line">{{ $spt->uraian_untuk ?: '—' }}</dd></div>
                    <div class="{{ $baris }}">
                        <dt class="text-neutral-600">Wilayah/Objek</dt>
                        <dd>{{ implode(', ', $spt->namaObjek(true)) ?: '—' }}</dd>
                    </div>
                    <div class="{{ $baris }}"><dt class="text-neutral-600">Kode Arsip</dt><dd>{{ $spt->kodeArsip ? $spt->kodeArsip->kode.' · '.$spt->kodeArsip->nama : '—' }}</dd></div>
                    <div class="{{ $baris }}"><dt class="text-neutral-600">Periode diperiksa</dt><dd>{{ $spt->periode_diperiksa ?: '—' }}</dd></div>
                    <div class="{{ $baris }}">
                        <dt class="text-neutral-600">Periode pelaksanaan</dt>
                        <dd class="tabular-nums">
                            @if ($spt->tanggal_mulai && $spt->tanggal_selesai)
                                {{ Tanggal::rentang($spt->tanggal_mulai, $spt->tanggal_selesai) }} · {{ $spt->durasi() }} hari{{ $hariKerja ? ' kerja' : '' }}
                            @else — @endif
                        </dd>
                    </div>
                    <div class="{{ $baris }}">
                        <dt class="text-neutral-600">PKPT terkait</dt>
                        <dd>
                            @if ($spt->pkptItem)
                                {{ $spt->pkptItem->jenisPenugasan->nama }} · PKPT {{ $spt->pkptItem->pkpt->tahun }}
                            @else Non-PKPT @endif
                        </dd>
                    </div>
                    <div class="{{ $baris }}">
                        <dt class="text-neutral-600">Kesesuaian</dt>
                        <dd class="tabular-nums">
                            @if ($sesuai === true)
                                Sesuai jadwal PKPT ({{ $rentangTeks }})
                            @elseif ($sesuai === false && in_array('tidak_sesuai_pkpt', $penanda, true))
                                <span class="inline-flex flex-wrap items-center gap-2"><x-app.flag jenis="tidak_sesuai_pkpt" /><span>({{ $rentangTeks }})</span></span>
                            @elseif ($sesuai === false)
                                {{-- No flag before the number is issued (PenandaSpt, PRD 9.3); the irban form words the same warning. --}}
                                Di luar jadwal PKPT ({{ $rentangTeks }})
                            @else
                                <span class="text-neutral-600">Tidak dinilai</span>
                            @endif
                            @if ($sesuai === false && $perubahanPenugasan)
                                <p class="mt-1 text-neutral-600">Jadwal item ini diubah lewat Perubahan Penugasan (disetujui {{ $perubahanPenugasan->diputuskan_pada?->timezone('Asia/Jakarta')->translatedFormat('d F Y') }}).</p>
                            @endif
                        </dd>
                    </div>
                    <div class="{{ $baris }}"><dt class="text-neutral-600">PPJ</dt><dd>{{ $spt->ppj?->nama_bergelar ?? '—' }}</dd></div>
                    <div class="{{ $baris }}"><dt class="text-neutral-600">Tanggal SPT</dt><dd class="tabular-nums">{{ $spt->tanggal_spt?->translatedFormat('d F Y') ?? '—' }}</dd></div>
                    @if ($spt->nomor_lhp)
                        <div class="{{ $baris }}"><dt class="text-neutral-600">Nomor LHP</dt><dd class="tabular-nums">{{ $spt->nomor_lhp }}</dd></div>
                    @endif
                    @if ($spt->tanggal_lhp)
                        <div class="{{ $baris }}"><dt class="text-neutral-600">Tanggal LHP</dt><dd class="tabular-nums">{{ $spt->tanggal_lhp->translatedFormat('d F Y') }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>

            <section class="order-4 lg:order-none" aria-labelledby="judul-personel">
                <h2 id="judul-personel" class="{{ $judulKartu }} mb-3">Personel</h2>
                @if ($spt->personel->isEmpty())
                    <x-ui.card><p class="text-sm text-neutral-600">Belum ada personel.</p></x-ui.card>
                @else
                    <x-ui.table label="Personel sesuai urutan">
                        <x-slot:head>
                            <x-ui.th numeric>No.</x-ui.th>
                            <x-ui.th>Nama</x-ui.th>
                            <x-ui.th>NIP</x-ui.th>
                            <x-ui.th>Peran</x-ui.th>
                        </x-slot:head>
                        @foreach ($spt->personel as $p)
                            <tr wire:key="p-{{ $p->id }}">
                                <td class="w-12 text-right">{{ $loop->iteration }}</td>
                                <td class="min-w-48">{{ $p->pegawai->nama_bergelar }}</td>
                                <td class="whitespace-nowrap tabular-nums">{{ $p->pegawai->nip ?: '—' }}</td>
                                <td class="whitespace-nowrap">{{ $p->peran->label() }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </section>

            <x-ui.card class="order-5 lg:order-none">
                <h2 class="{{ $judulKartu }}">Dokumen</h2>
                @if ($spt->dokumen->isEmpty())
                    <p class="mt-2 text-sm text-neutral-600">Belum ada dokumen.</p>
                @else
                    <ul role="list" class="mt-2 divide-y divide-neutral-200 text-sm">
                        @foreach ($spt->dokumen as $d)
                            @php $url = route('spt.dokumen', [$spt, $d]); @endphp
                            <li wire:key="d-{{ $d->id }}" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex min-w-0 gap-3">
                                    {{ svg('heroicon-o-document-text', 'mt-0.5 size-6 shrink-0 text-mint-700', ['aria-hidden' => 'true']) }}
                                    <div class="min-w-0">
                                        <p class="break-all font-medium text-neutral-900">{{ $d->nama_asli }}</p>
                                        <p class="text-neutral-600">{{ $jenisDokumen[$d->jenis] ?? $d->jenis }} · <span class="tabular-nums">{{ $ukuran((int) $d->ukuran) }}</span></p>
                                        <p class="text-xs text-neutral-500 tabular-nums">
                                            {{ $d->diunggahOleh?->name }}@if ($d->diinputOleh) · Diinput oleh {{ $d->diinputOleh->nama_bergelar }}@endif · {{ $waktu($d->created_at) }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex shrink-0 gap-1 pl-9 sm:pl-0">
                                    <a href="{{ $url }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-1.5 rounded-md px-3 font-medium text-mint-700 hover:bg-mint-50 lg:h-9">
                                        {{ svg('heroicon-m-eye', 'size-5') }}Lihat<span class="sr-only"> {{ $d->nama_asli }} (tab baru)</span>
                                    </a>
                                    <a href="{{ $url }}?unduh=1" class="inline-flex h-11 items-center gap-1.5 rounded-md px-3 font-medium text-mint-700 hover:bg-mint-50 lg:h-9">
                                        {{ svg('heroicon-m-arrow-down-tray', 'size-5') }}Unduh<span class="sr-only"> {{ $d->nama_asli }}</span>
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <div class="order-6 lg:order-none">
                <livewire:spt.komentar :spt="$spt" :key="'komentar-'.$spt->id" />
            </div>
        </div>

        <div class="contents lg:col-span-4 lg:flex lg:flex-col lg:gap-6">
            {{-- Nomor SPPD (versi 1.1.4 Task 2): Bagian Umum edits (UbahNomorSppd), other roles and a Dibatalkan SPT read-only. Same order slot as Riwayat, before it in DOM. --}}
            <x-ui.card class="order-2 lg:order-none">
                <h2 id="judul-sppd" class="{{ $judulKartu }}">Nomor SPPD</h2>
                @if ($ubahSppd)
                    <form wire:submit="simpanSppd" class="mt-3">
                        <x-ui.input label="Nomor SPPD" name="nomor_sppd" id="nomor-sppd" wire:model.live.debounce.500ms="nomor_sppd" maxlength="100" autocomplete="off"
                            class="tabular-nums" aria-describedby="nomor-sppd-peringatan" helper="Opsional. Kosongkan lalu simpan untuk menghapus." :error="$errors->first('nomor_sppd')" />
                        <x-app.peringatan-sppd id="nomor-sppd-peringatan" :duplikat="$duplikatSppd" :tahun="$tahunSppd" />
                        <x-ui.button type="submit" variant="secondary" icon="check" class="mt-3 max-sm:w-full" wire:loading.attr="disabled" wire:target="simpanSppd">Simpan Nomor SPPD</x-ui.button>
                    </form>
                @elseif ($spt->nomor_sppd)
                    <p class="mt-2 text-sm text-neutral-900 tabular-nums [overflow-wrap:anywhere]">{{ $spt->nomor_sppd }}</p>
                @else
                    <p class="mt-2 text-sm text-neutral-600">Belum dicatat.</p>
                @endif
            </x-ui.card>

            <x-ui.card class="order-2 lg:order-none">
                <h2 class="{{ $judulKartu }}">Riwayat Status</h2>
                @if ($spt->riwayat->isEmpty())
                    <p class="mt-2 text-sm text-neutral-600">Belum ada riwayat.</p>
                @else
                    <ol role="list" class="mt-3 text-sm">
                        @foreach ($spt->riwayat as $r)
                            <li wire:key="r-{{ $r->id }}" class="relative pb-5 pl-7 last:pb-0">
                                @unless ($loop->last)<span aria-hidden="true" class="absolute top-4 bottom-0 left-[7px] w-0.5 bg-neutral-200"></span>@endunless
                                <span aria-hidden="true" @class(['absolute top-1 left-0 size-4 rounded-full border-2 border-mint-700', 'bg-mint-700' => $loop->last, 'bg-white' => ! $loop->last])></span>
                                <p class="font-semibold text-neutral-900">{{ KejadianSpt::label($r->kejadian) }}</p>
                                <p class="text-xs text-neutral-500 tabular-nums">{{ $waktu($r->created_at) }}</p>
                                <p class="text-neutral-600">{{ $r->olehUser?->name }}@if ($r->diinputOleh) · Diinput oleh {{ $r->diinputOleh->nama_bergelar }}@endif</p>
                                @if ($r->kejadian === KejadianSpt::TANGGAL_DIUBAH)
                                    <p class="mt-1 tabular-nums text-neutral-900">{{ $rentangNilai($r->nilai_lama) }} → {{ $rentangNilai($r->nilai_baru) }}</p>
                                @elseif ($r->kejadian === KejadianSpt::SPPD_DIUBAH)
                                    <p class="mt-1 tabular-nums text-neutral-900 [overflow-wrap:anywhere]">{{ $r->nilai_lama['nomor_sppd'] ?? '—' }} → {{ $r->nilai_baru['nomor_sppd'] ?? '—' }}</p>
                                @endif
                                @if ($r->alasan)<p class="mt-1 whitespace-pre-line text-neutral-600">{{ $r->alasan }}</p>@endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>

            <x-ui.card class="order-3 lg:order-none">
                <h2 class="{{ $judulKartu }}">Kendala</h2>
                @if ($spt->kendala)
                    <p class="mt-2 text-sm font-semibold text-neutral-900">{{ $spt->kendala->kategori }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-neutral-900">{{ $spt->kendala->uraian }}</p>
                    <p class="mt-2 text-xs text-neutral-500 tabular-nums">
                        {{ $spt->kendala->dibuatOleh?->name }}@if ($spt->kendala->diinputOleh) · Diinput oleh {{ $spt->kendala->diinputOleh->nama_bergelar }}@endif · {{ $waktu($spt->kendala->updated_at) }}
                    </p>
                @else
                    <p class="mt-2 text-sm text-neutral-600">Belum ada kendala.</p>
                @endif
            </x-ui.card>

            @if ($log !== null)
                <x-ui.card class="order-7 lg:order-none" x-data="{ buka: false }">
                    <h2 class="{{ $judulKartu }}">
                        <button type="button" x-on:click="buka = !buka" x-bind:aria-expanded="buka.toString()" aria-expanded="false" aria-controls="log-spt"
                            class="flex min-h-11 w-full items-center justify-between gap-2 rounded-md text-left">
                            Log Aktivitas
                            <span class="inline-flex text-mint-700" x-bind:class="buka || '-rotate-90'">{{ svg('heroicon-m-chevron-down', 'size-5') }}</span>
                        </button>
                    </h2>
                    <div id="log-spt" x-show="buka" x-cloak>
                        @if ($log->isEmpty())
                            <p class="mt-2 text-sm text-neutral-600">Belum ada aktivitas.</p>
                        @else
                            <ul role="list" class="mt-2 divide-y divide-neutral-200">
                                @foreach ($log as $a)
                                    <x-app.log-aktivitas-ringkas :a="$a" wire:key="log-{{ $a->id }}" />
                                @endforeach
                            </ul>
                        @endif
                        <a href="{{ route(auth()->user()->role === \App\Enums\Role::Admin ? 'admin.log' : 'pimpinan.log', ['spt' => $spt->id]) }}" wire:navigate
                            class="mt-2 inline-flex min-h-11 items-center text-sm font-medium text-mint-700 underline-offset-2 hover:underline">Lihat semua</a>
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($laksanakan)
        @include('livewire.spt.partials.aksi-modal')
    @endif
</div>
