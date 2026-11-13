<?php

namespace App\Actions\MasterData;

use App\Enums\SyaratSelesai;
use App\Models\Klasifikasi;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin adds or edits a klasifikasi (1.2.1 Task 6, K7). Kode is set once: only read when creating, ignored when editing.
 * Nama is unique regardless of case because the SPT import matches it that way. $data keys are the Admin form's properties.
 */
class SaveKlasifikasi
{
    public static function rules(?int $id): array
    {
        return [
            ...$id ? [] : ['kode' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/', Rule::unique('klasifikasi', 'kode')]],
            'nama' => ['required', 'string', 'max:100', 'not_regex:~/~', function (string $attr, mixed $nilai, \Closure $gagal) use ($id) {
                if (Klasifikasi::whereRaw('lower(nama) = ?', [mb_strtolower(trim((string) $nilai))])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                    $gagal('Nama klasifikasi sudah ada.');
                }
            }],
            'wajibItemPkpt' => ['required', 'boolean'],
            'syaratSelesai' => ['required', Rule::enum(SyaratSelesai::class)],
            'tampilPublik' => ['required', 'boolean'],
            // Data colour of the Rekap Tahunan ribbon (spec 1.1.4 Task 4); empty clears it.
            'warna' => ['nullable', 'string', 'regex:/^[0-9A-Fa-f]{6}$/'],
            'urutan' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];
    }

    public static function messages(): array
    {
        return [
            'kode.required' => 'Isi kode klasifikasi.',
            'kode.regex' => 'Kode hanya huruf besar, angka, dan garis bawah (_).',
            'kode.max' => 'Kode maksimal 30 karakter.',
            'kode.unique' => 'Kode klasifikasi sudah ada.',
            'nama.required' => 'Isi nama klasifikasi.',
            'nama.not_regex' => 'Nama klasifikasi tidak boleh memuat garis miring (/).',
            'nama.max' => 'Nama maksimal 100 karakter.',
            'syaratSelesai.*' => 'Pilih syarat selesai.',
            'warna.regex' => 'Warna harus 6 digit heksadesimal, mis. BDD7EE.',
            'urutan.*' => 'Urutan harus bilangan bulat 1–999.',
        ];
    }

    public function handle(?Klasifikasi $klasifikasi, array $data): Klasifikasi
    {
        $data = validator($data, self::rules($klasifikasi?->id), self::messages())->validate();
        $klasifikasi ??= new Klasifikasi(['kode' => trim($data['kode']), 'aktif' => true]); // trim: the regex's `$` lets a trailing newline through
        $klasifikasi->fill([
            'nama' => trim($data['nama']),
            'wajib_item_pkpt' => $data['wajibItemPkpt'],
            'syarat_selesai' => $data['syaratSelesai'],
            'tampil_publik' => $data['tampilPublik'],
            // Empty: an edit keeps its place, a new row goes last.
            'urutan' => $data['urutan'] ?? $klasifikasi->urutan ?? (int) Klasifikasi::max('urutan') + 1,
            // Callers that do not send the colour keep it.
            ...array_key_exists('warna', $data) ? ['warna' => filled($data['warna']) ? strtoupper($data['warna']) : null] : [],
        ]);
        try {
            $klasifikasi->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['kode' => self::messages()['kode.unique']]);
        }

        return $klasifikasi;
    }
}
