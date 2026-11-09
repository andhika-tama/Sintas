<?php

namespace App\Actions\PerubahanPenugasan;

use App\Enums\StatusItemPkpt;
use App\Enums\StatusPerubahanPenugasan;
use App\Models\PerubahanPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\User;
use App\Notifications\PerubahanPenugasanDiputuskan;
use App\Services\PenerimaNotifikasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pimpinan approves or rejects a Perubahan Penugasan (1.2 K1, no SK). Approval marks the item (perubahan_penugasan_id, K6) and
 * never touches its dates (K2/K12). Stale tabs get a toast under `perubahan`.
 */
class PutuskanPerubahanPenugasan
{
    public const SUDAH_DIPUTUSKAN = 'Perubahan Penugasan ini sudah diputuskan.';

    public const ITEM_BASI = 'Item PKPT ini sudah dihapus atau dipindahkan oleh Perubahan PKPT.';

    public function __construct(private PenerimaNotifikasi $penerima) {}

    public function handle(User $user, PerubahanPenugasan $p, bool $setuju, ?string $catatan): PerubahanPenugasan
    {
        Gate::forUser($user)->authorize('putuskan', $p);
        $p = DB::transaction(function () use ($user, $p, $setuju, $catatan) {
            // PKPT row first (same order as PutuskanPerubahan and SimpanSpt), then the request row.
            Pkpt::whereKey(PkptItem::whereKey($p->pkpt_item_id)->value('pkpt_id'))->lockForUpdate()->first();
            $p = PerubahanPenugasan::whereKey($p->id)->lockForUpdate()->first();
            if (! $p) {
                // The row went with its item (cascade delete).
                throw ValidationException::withMessages(['perubahan' => self::ITEM_BASI]);
            }
            if ($p->status !== StatusPerubahanPenugasan::Diajukan) {
                throw ValidationException::withMessages(['perubahan' => self::SUDAH_DIPUTUSKAN]);
            }
            $catatan = $this->catatan($catatan, $setuju);
            $item = PkptItem::find($p->pkpt_item_id);
            // Only approval needs the item: a rejection stays possible, or the request would sit Diajukan forever.
            if ($setuju && ($item?->status !== StatusItemPkpt::Aktif || $item->irban_id !== $p->irban_id)) {
                throw ValidationException::withMessages(['perubahan' => self::ITEM_BASI]);
            }

            $p->forceFill([
                'status' => $setuju ? StatusPerubahanPenugasan::Disetujui : StatusPerubahanPenugasan::Ditolak,
                'diputuskan_oleh' => $user->id, 'diputuskan_pada' => now(), 'catatan_putusan' => $catatan,
            ])->save();
            if ($setuju) {
                // R2: the latest approval is the reference.
                $item->forceFill(['perubahan_penugasan_id' => $p->id])->save();
            }

            return $p;
        }, attempts: 3);

        $this->penerima->kirimKeIrban($p->irban_id, $p->diinputOleh, fn () => new PerubahanPenugasanDiputuskan($p));

        return $p;
    }

    private function catatan(?string $catatan, bool $setuju): ?string
    {
        $catatan = trim((string) $catatan);
        if (! $setuju && $catatan === '') {
            throw ValidationException::withMessages(['catatan' => 'Isi catatan penolakan.']);
        }
        if (mb_strlen($catatan) > 1000) {
            throw ValidationException::withMessages(['catatan' => 'Catatan maksimal 1000 karakter.']);
        }

        return $catatan === '' ? null : $catatan;
    }
}
