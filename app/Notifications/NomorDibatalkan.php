<?php

namespace App\Notifications;

use App\Models\Spt;

class NomorDibatalkan extends NotifikasiSintas
{
    // protected, not private: queued notifications skip a parent's private properties when serialising (Jebakan).
    protected string $nomor;

    protected int $sptId;

    /** $pembatal: "Bagian Umum", or "{irban} (diinput oleh {nama})" when the Irban cancelled (versi 1.2.1 K5). */
    public function __construct(Spt $spt, protected string $alasan, protected string $pembatal)
    {
        $this->nomor = $spt->nomor_lengkap;
        $this->sptId = $spt->id;
    }

    public function jenis(): string
    {
        return 'nomor_dibatalkan';
    }

    public function judul(): string
    {
        return 'Nomor SPT dibatalkan';
    }

    public function konteks(): ?string
    {
        return "SPT {$this->nomor}";
    }

    public function url(): string
    {
        return route('spt.show', $this->sptId, absolute: false);
    }

    public function catatan(): ?string
    {
        return $this->alasan;
    }

    public function isi(): array
    {
        return ["Nomor SPT {$this->nomor} dibatalkan oleh ".self::aman($this->pembatal).'.'];
    }
}
