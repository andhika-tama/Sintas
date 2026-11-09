<?php

namespace Database\Factories;

use App\Enums\AksiPerubahan;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\PkptPerubahanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PkptPerubahanItem> */
class PkptPerubahanItemFactory extends Factory
{
    protected $model = PkptPerubahanItem::class;

    public function definition(): array
    {
        return [
            'perubahan_id' => PkptPerubahan::factory(),
            'aksi' => AksiPerubahan::Ubah,
            'pkpt_item_id' => PkptItem::factory(),
            'data_baru' => fn (array $a) => self::data(PkptItem::find($a['pkpt_item_id'])),
            'data_lama' => null,
            'urutan' => 1,
        ];
    }

    /** The E4 json shape built from an item, with optional overrides. */
    public static function data(?PkptItem $item, array $timpa = []): array
    {
        return array_merge([
            'irban_id' => $item?->irban_id,
            'jenis_penugasan_id' => $item?->jenis_penugasan_id,
            'tanggal_mulai' => $item?->tanggal_mulai?->format('Y-m-d'),
            'tanggal_selesai' => $item?->tanggal_selesai?->format('Y-m-d'),
            'keterangan' => $item?->keterangan,
            'target_spt' => $item?->target_spt,
        ], $timpa);
    }
}
