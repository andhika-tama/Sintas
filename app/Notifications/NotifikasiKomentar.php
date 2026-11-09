<?php

namespace App\Notifications;

use App\Models\Komentar;
use Illuminate\Support\Str;

/** Shared payload of the comment notifications; values are snapshotted so the queued email never re-reads the thread. */
abstract class NotifikasiKomentar extends NotifikasiSintas
{
    protected string $penulis;

    protected ?string $konteks;

    protected string $url;

    protected string $catatan;

    protected string $nomor;

    public function __construct(Komentar $komentar)
    {
        $spt = $komentar->spt;
        $root = $komentar->parent_id ?? $komentar->id;
        $this->penulis = $komentar->penulis->name;
        $this->nomor = $spt->nomor_lengkap ?: 'tanpa nomor';
        $this->konteks = $spt->nomor_lengkap
            ? "SPT {$spt->nomor_lengkap}"
            : (collect([$spt->namaJenis(), Str::limit((string) $spt->uraian_untuk, 60)])->filter()->implode(' · ') ?: null);
        $this->url = route('spt.show', $spt, absolute: false).'#komentar-'.$root;
        $this->catatan = Str::limit($komentar->isi, 200);
    }

    public function konteks(): ?string
    {
        return $this->konteks;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function catatan(): ?string
    {
        return $this->catatan;
    }

    public function isi(): array
    {
        return ["{$this->penulis} menulis di SPT {$this->nomor}:"];
    }
}
