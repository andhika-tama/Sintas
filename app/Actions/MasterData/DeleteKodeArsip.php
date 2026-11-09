<?php

namespace App\Actions\MasterData;

use App\Models\KodeArsip;
use App\Models\Spt;
use Illuminate\Validation\ValidationException;

class DeleteKodeArsip
{
    public function handle(KodeArsip $kode): void
    {
        if (Spt::where('kode_arsip_id', $kode->id)->exists()) {
            throw ValidationException::withMessages(['delete' => 'Kode arsip sudah dipakai SPT; tidak bisa dihapus.']);
        }
        $kode->delete();
    }
}
