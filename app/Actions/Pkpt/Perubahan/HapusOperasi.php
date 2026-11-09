<?php

namespace App\Actions\Pkpt\Perubahan;

use App\Actions\Pkpt\Perubahan\Concerns\MengelolaPerubahan;
use App\Models\PkptPerubahanItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Removes an operation from a draf/ditolak package. */
class HapusOperasi
{
    use MengelolaPerubahan;

    public function handle(User $user, PkptPerubahanItem $op): void
    {
        Gate::forUser($user)->authorize('update', $op->perubahan);

        DB::transaction(function () use ($op) {
            $this->kunciTerbuka($op->perubahan);
            $op = PkptPerubahanItem::whereKey($op->id)->first();
            if (! $op) {
                return;
            }
            $op->delete();
        });
    }
}
