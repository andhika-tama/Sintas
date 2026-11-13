{{-- Register Nomor SPT (design/dashboard/bagian-umum.md "Register", _shared.md §3 tables + §6 filter bar/export): compact table with the 16 columns of
     RegisterNomor::KOLOM (cells from RegisterNomor::data, the source of the exports too), sticky header and sticky Nomor SPT column, scrolling inside its
     own container; long text clamped with "Selengkapnya", team shown as "Nama (Peran) + N lainnya". Search by number, filters status pengajuan/irban/bulan,
     100 per page, export split button. Cancelled rows keep the Dibatalkan badge, text never struck through. No animation (DESIGN §7.5). --}}
@php
    $filterAktif = count(array_filter([$this->status, $this->irban, $this->bulan, $this->cari, $this->klasifikasi, $this->kode_arsip, $this->jenis], fn ($v) => $v !== '' && $v !== null));
    $total = $baris->total();
@endphp
<div>
    <x-ui.page-header title="Register Nomor SPT" title-id="judul-register" description="Stambook digital nomor SPT tahun {{ $this->tahun }}. Nomor yang dibatalkan tetap tercatat dan tidak dipakai ulang.">
        <x-slot:actions>
            <x-app.export-buttons>
                <x-slot:tambahan>
                    {{-- Rekap Tahunan (spec 1.1.4 Task 4): Excel only, Tahun filter only; the caption says the other filters are ignored. --}}
                    <button type="button" role="menuitem" wire:click="eksporRekapTahunan" x-on:click="tutup()"
                        class="flex min-h-11 w-full items-start gap-2 px-3 py-2 text-left text-sm text-neutral-900 hover:bg-neutral-50 focus-visible:bg-neutral-50 lg:min-h-9">
                        {{ svg('heroicon-m-calendar-days', 'mt-px size-5 shrink-0 text-mint-700') }}
                        <span>Rekap Tahunan (Excel)<span class="mt-0.5 block text-xs text-neutral-500">Hanya filter Tahun ({{ $this->tahun }}); filter lain diabaikan</span></span>
                    </button>
                </x-slot:tambahan>
            </x-app.export-buttons>
            <x-ui.button type="button" variant="secondary" icon="hashtag" wire:click="bukaAwal" :disabled="$terkunciHalaman" aria-describedby="{{ $terkunciHalaman ? 'awal-terkunci' : '' }}">Atur nomor awal</x-ui.button>
            <x-ui.button type="button" icon="plus" wire:click="bukaManual">Catat Nomor Manual</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($terkunciHalaman)
        <p id="awal-terkunci" class="-mt-3 mb-4 text-sm text-neutral-600">Nomor awal tidak dapat diubah karena sudah ada nomor yang terbit pada register {{ $this->tahun }}.</p>
    @endif

    <div x-data="{ filter: false }" class="mb-4">
        <button type="button" x-on:click="filter = !filter" x-bind:aria-expanded="filter.toString()" aria-controls="filter-register"
            class="inline-flex h-11 items-center gap-2 rounded-md border border-border-strong bg-white px-4 text-sm font-medium text-mint-700 md:hidden">
            {{ svg('heroicon-m-funnel', 'size-5') }}Filter{{ $filterAktif ? " ({$filterAktif})" : '' }}
        </button>
        <div id="filter-register" class="grid gap-3 max-md:mt-3 max-md:hidden sm:grid-cols-2 md:flex md:flex-wrap md:items-end" x-bind:class="filter && 'max-md:!grid'">
            <div class="md:w-28"><x-ui.select label="Tahun" name="tahun" wire:model.live="tahun" :options="$tahunList" :value="$this->tahun" /></div>
            <div class="md:w-64">
                <x-ui.input type="search" label="Cari nomor" name="cari" wire:model.live.debounce.300ms="cari" :value="$this->cari" placeholder="Cari…" autocomplete="off" />
            </div>
            <div class="md:w-44"><x-ui.select label="Status" name="status" wire:model.live="status" :options="$statusOptions" placeholder="Semua status" :value="$this->status" /></div>
            <div class="md:w-44"><x-ui.select label="Irban" name="irban" wire:model.live="irban" :options="$irbanOptions" placeholder="Semua irban" :value="$this->irban" /></div>
            <div class="md:w-40"><x-ui.select label="Bulan" name="bulan" wire:model.live="bulan" :options="$bulanOptions" placeholder="Semua bulan" :value="$this->bulan" /></div>
            <div class="md:w-52"><x-ui.select label="Klasifikasi" name="klasifikasi" wire:model.live="klasifikasi" :options="$saringOpsi['klasifikasi']" placeholder="Semua klasifikasi" :value="$this->klasifikasi" /></div>
            <div class="md:w-56"><x-ui.select label="Kode Arsip" name="kode_arsip" wire:model.live="kode_arsip" :options="$saringOpsi['kode_arsip']" placeholder="Semua kode arsip" :value="$this->kode_arsip" /></div>
            <div class="md:w-44"><x-ui.select label="Jenis" name="jenis" wire:model.live="jenis" :options="$saringOpsi['jenis']" placeholder="Semua jenis" :value="$this->jenis" /></div>
            @if ($filterAktif)
                <x-ui.button type="button" variant="ghost" wire:click="aturUlang">Atur ulang</x-ui.button>
            @endif
        </div>
    </div>

    <div wire:loading.delay.class="opacity-60" wire:target="tahun,cari,status,irban,bulan,klasifikasi,kode_arsip,jenis,aturUlang,gotoPage,previousPage,nextPage,perPage" wire:loading.delay.attr="aria-busy">
        @if ($total === 0)
            <x-ui.card>
                <x-ui.empty-state icon="book-open" :title="$filterAktif ? 'Tidak ada nomor yang cocok.' : 'Belum ada nomor terbit pada '.$this->tahun.'.'"
                    :description="$filterAktif ? 'Coba atur ulang filter.' : 'Nomor yang diterbitkan dari Kotak Masuk atau dicatat manual akan tampil di sini.'">
                    @if ($filterAktif)
                        <x-ui.button type="button" variant="secondary" wire:click="aturUlang">Atur ulang filter</x-ui.button>
                    @endif
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <x-ui.table label="Register Nomor SPT {{ $this->tahun }}" density="compact">
                <x-slot:head>
                    @foreach (\App\Services\RegisterNomor::KOLOM as $i => $k)
                        <x-ui.th>{{ $k }}</x-ui.th>
                        @if ($i === 13)
                            <x-ui.th><span class="sr-only">Aksi</span></x-ui.th>
                        @endif
                    @endforeach
                </x-slot:head>
                @foreach ($baris as $s)
                    @php
                        $d = $register->data($s);
                        $kosong = \App\Services\RegisterNomor::KOSONG;
                    @endphp
                    <tr wire:key="reg-{{ $s->id }}" class="align-top">
                        {{-- Below 640px the sticky number may wrap (after "/" or "-") so the other columns stay visible beside it. --}}
                        <td class="whitespace-nowrap max-sm:whitespace-normal">
                            <span class="inline-flex items-center gap-1">
                                <a href="{{ route('spt.show', $s) }}" wire:navigate class="inline-flex min-h-11 items-center font-medium text-mint-700 underline-offset-2 hover:underline max-sm:max-w-32 lg:min-h-6">{{ $d['nomor'] }}</a>
                                <button type="button" x-data x-on:click="navigator.clipboard?.writeText(@js($d['nomor'])).then(() => $dispatch('toast', { type: 'success', message: 'Nomor disalin' }))"
                                    aria-label="Salin nomor {{ $d['nomor'] }}" class="inline-flex size-11 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900 lg:size-8">
                                    {{ svg('heroicon-m-clipboard', 'size-4') }}
                                </button>
                            </span>
                        </td>
                        <td class="whitespace-nowrap">{{ $d['tanggal'] ?? $kosong }}</td>
                        <td class="whitespace-nowrap">{{ $d['sppd'] ?? $kosong }}</td>
                        <td class="whitespace-nowrap">{{ $d['klasifikasi'] ?? $kosong }}</td>
                        <td>@include('livewire.bagian-umum.partials.teks-panjang', ['teks' => $d['item'], 'id' => "item-{$s->id}"])</td>
                        <td class="min-w-36 [overflow-wrap:anywhere]">{{ $d['jenis'] ?? $kosong }}</td>
                        <td>@include('livewire.bagian-umum.partials.teks-panjang', ['teks' => $d['objek'], 'id' => "objek-{$s->id}"])</td>
                        <td class="whitespace-nowrap">{{ $d['mulai'] ?? $kosong }}</td>
                        <td class="whitespace-nowrap">{{ $d['selesai'] ?? $kosong }}</td>
                        <td>@include('livewire.bagian-umum.partials.teks-panjang', ['teks' => $d['periode'], 'id' => "periode-{$s->id}"])</td>
                        <td>@include('livewire.bagian-umum.partials.teks-panjang', ['teks' => $d['uraian'], 'id' => "uraian-{$s->id}"])</td>
                        <td class="min-w-44">{{ $d['ppj'] ?? $kosong }}</td>
                        <td>
                            @if ($d['anggota'] === [])
                                {{ $kosong }}
                            @elseif (count($d['anggota']) === 1)
                                <span class="block min-w-48">{{ $d['anggota'][0] }}</span>
                            @else
                                {{-- Short form "Nama (Peran) + N lainnya"; the toggle reveals the whole team in print order. --}}
                                <div x-data="{ buka: false }" class="min-w-56 max-w-72">
                                    <p x-show="!buka">{{ $d['anggota'][0] }}</p>
                                    <ol id="anggota-{{ $s->id }}" x-show="buka" x-cloak class="list-decimal space-y-0.5 pl-5">
                                        @foreach ($d['anggota'] as $a)
                                            <li>{{ $a }}</li>
                                        @endforeach
                                    </ol>
                                    <button type="button" x-on:click="buka = !buka" x-bind:aria-expanded="buka.toString()" aria-expanded="false" aria-controls="anggota-{{ $s->id }}"
                                        class="min-h-11 rounded-sm text-sm font-medium text-mint-700 hover:underline lg:min-h-6"
                                        x-text="buka ? 'Sembunyikan' : @js('+ '.(count($d['anggota']) - 1).' lainnya')">+ {{ count($d['anggota']) - 1 }} lainnya</button>
                                </div>
                            @endif
                        </td>
                        <td><x-app.status-badge :status="$d['status']" /></td>
                        <td>
                            @if ($d['status'] === \App\Enums\StatusPengajuan::Diterima)
                                {{-- BatalkanNomor::cegah(): a Selesai SPT keeps a disabled button with the reason written beside it (versi 1.2.1 Task 4). --}}
                                @php $blokir = $pembatalan->cegah($s); @endphp
                                <div @class(['flex items-start gap-1', 'min-w-44' => $blokir])>
                                    <button type="button" wire:click="bukaBatal({{ $s->id }})" aria-label="Batalkan Nomor {{ $d['nomor'] }}" title="Batalkan Nomor"
                                        @if ($blokir) disabled aria-disabled="true" aria-describedby="batal-blokir-{{ $s->id }}" @endif
                                        class="inline-flex size-11 shrink-0 items-center justify-center rounded-md text-danger-700 enabled:hover:bg-neutral-50 disabled:cursor-not-allowed disabled:text-neutral-400 lg:size-8">{{ svg('heroicon-m-no-symbol', 'size-5') }}</button>
                                    @if ($blokir)
                                        <p id="batal-blokir-{{ $s->id }}" class="pt-1 text-xs text-neutral-600">{{ $blokir }}</p>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>@include('livewire.bagian-umum.partials.teks-panjang', ['teks' => $d['catatan'], 'id' => "catatan-{$s->id}"])</td>
                        <td>@include('livewire.bagian-umum.partials.teks-panjang', ['teks' => $d['keterangan'], 'id' => "ket-{$s->id}"])</td>
                    </tr>
                @endforeach
            </x-ui.table>
            <x-ui.pagination :paginator="$baris" perPageModel="perPage" :perPageOptions="[100]" class="mt-4" />
        @endif
    </div>

    {{-- Catat Nomor Manual --}}
    <x-ui.drawer name="manual" title="Catat Nomor Manual" size="md" wire:model="showManual">
        <x-ui.alert variant="warning" class="mb-4">Gunakan hanya untuk SPT mendesak.</x-ui.alert>
        <div class="space-y-4">
            <x-ui.select label="Irban" name="manual_irban" id="manual-irban" wire:model.live="manual.irban_id" :options="$irbanOptions" placeholder="Pilih Irban" required
                :value="$manual['irban_id'] ?? ''" :error="$errors->first('manual.irban_id')" />
            <x-ui.select label="Klasifikasi" name="manual_klasifikasi" id="manual-klasifikasi" wire:model.live="manual.klasifikasi_id" :options="$klasifikasiOptions" placeholder="Pilih klasifikasi" required
                :value="$manual['klasifikasi_id'] ?? ''" :error="$errors->first('manual.klasifikasi_id')" />
            <x-ui.select label="Kode arsip" name="manual_kode_arsip" id="manual-kode-arsip" wire:model.live="manual.kode_arsip_id" :options="$kodeArsipOptions" placeholder="Pilih kode arsip" required
                :value="$manual['kode_arsip_id'] ?? ''" :error="$errors->first('manual.kode_arsip_id')" />
            <x-ui.select label="Jenis penugasan" name="manual_jenis" id="manual-jenis" wire:model="manual.jenis_penugasan_id" :options="$jenisOptions" placeholder="Tanpa jenis penugasan"
                :value="$manual['jenis_penugasan_id'] ?? ''" :error="$errors->first('manual.jenis_penugasan_id')" />
            <x-ui.textarea label="Perihal" name="manual_perihal" id="manual-perihal" wire:model="manual.uraian_untuk" rows="3" maxlength="1000" required
                helper="Isi seperti pada kalimat setelah kata Untuk di SPT." :error="$errors->first('manual.uraian_untuk')" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input type="date" label="Tanggal mulai" name="manual_mulai" id="manual-mulai" wire:model="manual.tanggal_mulai" required :error="$errors->first('manual.tanggal_mulai')" />
                <x-ui.input type="date" label="Tanggal selesai" name="manual_selesai" id="manual-selesai" wire:model="manual.tanggal_selesai" required :error="$errors->first('manual.tanggal_selesai')" />
            </div>
            <x-ui.textarea label="Alasan" name="manual_alasan" id="manual-alasan" wire:model="manual.alasan" rows="3" maxlength="1000" required
                helper="Minimal 10 karakter. Dicatat pada riwayat SPT." :error="$errors->first('manual.alasan')" />
            <div>
                <x-ui.input label="Nomor SPPD" name="manual_sppd" id="manual-sppd" wire:model.live.debounce.500ms="manual.nomor_sppd" maxlength="100" autocomplete="off"
                    class="tabular-nums" aria-describedby="manual-sppd-peringatan" helper="Opsional. Dapat diisi nanti di halaman detail SPT." :error="$errors->first('manual.nomor_sppd')" />
                <x-app.peringatan-sppd id="manual-sppd-peringatan" :duplikat="$duplikatSppd" :tahun="$tahunIni" />
            </div>
            <div>
                <p class="text-sm font-medium text-neutral-900">Nomor yang akan terbit</p>
                <p class="mt-1.5 rounded-md border border-mint-200 bg-mint-50 px-4 py-3 text-center font-sans text-xl font-bold [overflow-wrap:anywhere] text-neutral-900 tabular-nums" aria-live="polite">
                    {{ $pratinjau ?: 'Pilih Irban, klasifikasi, dan kode arsip untuk melihat nomor' }}
                </p>
                <p class="mt-1.5 text-xs text-neutral-500">Nomor diambil dari urutan yang sama dengan pengajuan Irban dan baru pasti saat Anda menekan "Terbitkan Nomor".</p>
            </div>
        </div>
        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="$wire.showManual = false">Batal</x-ui.button>
            <x-ui.button type="button" icon="check" wire:click="catatManual" wire:loading.attr="disabled" wire:target="catatManual">Terbitkan Nomor</x-ui.button>
        </x-slot:footer>
    </x-ui.drawer>

    {{-- Batalkan Nomor --}}
    <x-ui.modal name="batal" title="Batalkan Nomor" wire:model="showBatal">
        @if ($batal)
            <p class="text-neutral-900">Nomor <span class="font-semibold tabular-nums [overflow-wrap:anywhere]">{{ $batal->nomor_lengkap }}</span> akan ditandai Dibatalkan. Nomor tetap tercatat di register dan tidak akan dipakai ulang.</p>
        @endif
        <div class="mt-4">
            <x-ui.textarea label="Alasan pembatalan" name="alasan" id="alasan-batal" wire:model="alasan" rows="4" maxlength="1000" required autofocus
                helper="Minimal 10 karakter." :error="$errors->first('alasan')" />
            <x-ui.penghitung-karakter model="alasan" :value="$alasan" />
        </div>
        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="$wire.showBatal = false">Kembali</x-ui.button>
            <x-ui.button type="button" variant="danger" icon="no-symbol" wire:click="batalkan" wire:loading.attr="disabled" wire:target="batalkan">Batalkan Nomor</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Atur nomor awal --}}
    <x-ui.modal name="awal" title="Atur nomor awal" size="sm" wire:model="showAwal">
        <p class="mb-4 text-neutral-600">Isi dengan nomor terakhir dari stambook fisik. Nomor berikutnya yang terbit adalah angka ini ditambah 1. Hanya untuk register {{ $this->tahun }}.</p>
        <div class="space-y-4">
            @if ($perIrban)
                <x-ui.select label="Irban" name="awal_irban" id="awal-irban" wire:model.live="awalIrban" :options="$irbanOptions" placeholder="Pilih Irban" required
                    :value="$this->awalIrban" :error="$errors->first('awalIrban')" />
            @endif
            @if ($awalTerkunci)
                <x-ui.alert variant="info">Nomor awal tidak dapat diubah karena sudah ada nomor yang terbit pada register ini.</x-ui.alert>
            @endif
            <x-ui.input type="number" min="0" inputmode="numeric" label="Nomor terakhir dari stambook fisik" name="awal_nomor" id="awal-nomor" wire:model="awalNomor" required
                :disabled="$awalTerkunci" :error="$errors->first('awalNomor')" />
        </div>
        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="$wire.showAwal = false">Batal</x-ui.button>
            <x-ui.button type="button" icon="check" wire:click="aturAwal" wire:loading.attr="disabled" wire:target="aturAwal" :disabled="$awalTerkunci">Simpan</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
