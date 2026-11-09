<?php

namespace App\Actions\MasterData;

use App\Models\KodeArsip;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveKodeArsip
{
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'kode' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.]+$/', Rule::unique('kode_arsip', 'kode')->ignore($ignoreId)],
            'nama' => ['required', 'string', 'max:100'],
        ];
    }

    public static function messages(): array
    {
        return [
            'kode.required' => 'Isi kode arsip.',
            'kode.regex' => 'Kode arsip hanya huruf, angka, dan titik.',
            'kode.max' => 'Kode arsip maksimal 20 karakter.',
            'kode.unique' => 'Kode arsip sudah ada.',
            'nama.required' => 'Isi nama kode arsip.',
            'nama.max' => 'Nama maksimal 100 karakter.',
        ];
    }

    /** @param array{kode: string, nama: string} $data */
    public function handle(array $data, ?KodeArsip $kode = null): KodeArsip
    {
        $data = validator($data, self::rules($kode?->id), self::messages())->validate();
        try {
            return tap($kode ?? new KodeArsip, fn ($k) => $k->fill($data)->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['kode' => self::messages()['kode.unique']]);
        }
    }
}
