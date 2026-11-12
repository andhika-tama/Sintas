<?php

namespace App\Actions\MasterData;

use App\Models\Klasifikasi;
use App\Models\Spt;
use Illuminate\Validation\ValidationException;

/** Only a klasifikasi no SPT uses (drafts included; spt.klasifikasi_id is restrictOnDelete) can be deleted; otherwise it is deactivated (K7). */
class DeleteKlasifikasi
{
    public const DIPAKAI = 'Klasifikasi sudah dipakai SPT; tidak bisa dihapus.';

    public function handle(Klasifikasi $klasifikasi): void
    {
        if (Spt::where('klasifikasi_id', $klasifikasi->id)->exists()) {
            throw ValidationException::withMessages(['delete' => self::DIPAKAI]);
        }
        $klasifikasi->delete();
    }
}
