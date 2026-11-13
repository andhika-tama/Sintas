<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Realisasi dana (versi 1.3 K5): the single Rupiah format for screens, PDF and riwayat, and the server-side parser.
 * resources/views/components/ui/input-rupiah.blade.php applies the same parsing rules in the browser; keep both in step.
 */
class Rupiah
{
    public const MAKS = 999_999_999_999;

    public const GALAT_FORMAT = 'Realisasi dana harus berupa angka Rupiah, tidak boleh minus.';

    public const GALAT_MAKS = 'Realisasi dana maksimal Rp999.999.999.999.';

    /** `Rp600.000`; null = `–`. */
    public static function format(?int $n): string
    {
        return $n === null ? '–' : 'Rp'.number_format($n, 0, ',', '.');
    }

    /**
     * Empty/whitespace/`Rp` alone = null. Drops whitespace, a leading `Rp`, thousands dots and the part after the comma
     * (which must be digits too); what is left must be digits, at most MAKS. Anything else throws with the exact message.
     */
    public static function baca(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (! is_string($v)) {
            throw new InvalidArgumentException(self::GALAT_FORMAT);
        }
        $teks = preg_replace('/^rp/i', '', preg_replace('/\s+/u', '', $v) ?? '');
        if (! preg_match('/^([\d.]*)(?:,(\d*))?$/', $teks, $m)) {
            throw new InvalidArgumentException(self::GALAT_FORMAT);
        }
        $digit = str_replace('.', '', $m[1]);
        if ($digit === '') {
            if ($teks === '' || $teks === '.') {
                return null;
            }
            throw new InvalidArgumentException(self::GALAT_FORMAT); // ",50" without a whole part
        }
        $digit = ltrim($digit, '0') ?: '0';
        if (strlen($digit) > 12) {
            throw new InvalidArgumentException(self::GALAT_MAKS);
        }

        return (int) $digit;
    }
}
