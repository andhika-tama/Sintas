<?php

namespace App\Notifications;

use App\Enums\StatusPerubahanPenugasan;
use App\Models\PerubahanPenugasan;

/** Pimpinan approved or rejected a Perubahan Penugasan: the irban accounts + the "Diinput oleh" pegawai (via PenerimaNotifikasi::kirimKeIrban). */
class PerubahanPenugasanDiputuskan extends NotifikasiSintas
{
    protected bool $disetujui;

    protected ?string $konteks;

    protected ?string $catatanPutusan;

    public function __construct(PerubahanPenugasan $p)
    {
        $p->loadMissing(['irban', 'item.jenisPenugasan']);
        $this->disetujui = $p->status === StatusPerubahanPenugasan::Disetujui;
        $this->konteks = PerubahanPenugasanDiajukan::konteksPerubahan($p);
        $this->catatanPutusan = $p->catatan_putusan;
    }

    public function jenis(): string
    {
        return $this->disetujui ? 'perubahan_penugasan_disetujui' : 'perubahan_penugasan_ditolak';
    }

    public function judul(): string
    {
        return $this->disetujui ? 'Perubahan Penugasan disetujui' : 'Perubahan Penugasan ditolak';
    }

    public function konteks(): ?string
    {
        return $this->konteks;
    }

    public function url(): string
    {
        return route('irban.pkpt', absolute: false);
    }

    public function catatan(): ?string
    {
        return $this->catatanPutusan;
    }

    public function isi(): array
    {
        return [$this->disetujui
            ? 'Pimpinan menyetujui perubahan jadwal penugasan Anda. Jadwal PKPT tidak berubah, dan SPT pada item ini ditandai Tidak Sesuai PKPT.'
            : 'Pimpinan menolak perubahan jadwal penugasan Anda. Jadwal PKPT tetap berlaku.'];
    }
}
