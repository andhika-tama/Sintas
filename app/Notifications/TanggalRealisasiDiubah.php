<?php

namespace App\Notifications;

use App\Models\Spt;

class TanggalRealisasiDiubah extends NotifikasiSintas
{
    // protected, not private: queued notifications skip a parent's private properties when serialising (Jebakan).
    protected string $nomor;

    protected int $sptId;

    /** $lama/$baru: Tanggal::rentang() strings; $penginput: nama_bergelar of the "Diinput oleh" pegawai. */
    public function __construct(Spt $spt, protected string $lama, protected string $baru, protected string $alasan, protected ?string $penginput)
    {
        $this->nomor = $spt->nomor_lengkap;
        $this->sptId = $spt->id;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database']; // bell only (K6): no email
    }

    public function jenis(): string
    {
        return 'tanggal_realisasi_diubah';
    }

    public function judul(): string
    {
        return 'Tanggal realisasi SPT diubah';
    }

    public function konteks(): ?string
    {
        // The bell renders only judul/konteks/catatan, so dates and penginput live here.
        return collect(["SPT {$this->nomor}", "{$this->lama} → {$this->baru}", $this->penginput ? "Diinput oleh {$this->penginput}" : null])->filter()->implode(' · ');
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
        return array_filter([
            "Tanggal realisasi SPT {$this->nomor} diubah: {$this->lama} → {$this->baru}.",
            $this->penginput ? 'Diinput oleh '.self::aman($this->penginput).'.' : null,
        ]);
    }
}
