<?php

namespace App\Actions\MasterData;

use App\Models\KodeArsip;
use App\Models\Spt;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class DeleteKodeArsip
{
    public function handle(KodeArsip $kode): void
    {
        $dipakai = ValidationException::withMessages(['delete' => 'Kode arsip sudah dipakai SPT; tidak bisa dihapus.']);
        if (Spt::where('kode_arsip_id', $kode->id)->exists()) {
            throw $dipakai;
        }
        try {
            $kode->delete();
        } catch (QueryException $e) {
            // An SPT was saved between the check and the delete: the FK says no.
            throw (string) $e->getCode() === '23000' ? $dipakai : $e;
        }
    }
}
