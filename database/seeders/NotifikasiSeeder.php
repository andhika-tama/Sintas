<?php

namespace Database\Seeders;

use App\Enums\StatusPengajuan;
use App\Models\Spt;
use App\Models\User;
use App\Notifications\NomorDibatalkan;
use App\Notifications\NotifikasiSintas;
use App\Notifications\PengajuanBaru;
use App\Notifications\PengajuanDiputuskan;
use App\Notifications\RangkumanNomorTerbit;
use App\Notifications\RingkasanMingguan;
use App\Notifications\SptTerlambat;
use App\Services\RingkasanMingguan as Hitung;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// Dev only. Run after SptSeeder. Bell entries are built by the real notification classes, database channel only (no email).
class NotifikasiSeeder extends Seeder
{
    public function run(): void
    {
        $irban = User::where('username', 'irban1')->first();
        $umum = User::where('username', 'bagianumum')->first();
        $pimpinan = User::where('username', 'pimpinan')->first();
        if (app()->isProduction() || ! $irban?->irban_id || ! $umum || ! $pimpinan || $irban->notifications()->exists()) {
            return;
        }

        $spt = fn (StatusPengajuan $status, string $uraian = '%') => Spt::where('irban_id', $irban->irban_id)->where('status_pengajuan', $status)
            ->where('uraian_untuk', 'like', $uraian)->orderBy('id')->first();

        // Irban: decisions on its submissions, a cancelled number, a late SPT (marked as notified, as the daily command would).
        $this->kirim($irban, fn ($s) => new PengajuanDiputuskan($s), $spt(StatusPengajuan::Diterima), 120, false);
        $this->kirim($irban, fn ($s) => new PengajuanDiputuskan($s, 'Lengkapi uraian objek dan periode yang diperiksa.'), $spt(StatusPengajuan::PerluRevisi), 1500, true);
        $this->kirim($irban, fn ($s) => new NomorDibatalkan($s, 'Penugasan digabung ke SPT lain.', 'Bagian Umum'), $spt(StatusPengajuan::Dibatalkan), 4300, true);
        $terlambat = $spt(StatusPengajuan::Diterima, '%terlambat%');
        if ($this->kirim($irban, fn ($s) => new SptTerlambat($s, (int) app(Settings::class)->get('penanda.batas_terlambat_hari')), $terlambat, 30, false)) {
            $terlambat->forceFill(['terlambat_diberitahu_pada' => now()])->save();
        }

        // Bagian Umum: new submissions waiting for a number.
        Spt::where('irban_id', $irban->irban_id)->where('status_pengajuan', StatusPengajuan::MenungguNomor)->orderBy('id')->get()
            ->each(fn (Spt $s, int $i) => $this->kirim($umum, fn ($s) => new PengajuanBaru($s), $s, [10, 75, 2900][$i] ?? 4400, $i >= 2));

        $this->kirim($pimpinan, fn ($h) => new RingkasanMingguan($h), app(Hitung::class)->hitung(CarbonImmutable::now('Asia/Jakarta')->startOfWeek(CarbonInterface::MONDAY)), 360, false);
        $kemarin = CarbonImmutable::now('Asia/Jakarta')->subDay()->toDateString();
        $this->kirim($pimpinan, fn ($h) => new RangkumanNomorTerbit($h), ['tanggal' => $kemarin, 'jumlah' => 3, 'per_irban' => ['Inspektur Pembantu I' => 2, 'Inspektur Pembantu II' => 1]], 180, false);
    }

    /** Sends one database notification $menit minutes in the past; returns false when the source model is missing. */
    private function kirim(User $user, callable $buat, mixed $sumber, int $menit, bool $dibaca): bool
    {
        if (! $sumber) {
            return false;
        }
        /** @var NotifikasiSintas $n */
        $n = $buat($sumber);
        $n->id = (string) Str::uuid(); // the sender clones the notification, so fix the id here to find the row again
        Notification::sendNow($user, $n, ['database']);
        $waktu = now()->subMinutes($menit);
        $user->notifications()->where('id', $n->id)->update(['created_at' => $waktu, 'updated_at' => $waktu, 'read_at' => $dibaca ? $waktu->copy()->addMinutes(5) : null]);

        return true;
    }
}
