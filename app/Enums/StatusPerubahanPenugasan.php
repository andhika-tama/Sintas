<?php

namespace App\Enums;

enum StatusPerubahanPenugasan: string
{
    case Diajukan = 'diajukan';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Diajukan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
        };
    }

    /** x-app.tag icon + variant (rectangular tag, never a pill). */
    public function tag(): array
    {
        return match ($this) {
            self::Diajukan => ['icon' => 'inbox', 'variant' => 'info'],
            self::Disetujui => ['icon' => 'check', 'variant' => 'mint'],
            self::Ditolak => ['icon' => 'x-circle', 'variant' => 'danger'],
        };
    }
}
