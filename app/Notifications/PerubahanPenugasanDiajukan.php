<?php

namespace App\Notifications;

use App\Models\PerubahanPenugasan;
use App\Support\Tanggal;

/** A new Perubahan Penugasan for every Pimpinan (1.2 K1). */
class PerubahanPenugasanDiajukan extends NotifikasiSintas
{
    /** Pimpinan list page; the route is added in Task 7, whose test asserts it equals route('pimpinan.perubahan-penugasan', absolute: false). */
    public const URL = '/pimpinan/perubahan-penugasan';

    protected string $irban;

    protected ?string $konteks;

    protected string $jadwal;

    protected string $alasan;

    public function __construct(PerubahanPenugasan $p)
    {
        $p->loadMissing(['irban', 'item.jenisPenugasan']);
        $this->irban = (string) $p->irban?->nama;
        $this->konteks = self::konteksPerubahan($p);
        $this->jadwal = Tanggal::rentang($p->item->tanggal_mulai, $p->item->tanggal_selesai);
        $this->alasan = $p->alasan;
    }

    /** "Irban I · Reviu · 6–24 April 2026": the requested dates. */
    public static function konteksPerubahan(PerubahanPenugasan $p): string
    {
        return collect([$p->irban?->nama, $p->item?->jenisPenugasan?->nama, Tanggal::rentang($p->tanggal_mulai, $p->tanggal_selesai)])
            ->filter()->implode(' · ');
    }

    public function jenis(): string
    {
        return 'perubahan_penugasan_diajukan';
    }

    public function judul(): string
    {
        return 'Perubahan Penugasan menunggu keputusan';
    }

    public function konteks(): ?string
    {
        return $this->konteks;
    }

    public function url(): string
    {
        return self::URL;
    }

    public function catatan(): ?string
    {
        return $this->alasan;
    }

    public function isi(): array
    {
        return ["{$this->irban} mengajukan perubahan jadwal penugasan dari jadwal PKPT {$this->jadwal}. Setujui atau tolak pengajuan ini."];
    }
}
