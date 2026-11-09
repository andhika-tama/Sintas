<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Irban;
use App\Models\User;
use Illuminate\Database\Seeder;

// Dev only: every account uses the password "password".
class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $accounts = [
            ['admin', 'Administrator', Role::Admin],
            ['pimpinan', 'Inspektur', Role::Pimpinan],
            ['sekretaris', 'Sekretaris', Role::Pimpinan],
            ['irban1', 'Irban I', Role::Irban],
            ['bagianumum', 'Bagian Umum', Role::BagianUmum],
            ['irban2', 'Irban II', Role::Irban],
            ['irban3', 'Irban III', Role::Irban],
            ['irban4', 'Irban IV', Role::Irban],
            ['irsus', 'Irban Khusus', Role::Irban],
        ];

        foreach ($accounts as [$username, $name, $role]) {
            // irban_id is not mass-assignable; null when IrbanSeeder has not run.
            User::firstOrNew(['username' => $username])->forceFill([
                'name' => $name,
                'email' => "{$username}@sintas.test",
                'password' => 'password',
                'role' => $role,
                'irban_id' => $role === Role::Irban ? Irban::where('nama', $name)->value('id') : null,
            ])->save();
        }
    }
}
