<?php

namespace App\Actions\PerubahanPenugasan;

use App\Actions\Pkpt\SavePkptItem;
use App\Enums\StatusItemPkpt;
use App\Enums\StatusPerubahanPenugasan;
use App\Enums\StatusPkpt;
use App\Models\PerubahanPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahanItem;
use App\Models\User;
use App\Notifications\PerubahanPenugasanDiajukan;
use App\Services\PenerimaNotifikasi;
use App\Support\DiinputOleh;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An Irban asks to change the schedule of its own active item of a Disahkan PKPT (1.2 K5/K7). The item's dates never change; Pimpinan decides.
 * $data: tanggal_mulai, tanggal_selesai, alasan, diinput_oleh.
 */
class AjukanPerubahanPenugasan
{
    /** R2: one Diajukan per item (key `item`, a toast). */
    public const MASIH_DIAJUKAN = 'Masih ada Perubahan Penugasan yang menunggu keputusan untuk item ini.';

    public function __construct(private PenerimaNotifikasi $penerima) {}

    /**
     * Own active item of a Disahkan PKPT. An item that was this irban's (it still carries the irban but is dihapus, or an applied operation
     * recorded this irban in data_lama) throws ITEM_BASI (key `item`, a toast); any other id is a 404, so foreign ids stay indistinguishable from missing ones.
     */
    public static function itemSendiri(User $user, int $id): PkptItem
    {
        $item = PkptItem::whereHas('pkpt', fn ($q) => $q->where('status', StatusPkpt::Disahkan->value))->find($id);
        $irban = (int) $user->irban_id;
        if ($item && $item->irban_id === $irban && $item->status === StatusItemPkpt::Aktif) {
            return $item;
        }
        $pernah = $item && ($item->irban_id === $irban || PkptPerubahanItem::where('pkpt_item_id', $item->id)->whereNotNull('data_lama')->get()
            ->contains(fn (PkptPerubahanItem $op) => (int) ($op->data_lama['irban_id'] ?? 0) === $irban));
        abort_unless($pernah, 404);

        throw ValidationException::withMessages(['item' => PutuskanPerubahanPenugasan::ITEM_BASI]);
    }

    public function handle(User $user, PkptItem $item, array $data): PerubahanPenugasan
    {
        $pkpt = $item->pkpt()->firstOrFail();
        // Before the policy (which also requires Disahkan) so the irban sees the reason instead of a 403.
        if ($pkpt->status !== StatusPkpt::Disahkan) {
            throw ValidationException::withMessages(['pkpt' => 'Perubahan Penugasan hanya untuk PKPT yang sudah disahkan.']);
        }
        Gate::forUser($user)->authorize('create', [PerubahanPenugasan::class, $item]);

        $p = DB::transaction(function () use ($user, $pkpt, $item, $data) {
            // PKPT row first, like every PKPT writer: a concurrent Perubahan PKPT approval cannot remove/move the item mid-way,
            // and two submissions for one item queue here so R2 holds.
            Pkpt::whereKey($pkpt->id)->lockForUpdate()->first();
            $item = self::itemSendiri($user, $item->id);
            if (PerubahanPenugasan::where('pkpt_item_id', $item->id)->where('status', StatusPerubahanPenugasan::Diajukan->value)->exists()) {
                throw ValidationException::withMessages(['item' => self::MASIH_DIAJUKAN]);
            }

            $errors = [];
            $tanggal = SavePkptItem::tanggal($pkpt, $data, $errors);
            if (count($tanggal) === 2 && ! $errors
                && $item->tanggal_mulai->equalTo($tanggal['tanggal_mulai']) && $item->tanggal_selesai->equalTo($tanggal['tanggal_selesai'])) {
                $errors['tanggal_mulai'] = 'Jadwal baru sama dengan jadwal PKPT.';
            }
            $alasan = trim((string) ($data['alasan'] ?? ''));
            if ($alasan === '') {
                $errors['alasan'] = 'Isi alasan perubahan.';
            } elseif (mb_strlen($alasan) > 1000) {
                $errors['alasan'] = 'Alasan maksimal 1000 karakter.';
            }
            $diinput = filter_var($data['diinput_oleh'] ?? null, FILTER_VALIDATE_INT) ?: null;
            if ($galat = DiinputOleh::galat($user, $diinput)) {
                $errors['diinput_oleh'] = $galat;
            } else {
                DiinputOleh::catat($diinput);
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            return PerubahanPenugasan::create([
                'pkpt_item_id' => $item->id, 'irban_id' => $item->irban_id,
                'tanggal_mulai' => $tanggal['tanggal_mulai'], 'tanggal_selesai' => $tanggal['tanggal_selesai'],
                'alasan' => $alasan, 'diajukan_oleh' => $user->id, 'diinput_oleh' => $diinput,
            ]);
        }, attempts: 3);
        DiinputOleh::ingat($user, $p->diinput_oleh);

        Notification::send($this->penerima->pimpinan(), new PerubahanPenugasanDiajukan($p));

        return $p;
    }
}
