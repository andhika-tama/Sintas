<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Models\Spt;
use App\Models\User;

class SptPolicy
{
    private function milik(User $user, Spt $spt): bool
    {
        return $user->role === Role::Irban && $user->irban_id !== null && $user->irban_id === $spt->irban_id;
    }

    /** Irban: own irban, any status. Bagian Umum, Pimpinan, Admin: anything that has been submitted (not draf). */
    public function view(User $user, Spt $spt): bool
    {
        if ($user->role === Role::Irban) {
            return $this->milik($user, $spt);
        }

        return $spt->status_pengajuan !== StatusPengajuan::Draf;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Irban && $user->irban_id !== null;
    }

    public function update(User $user, Spt $spt): bool
    {
        return $this->milik($user, $spt) && in_array($spt->status_pengajuan, [StatusPengajuan::Draf, StatusPengajuan::PerluRevisi], true);
    }

    /** Irban of the SPT, after the number is issued and until it is marked Selesai: dates, final SPT, Selesai, kendala. */
    public function laksanakan(User $user, Spt $spt): bool
    {
        return $this->milik($user, $spt) && $spt->status_pengajuan === StatusPengajuan::Diterima && $spt->ditandai_selesai_pada === null;
    }

    /** Terima / minta revisi / tolak a pending submission. */
    public function decide(User $user, Spt $spt): bool
    {
        return $user->role === Role::BagianUmum && $spt->status_pengajuan === StatusPengajuan::MenungguNomor;
    }

    /** Cancel an issued number (versi 1.2.1 K4): Bagian Umum, or the Irban that owns the SPT. Role/ownership only; the state rule is BatalkanNomor::cegah(). */
    public function batalkan(User $user, Spt $spt): bool
    {
        return $user->role === Role::BagianUmum || $this->milik($user, $spt);
    }

    /** Manual number entry, register, starting number: Bagian Umum only. */
    public function kelolaNomor(User $user): bool
    {
        return $user->role === Role::BagianUmum;
    }

    /** Nomor SPPD (K5): Bagian Umum while the SPT is Menunggu Nomor or Diterima (not Ditolak, Perlu Revisi, Dibatalkan); other roles see it read-only. */
    public function ubahSppd(User $user, Spt $spt): bool
    {
        return $user->role === Role::BagianUmum && $this->view($user, $spt) && in_array($spt->status_pengajuan, [StatusPengajuan::MenungguNomor, StatusPengajuan::Diterima], true);
    }

    /** Rekap Triwulanan (Pimpinan) and Rekap Saya (Irban with an irban); read-only. */
    public function rekap(User $user): bool
    {
        return $user->role === Role::Pimpinan || ($user->role === Role::Irban && $user->irban_id !== null);
    }

    /** Monitoring SPT page and its export: Pimpinan only (PRD §5, read-only). */
    public function monitoring(User $user): bool
    {
        return $user->role === Role::Pimpinan;
    }

    /** Pimpinan starts a thread (optionally Minta Penjelasan) on any SPT they can view. Never changes SPT data. */
    public function komentari(User $user, Spt $spt): bool
    {
        return $user->role === Role::Pimpinan && $this->view($user, $spt);
    }

    /** Pimpinan, Bagian Umum or the owning Irban reply in a thread. Admin is view-only. */
    public function balasKomentar(User $user, Spt $spt): bool
    {
        return $this->view($user, $spt) && ($user->role === Role::Pimpinan || $user->role === Role::BagianUmum || $this->milik($user, $spt));
    }

    /** Any pimpinan may close (Inspektur and Sekretaris share oversight), not only the requester. */
    public function tutupPermintaan(User $user, Spt $spt): bool
    {
        return $user->role === Role::Pimpinan && $this->view($user, $spt);
    }
}
