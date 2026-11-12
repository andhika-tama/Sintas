<?php

namespace App\Actions\Spt;

use App\Enums\KejadianSpt;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Models\Pegawai;
use App\Models\Spt;
use App\Models\User;
use App\Notifications\NomorDibatalkan;
use App\Services\PenerimaNotifikasi;
use App\Support\DiinputOleh;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an issued number (versi 1.2.1 K4): Bagian Umum or the owning Irban, before the SPT is Selesai. Only the status changes:
 * the number stays in the register and the counter never decrements (PRD §9.1). Notifications per direction (K5).
 */
class BatalkanNomor
{
    /** The state rule shared by the UI (disabled button, stale tab) and handle(); null = may be cancelled. */
    public function cegah(Spt $spt): ?string
    {
        if ($spt->status_pengajuan !== StatusPengajuan::Diterima || $spt->nomor_urut === null) {
            return 'Hanya nomor yang sudah terbit yang dapat dibatalkan.';
        }

        return $spt->ditandai_selesai_pada !== null ? 'SPT sudah Selesai; penugasan tidak dapat dibatalkan.' : null;
    }

    public function handle(User $user, Spt $spt, string $alasan, ?int $diinputOleh = null): Spt
    {
        Gate::forUser($user)->authorize('batalkan', $spt);
        $this->periksa($spt); // a stale tab gets the state message before any field error
        $irban = $user->role === Role::Irban;
        $alasan = trim($alasan);
        $e = [];
        if (mb_strlen($alasan) < 10) {
            $e['alasan'] = 'Alasan pembatalan minimal 10 karakter.';
        } elseif (mb_strlen($alasan) > 1000) {
            $e['alasan'] = 'Alasan pembatalan maksimal 1000 karakter.';
        }
        if ($irban && ($galat = DiinputOleh::galat($user, $diinputOleh))) {
            $e['diinput_oleh'] = $galat;
        }
        if ($e) {
            throw ValidationException::withMessages($e);
        }
        $diinput = $irban ? $diinputOleh : null;
        DiinputOleh::catat($diinput);

        $spt = DB::transaction(function () use ($user, $spt, $alasan, $diinput) {
            $spt = Spt::whereKey($spt->id)->lockForUpdate()->firstOrFail();
            $this->periksa($spt);
            $spt->forceFill(['status_pengajuan' => StatusPengajuan::Dibatalkan])->save();
            $spt->riwayat()->create([
                'kejadian' => KejadianSpt::DIBATALKAN, 'nilai_lama' => ['status_pengajuan' => StatusPengajuan::Diterima->value],
                'nilai_baru' => ['status_pengajuan' => StatusPengajuan::Dibatalkan->value, 'nomor_lengkap' => $spt->nomor_lengkap, 'peran' => $user->role->value],
                'alasan' => $alasan, 'oleh' => $user->id, 'diinput_oleh' => $diinput,
            ]);

            return $spt;
        });

        $penerima = app(PenerimaNotifikasi::class);
        if ($irban) {
            DiinputOleh::ingat($user, $diinput);
            $pembatal = $spt->irban->nama.' (diinput oleh '.Pegawai::find($diinput)->nama_bergelar.')';
            Notification::send($penerima->bagianUmum()->merge($penerima->pimpinan())->unique('id'), new NomorDibatalkan($spt, $alasan, $pembatal));
        } else {
            $penerima->kirimKeIrban($spt->irban_id, $spt->diinputOleh, fn () => new NomorDibatalkan($spt, $alasan, 'Bagian Umum'));
            Notification::send($penerima->pimpinan(), new NomorDibatalkan($spt, $alasan, 'Bagian Umum'));
        }

        return $spt;
    }

    private function periksa(Spt $spt): void
    {
        if ($pesan = $this->cegah($spt)) {
            throw ValidationException::withMessages(['spt' => $pesan]);
        }
    }
}
