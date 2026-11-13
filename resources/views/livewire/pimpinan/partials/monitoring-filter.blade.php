{{-- Monitoring filter fields, rendered inline (≥768) and in the "Filter" drawer (<768); $p keeps ids unique. --}}
<div class="md:w-56"><x-ui.input label="Cari nomor SPT" name="cari" :id="$p.'-cari'" type="search" wire:model.live.debounce.300ms="cari" maxlength="100" :value="$this->cari" /></div>
<div class="md:w-24"><x-ui.select label="Tahun" name="tahun" :id="$p.'-tahun'" wire:model.live="tahun" :options="$tahunList" :value="$this->tahun" /></div>
<div class="md:w-36"><x-ui.select label="TW" name="tw" :id="$p.'-tw'" wire:model.live="tw" :options="\App\Services\Ekspor\PembangunMonitoring::TW" placeholder="Semua TW" :value="$this->tw" /></div>
<div class="md:w-36"><x-ui.select label="Irban" name="irban" :id="$p.'-irban'" wire:model.live="irban" :options="$irbanOptions" placeholder="Semua irban" :value="$this->irban" /></div>
<div class="md:w-44"><x-ui.select label="Status" name="status" :id="$p.'-status'" wire:model.live="status" :options="\App\Services\Ekspor\PembangunMonitoring::STATUS" placeholder="Semua status" :value="$this->status" /></div>
<div class="md:w-52"><x-ui.select label="Klasifikasi" name="klasifikasi" :id="$p.'-klasifikasi'" wire:model.live="klasifikasi" :options="$saringOpsi['klasifikasi']" placeholder="Semua klasifikasi" :value="$this->klasifikasi" /></div>
<div class="md:w-56"><x-ui.select label="Kode Arsip" name="kode_arsip" :id="$p.'-kode_arsip'" wire:model.live="kode_arsip" :options="$saringOpsi['kode_arsip']" placeholder="Semua kode arsip" :value="$this->kode_arsip" /></div>
<div class="md:w-44"><x-ui.select label="Jenis" name="jenis" :id="$p.'-jenis'" wire:model.live="jenis" :options="$saringOpsi['jenis']" placeholder="Semua jenis" :value="$this->jenis" /></div>
<div class="md:w-44"><x-ui.select label="Kesesuaian" name="kesesuaian" :id="$p.'-kesesuaian'" wire:model.live="kesesuaian" :options="\App\Services\Ekspor\PembangunMonitoring::KESESUAIAN" placeholder="Semua" :value="$this->kesesuaian" /></div>
<div class="md:w-52"><x-ui.select label="Penanda" name="penanda" :id="$p.'-penanda'" wire:model.live="penanda" :options="['terlambat' => 'Terlambat (semua tahun)']" placeholder="Semua" :value="$this->penanda" /></div>
