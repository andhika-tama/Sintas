<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\StatusPkpt;
use App\Models\PerubahanPenugasan;
use App\Models\PkptItem;
use App\Models\User;

/** Irban asks for its own items of a Disahkan PKPT; Pimpinan decides. Admin and Bagian Umum never see them (K13). */
class PerubahanPenugasanPolicy
{
    /** Ownership and the item being active are checked by AjukanPerubahanPenugasan::itemSendiri() (404 or a stale-tab toast). */
    public function create(User $user, PkptItem $item): bool
    {
        return $user->role === Role::Irban && $user->irban_id !== null && $item->pkpt?->status === StatusPkpt::Disahkan;
    }

    public function view(User $user, PerubahanPenugasan $p): bool
    {
        return $user->role === Role::Pimpinan || ($user->role === Role::Irban && (int) $user->irban_id === $p->irban_id);
    }

    public function putuskan(User $user, PerubahanPenugasan $p): bool
    {
        return $user->role === Role::Pimpinan;
    }
}
