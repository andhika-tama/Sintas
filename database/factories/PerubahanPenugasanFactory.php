<?php

namespace Database\Factories;

use App\Enums\StatusPerubahanPenugasan;
use App\Models\Pegawai;
use App\Models\PerubahanPenugasan;
use App\Models\PkptItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PerubahanPenugasan> */
class PerubahanPenugasanFactory extends Factory
{
    protected $model = PerubahanPenugasan::class;

    public function definition(): array
    {
        return [
            'pkpt_item_id' => PkptItem::factory(),
            'irban_id' => fn (array $a) => PkptItem::find($a['pkpt_item_id'])->irban_id,
            'tanggal_mulai' => '2026-04-01',
            'tanggal_selesai' => '2026-04-20',
            'alasan' => 'Menyesuaikan jadwal OPD.',
            'diajukan_oleh' => User::factory()->irban(),
            'diinput_oleh' => Pegawai::factory(),
            'status' => StatusPerubahanPenugasan::Diajukan,
        ];
    }
}
