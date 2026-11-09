<?php

namespace App\Actions\Spt;

use App\Enums\KejadianSpt;
use App\Enums\StatusPengajuan;
use App\Models\Spt;
use App\Models\User;
use App\Services\Penomoran;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/** Bagian Umum accepts a pengajuan: the decision date is today (Asia/Jakarta) and the number comes from the locked counter (PRD §9.1). Optional Nomor SPPD in the same transaction. */
class TerimaPengajuan
{
    use PutuskanPengajuan;

    public function __construct(private Penomoran $penomoran, private UbahNomorSppd $sppd) {}

    /** $nomorSppd: null leaves the stored value alone; '' clears it. */
    public function handle(User $user, Spt $spt, ?string $nomorSppd = null): Spt
    {
        Gate::forUser($user)->authorize('kelolaNomor', Spt::class);
        if ($nomorSppd !== null) {
            UbahNomorSppd::normal($nomorSppd); // field error before a number is taken
        }

        return $this->putuskan($user, $spt, function (Spt $spt) {
            $tanggal = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
            $n = $this->penomoran->ambil($spt->irban, $tanggal, $spt->klasifikasi, $spt->kodeArsip);
            $spt->forceFill([
                'nomor_urut' => $n['nomor_urut'], 'nomor_lengkap' => $n['nomor_lengkap'], 'nomor_counter_id' => $n['counter']->id,
                'tahun' => $tanggal->year, 'tanggal_spt' => $tanggal->toDateString(),
            ]);

            return [StatusPengajuan::Diterima, KejadianSpt::DITERIMA, null, ['nomor_lengkap' => $n['nomor_lengkap']]];
        }, $nomorSppd === null ? null : fn (Spt $spt) => $this->sppd->terapkan($user, $spt, $nomorSppd));
    }
}
