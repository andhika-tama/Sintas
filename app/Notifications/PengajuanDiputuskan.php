<?php

namespace App\Notifications;

use App\Enums\StatusPengajuan;
use App\Models\Spt;
use Illuminate\Support\Str;

/** One class for the three Bagian Umum decisions; jenis, judul and konteks follow the SPT status. */
class PengajuanDiputuskan extends NotifikasiSintas
{
    private StatusPengajuan $status;

    private string $konteks;

    private ?string $nomor;

    private ?string $catatan;

    private int $sptId;

    public function __construct(Spt $spt, ?string $catatan = null)
    {
        $this->status = $spt->status_pengajuan;
        $this->nomor = $spt->nomor_lengkap;
        $this->konteks = $this->status === StatusPengajuan::Diterima
            ? "SPT {$spt->nomor_lengkap}"
            : collect([$spt->namaJenis(), Str::limit((string) $spt->uraian_untuk, 60)])->filter()->implode(' · ');
        $this->catatan = $catatan;
        $this->sptId = $spt->id;
    }

    public function jenis(): string
    {
        return match ($this->status) {
            StatusPengajuan::Diterima => 'pengajuan_diterima',
            StatusPengajuan::PerluRevisi => 'pengajuan_perlu_revisi',
            default => 'pengajuan_ditolak',
        };
    }

    public function judul(): string
    {
        return match ($this->status) {
            StatusPengajuan::Diterima => 'Nomor SPT terbit',
            StatusPengajuan::PerluRevisi => 'Pengajuan nomor SPT perlu direvisi',
            default => 'Pengajuan nomor SPT ditolak',
        };
    }

    public function konteks(): ?string
    {
        return $this->konteks;
    }

    public function url(): string
    {
        return route('spt.show', $this->sptId, absolute: false);
    }

    public function catatan(): ?string
    {
        return $this->catatan;
    }

    public function isi(): array
    {
        return $this->status === StatusPengajuan::Diterima ? ["Nomor SPT: {$this->nomor}"] : [$this->judul().'.', $this->konteks];
    }
}
