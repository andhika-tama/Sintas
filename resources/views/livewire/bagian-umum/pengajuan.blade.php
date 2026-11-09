{{-- Kotak Masuk Pengajuan (design/dashboard/bagian-umum.md "Inbox", _shared.md §3/§5). ≥1280: list 40% + detail 60% side by side; 768–1279: full-width list,
     detail as a right drawer (no slide: the ≤220ms drawer motion is optional and this role has none); <768: cards, the detail replaces the list as a full page.
     Keyboard: resources/js/kotak-masuk.js (listbox with aria-activedescendant; J/K/T/R/X/? toggleable, never inside fields or modals).
     No animation anywhere here (DESIGN §7.5); loading only dims the detail pane. --}}
@use('App\Support\Tanggal')
@php
    $umur = function ($s) {
        $hari = (int) $s->diajukan_pada?->setTimezone('Asia/Jakarta')->startOfDay()->diffInDays(now('Asia/Jakarta')->startOfDay());

        return $hari === 0 ? 'Hari ini' : $hari.' hari';
    };
    $filterAktif = count(array_filter([$this->irban, $this->dari, $this->sampai]));
@endphp
<div x-data="kotakMasuk" x-on:keydown.window="tombol($event)" x-on:kotak-masuk-maju.window="maju()">
    <x-ui.page-header title="Kotak Masuk Pengajuan" description="Putuskan pengajuan nomor SPT dari Irban.">
        <x-slot:actions>
            <x-ui.switch label="Pintasan keyboard" name="pintasan" x-model="pintasan" />
            <x-ui.button type="button" variant="ghost" icon="question-mark-circle" x-on:click="$dispatch('open-modal', { name: 'bantuan-pintasan' })" aria-keyshortcuts="?">
                Bantuan pintasan
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div @class(['max-md:hidden' => $detail])>
        <x-ui.tabs :tabs="$tabs" :active="$this->tab" model="tab" label="Status pengajuan" panel="panel-pengajuan" />

        <div x-data="{ filter: false }" class="mb-4">
            <button type="button" x-on:click="filter = !filter" x-bind:aria-expanded="filter.toString()" aria-controls="filter-pengajuan"
                class="inline-flex h-11 items-center gap-2 rounded-md border border-border-strong bg-white px-4 text-sm font-medium text-mint-700 md:hidden">
                {{ svg('heroicon-m-funnel', 'size-5') }}Filter{{ $filterAktif ? " ({$filterAktif})" : '' }}
            </button>
            <div id="filter-pengajuan" class="grid gap-3 max-md:mt-3 max-md:hidden sm:grid-cols-3 md:flex md:items-end" x-bind:class="filter && 'max-md:!grid'">
                <div class="md:w-48"><x-ui.select label="Irban" name="irban" wire:model.live="irban" :options="$irbanOptions" placeholder="Semua irban" :value="$this->irban" /></div>
                <div class="md:w-44"><x-ui.input type="date" label="Diajukan dari" name="dari" wire:model.live="dari" :value="$this->dari" /></div>
                <div class="md:w-44"><x-ui.input type="date" label="Sampai" name="sampai" wire:model.live="sampai" :value="$this->sampai" /></div>
                @if ($filterAktif)
                    <x-ui.button type="button" variant="ghost" wire:click="$set('irban', ''); $set('dari', ''); $set('sampai', '')">Atur ulang</x-ui.button>
                @endif
            </div>
        </div>
    </div>

    <div id="panel-pengajuan" role="tabpanel" aria-labelledby="panel-pengajuan-tab-{{ $this->tab }}" class="xl:grid xl:grid-cols-5 xl:items-start xl:gap-6">
        {{-- List (40% at ≥1280) --}}
        <div @class(['xl:col-span-2', 'max-md:hidden' => $detail])>
            @if ($daftar->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state icon="inbox" :title="$this->tab === 'menunggu_nomor' ? 'Semua pengajuan sudah diproses.' : 'Tidak ada pengajuan di tab ini.'"
                        :description="$filterAktif ? 'Coba atur ulang filter untuk melihat pengajuan lain.' : 'Pengajuan baru dari Irban akan tampil di sini.'">
                        @if ($filterAktif)
                            <x-ui.button type="button" variant="secondary" wire:click="$set('irban', ''); $set('dari', ''); $set('sampai', '')">Atur ulang filter</x-ui.button>
                        @endif
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <ul x-ref="daftar" role="listbox" tabindex="0" aria-label="Daftar pengajuan: {{ $tabs[$this->tab] }}"
                    @if ($this->pilih) aria-activedescendant="spt-opsi-{{ $this->pilih }}" @endif
                    x-bind:aria-activedescendant="aktif ? 'spt-opsi-' + aktif : null"
                    class="group space-y-2 outline-offset-2 md:space-y-0 md:divide-y md:divide-neutral-200 md:overflow-hidden md:rounded-lg md:border md:border-neutral-200 md:bg-white md:shadow-xs">
                    @foreach ($daftar as $s)
                        <li wire:key="opsi-{{ $s->id }}" id="spt-opsi-{{ $s->id }}" role="option" data-id="{{ $s->id }}"
                            aria-selected="{{ $s->id === $this->pilih ? 'true' : 'false' }}" x-bind:data-aktif="aktif === {{ $s->id }} || null"
                            x-on:click="aktif = {{ $s->id }}; pilih({{ $s->id }})"
                            @class([
                                'flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2 text-sm text-neutral-900 md:min-h-11 md:flex-nowrap',
                                'max-md:rounded-lg max-md:border max-md:border-neutral-200 max-md:p-4 max-md:shadow-xs',
                                'group-focus-visible:data-aktif:outline-2 group-focus-visible:data-aktif:-outline-offset-2 group-focus-visible:data-aktif:outline-mint-600',
                                'bg-mint-50 shadow-[inset_3px_0_0_var(--color-mint-700)]' => $s->id === $this->pilih,
                                'bg-white hover:bg-neutral-50' => $s->id !== $this->pilih,
                            ])>
                            <x-app.status-badge :status="$s->status()" class="shrink-0" />
                            <div class="min-w-0 flex-1 max-md:basis-full max-md:order-last">
                                <p class="truncate font-medium">{{ $s->irban->nama }}</p>
                                @if ($s->namaJenis())
                                    <x-app.type-chip :color="$s->jenisPenugasan?->warna" :label="$s->namaJenis()" class="max-w-full truncate !text-xs" />
                                @else
                                    <p class="truncate text-xs text-neutral-600 max-md:hidden" title="{{ $s->uraian_untuk }}">{{ $s->uraian_untuk ?: 'Tanpa jenis penugasan' }}</p>
                                @endif
                                <p class="mt-1 line-clamp-2 text-neutral-600 md:hidden">{{ $s->uraian_untuk }}</p>
                            </div>
                            <div class="shrink-0 text-right text-xs tabular-nums max-md:ml-auto">
                                <p class="text-neutral-900">{{ $s->diajukan_pada?->setTimezone('Asia/Jakarta')->translatedFormat('d F Y') }}</p>
                                <p class="text-neutral-500"><span class="sr-only">Umur pengajuan: </span>{{ $umur($s) }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($jumlahTab > $daftar->count())
                    <p class="mt-2 text-xs text-neutral-500">Menampilkan {{ $daftar->count() }} dari {{ $jumlahTab }} pengajuan. Persempit dengan filter.</p>
                @endif
            @endif
        </div>

        {{-- Detail (60% at ≥1280, drawer 768–1279, full page below 768) --}}
        @if ($detail)
            <div x-show="drawer" x-on:click="tutup()" aria-hidden="true" class="fixed inset-0 z-[60] bg-scrim"></div>
        @endif
        <section x-ref="detail" tabindex="-1" aria-labelledby="detail-judul" wire:key="detail-{{ $detail?->id ?? 'kosong' }}"
            x-bind:role="drawer && @js((bool) $detail) ? 'dialog' : 'region'" x-bind:aria-modal="drawer && @js((bool) $detail) ? 'true' : null"
            x-trap="drawer && @js((bool) $detail)"
            wire:loading.delay.class="opacity-60" wire:loading.delay.attr="aria-busy" wire:target="pilihSpt,terima,kirimCatatan,muatUlang,bukaTerima,bukaCatatan"
            @class([
                'flex flex-col bg-white outline-none xl:col-span-3 xl:sticky xl:top-20 xl:max-h-[calc(100dvh-6rem)] xl:rounded-lg xl:border xl:border-neutral-200 xl:shadow-xs',
                'max-md:rounded-lg max-md:border max-md:border-neutral-200',
                'md:max-xl:fixed md:max-xl:inset-y-0 md:max-xl:right-0 md:max-xl:z-[70] md:max-xl:w-[560px] md:max-xl:shadow-lg' => $detail,
                'max-xl:hidden' => ! $detail,
            ])>
            @if ($detail)
                @include('livewire.bagian-umum.partials.detail-pengajuan', ['spt' => $detail])
            @else
                <h2 id="detail-judul" class="sr-only">Rincian pengajuan</h2>
                <x-ui.empty-state icon="cursor-arrow-rays" title="Pilih pengajuan" description="Pilih pengajuan di daftar untuk melihat rinciannya dan memutuskan." />
            @endif
        </section>
    </div>

    {{-- Terima --}}
    <x-ui.modal name="terima" title="Terima pengajuan" size="sm" wire:model="showTerima">
        <p class="text-neutral-600">Nomor berikutnya dalam register {{ now('Asia/Jakarta')->year }}</p>
        <p class="mt-2 rounded-md border border-mint-200 bg-mint-50 px-4 py-3 text-center font-sans text-2xl font-bold [overflow-wrap:anywhere] text-neutral-900 tabular-nums">{{ $pratinjau }}</p>
        <p class="mt-3 text-neutral-600">Nomor terbit saat Anda menekan "Terbitkan Nomor" dan tidak dapat diubah atau dipakai ulang.</p>
        <div class="mt-4">
            <x-ui.input label="Nomor SPPD" name="nomor_sppd" id="terima-sppd" wire:model.live.debounce.500ms="nomor_sppd" maxlength="100" autocomplete="off"
                class="tabular-nums" aria-describedby="terima-sppd-peringatan" helper="Opsional. Dapat diisi nanti di halaman detail SPT." :error="$errors->first('nomor_sppd')" />
            <x-app.peringatan-sppd id="terima-sppd-peringatan" :duplikat="$duplikatSppd" :tahun="$tahunSppd" />
        </div>
        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="$wire.showTerima = false">Batal</x-ui.button>
            <x-ui.button type="button" icon="check" wire:click="terima" wire:loading.attr="disabled" wire:target="terima" autofocus>
                Terbitkan Nomor
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Perlu Revisi / Tolak --}}
    <x-ui.modal name="catatan" :title="$mode === 'tolak' ? 'Tolak pengajuan' : 'Minta revisi'" wire:model="showCatatan">
        <x-ui.textarea label="Catatan" name="catatan" id="catatan-keputusan" wire:model="catatan" rows="4" maxlength="1000" required autofocus
            :helper="'Catatan akan dikirim ke '.($detail?->irban->nama ?? 'Irban').'. Minimal 10 karakter.'" :error="$errors->first('catatan')" />
        <x-ui.penghitung-karakter model="catatan" :value="$catatan" />
        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="$wire.showCatatan = false">Batal</x-ui.button>
            <x-ui.button type="button" :variant="$mode === 'tolak' ? 'danger' : 'primary'" :icon="$mode === 'tolak' ? 'x-circle' : 'arrow-uturn-left'"
                wire:click="kirimCatatan" wire:loading.attr="disabled" wire:target="kirimCatatan">{{ $mode === 'tolak' ? 'Tolak' : 'Perlu Revisi' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- "?" help --}}
    <x-ui.modal name="bantuan-pintasan" title="Pintasan keyboard" size="sm">
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
            @foreach ([['↑ / ↓ atau J / K', 'Pindah antarpengajuan'], ['Enter', 'Buka / fokus ke rincian'], ['T', 'Terima'], ['R', 'Perlu Revisi'], ['X', 'Tolak'], ['Esc', 'Kembali ke daftar'], ['?', 'Tampilkan bantuan ini']] as [$k, $v])
                <dt><kbd class="rounded-sm border border-border-strong bg-neutral-50 px-1.5 py-0.5 font-sans text-xs font-semibold text-neutral-900">{{ $k }}</kbd></dt>
                <dd class="text-neutral-900">{{ $v }}</dd>
            @endforeach
        </dl>
        <p class="mt-4 text-neutral-600">J, K, T, R, X dan ? tidak berlaku saat mengetik di kolom isian, dan dapat dimatikan.</p>
        <div class="mt-3"><x-ui.switch label="Pintasan keyboard" name="pintasan-bantuan" x-model="pintasan" /></div>
    </x-ui.modal>
</div>
