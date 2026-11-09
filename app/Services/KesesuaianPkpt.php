<?php

namespace App\Services;

use App\Models\PkptItem;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class KesesuaianPkpt
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} the item's own dates (an approved Perubahan PKPT writes them; an approved
     * Perubahan Penugasan never does, K2/K12). For "is this SPT sesuai" always call sesuai(), which also honours the Perubahan Penugasan mark.
     */
    public function rentang(PkptItem $item): array
    {
        return [CarbonImmutable::parse($item->tanggal_mulai)->startOfDay(), CarbonImmutable::parse($item->tanggal_selesai)->startOfDay()];
    }

    /** An item marked by an approved Perubahan Penugasan is never sesuai, whatever the dates (1.2 K6). */
    public function sesuai(PkptItem $item, DateTimeInterface|string $mulai, DateTimeInterface|string $selesai): bool
    {
        return $item->perubahan_penugasan_id === null && $this->sesuaiRentang($this->rentang($item), $mulai, $selesai);
    }

    /** @param array{0: DateTimeInterface|string, 1: DateTimeInterface|string} $rentang PKPT range; also for a range that is not saved yet (Perubahan PKPT impact). */
    public function sesuaiRentang(array $rentang, DateTimeInterface|string $mulai, DateTimeInterface|string $selesai): bool
    {
        [$m, $s] = [CarbonImmutable::parse($rentang[0])->startOfDay(), CarbonImmutable::parse($rentang[1])->startOfDay()];

        return $m->lte(CarbonImmutable::parse($mulai)->startOfDay()) && CarbonImmutable::parse($selesai)->startOfDay()->lte($s);
    }
}
