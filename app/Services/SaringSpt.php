<?php

namespace App\Services;

use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Spt;

/** Klasifikasi / Kode Arsip / Jenis Penugasan filter shared by Monitoring, Register and their exports (1.3 Task 1). Unknown values become null. */
class SaringSpt
{
    public const LAINNYA = 'lainnya';

    /** @return array{klasifikasi: ?int, kode_arsip: ?int, jenis: int|string|null} */
    public function dari(array $input): array
    {
        $ada = function ($v, string $model): ?int {
            $id = is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;

            return $id !== null && $model::whereKey($id)->exists() ? $id : null;
        };

        return [
            'klasifikasi' => $ada($input['klasifikasi'] ?? null, Klasifikasi::class),
            'kode_arsip' => $ada($input['kode_arsip'] ?? null, KodeArsip::class),
            'jenis' => ($input['jenis'] ?? null) === self::LAINNYA ? self::LAINNYA : $ada($input['jenis'] ?? null, JenisPenugasan::class),
        ];
    }

    /** @return array{klasifikasi: array<int, string>, kode_arsip: array<int, string>, jenis: array<int|string, string>} */
    public function opsi(): array
    {
        return [
            'klasifikasi' => Klasifikasi::urut()->get()->mapWithKeys(fn ($k) => [$k->id => $k->label()])->all(),
            'kode_arsip' => KodeArsip::orderBy('kode')->get()->mapWithKeys(fn ($k) => [$k->id => $k->kode.' · '.$k->nama])->all(),
            'jenis' => JenisPenugasan::orderBy('nama')->pluck('nama', 'id')->all() + [self::LAINNYA => Spt::LABEL_LAINNYA],
        ];
    }

    /** @return list<string> file/PDF header lines for the active keys. */
    public function ringkasan(array $saring): array
    {
        $f = $this->dari($saring);

        // A master row deleted since dari() validated it yields no line instead of an error.
        $klasifikasi = $f['klasifikasi'] !== null ? Klasifikasi::whereKey($f['klasifikasi'])->value('nama') : null;
        $arsip = $f['kode_arsip'] !== null ? KodeArsip::find($f['kode_arsip']) : null;
        $jenis = $f['jenis'] === self::LAINNYA ? Spt::LABEL_LAINNYA : ($f['jenis'] !== null ? JenisPenugasan::whereKey($f['jenis'])->value('nama') : null);

        return array_values(array_filter([
            $klasifikasi !== null ? 'Klasifikasi: '.$klasifikasi : null,
            $arsip ? 'Kode Arsip: '.$arsip->kode.' · '.$arsip->nama : null,
            $jenis !== null ? 'Jenis: '.$jenis : null,
        ]));
    }

    public function aktif(array $saring): bool
    {
        return ($saring['klasifikasi'] ?? null) !== null || ($saring['kode_arsip'] ?? null) !== null || ($saring['jenis'] ?? null) !== null;
    }
}
