<?php

namespace Database\Factories;

use App\Models\KodeArsip;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KodeArsip> */
class KodeArsipFactory extends Factory
{
    protected $model = KodeArsip::class;

    public function definition(): array
    {
        return ['kode' => fake()->unique()->numerify('6##.#'), 'nama' => 'Kode '.fake()->unique()->bothify('??##')];
    }
}
