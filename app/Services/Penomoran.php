<?php

namespace App\Services;

use App\Models\Irban;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\NomorCounter;
use App\Models\Spt;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/** SPT numbering (PRD §9.1): one locked counter row per register, never MAX(nomor_urut)+1; numbers are never reused. */
class Penomoran
{
    public function __construct(private Settings $settings) {}

    /** @return array{tahun: int, scope: string} counter key for a register (year of the decision). */
    public function kunci(?Irban $irban, int $tahun): array
    {
        return self::kunciAturan([$this->settings->get('penomoran.scope'), $this->settings->get('penomoran.reset_tahunan')], $irban, $tahun);
    }

    /** @param array{0: string, 1: bool} $aturan [scope, reset_tahunan] */
    private static function kunciAturan(array $aturan, ?Irban $irban, int $tahun): array
    {
        return ['tahun' => $aturan[1] ? $tahun : 0, 'scope' => $aturan[0] === 'per_irban' ? "irban:{$irban?->id}" : 'global'];
    }

    /**
     * Takes the next number. Must run inside the caller's DB::transaction (LogicException otherwise).
     *
     * @return array{counter: NomorCounter, nomor_urut: int, nomor_lengkap: string}
     */
    public function ambil(Irban $irban, CarbonImmutable $tanggal, Klasifikasi $klasifikasi, KodeArsip $kodeArsip): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Penomoran::ambil harus dipanggil di dalam DB::transaction.');
        }
        $kunci = $this->kunci($irban, $this->tahun($tanggal));

        self::pastikanCounter($kunci);
        $counter = NomorCounter::where($kunci)->lockForUpdate()->firstOrFail();
        $bebas = $this->nomorBebas($counter->nomor_terakhir + 1, $irban, $tanggal, $klasifikasi, $kodeArsip);
        $counter->increment('nomor_terakhir', $bebas['nomor_urut'] - $counter->nomor_terakhir);

        return ['counter' => $counter] + $bebas;
    }

    /**
     * Saves the penomoran card through $simpan. When scope or reset_tahunan changes, every register of the new rule for the current
     * year (Asia/Jakarta) continues from the highest number used this year, so a new register never restarts at 1 (PRD §9.1).
     */
    public function simpanAturan(Closure $simpan): void
    {
        DB::transaction(function () use ($simpan) {
            $tahun = CarbonImmutable::now('Asia/Jakarta')->year;
            // From the table, not the cache: the cache only changes after commit, and a retried attempt must see the rolled-back values.
            $aturan = fn () => [$this->settings->segar('penomoran.scope'), $this->settings->segar('penomoran.reset_tahunan')];
            $lama = $aturan();
            // Lock first so an ambil in flight on an old register finishes before the highest number is read.
            $terpakai = NomorCounter::whereIn('tahun', array_unique([$tahun, self::kunciAturan($lama, null, $tahun)['tahun']]))
                ->lockForUpdate()->pluck('nomor_terakhir')->max();

            $simpan();
            $baru = $aturan();
            if ($baru === $lama) {
                return;
            }
            $tertinggi = max((int) $terpakai, (int) Spt::where('tahun', $tahun)->max('nomor_urut'));
            foreach ($baru[0] === 'per_irban' ? Irban::all() : [null] as $irban) {
                $kunci = self::kunciAturan($baru, $irban, $tahun);
                self::pastikanCounter($kunci);
                $counter = NomorCounter::where($kunci)->lockForUpdate()->firstOrFail();
                if ($counter->nomor_terakhir < $tertinggi) {
                    $counter->forceFill(['nomor_terakhir' => $tertinggi])->save(); // per model, so the activity log records it
                }
            }
        }, attempts: 3);
    }

    /**
     * Creates the register row if missing, as one atomic write that takes an exclusive lock and never touches an existing
     * nomor_terakhir. A plain firstOrCreate deadlocks on MySQL when several transactions race for a register's first number
     * (duplicate-key errors leave shared locks, then FOR UPDATE waits on each other). On SQLite the IMMEDIATE transaction
     * mode (config/database.php) already holds the write lock; lockForUpdate after this is for MySQL.
     */
    public static function pastikanCounter(array $kunci): void
    {
        NomorCounter::upsert([$kunci + ['nomor_terakhir' => 0]], ['tahun', 'scope'], ['updated_at']);
    }

    /** @return array{nomor_urut: int, nomor_lengkap: string} what the next ambil() would give; no lock, no write. */
    public function pratinjau(Irban $irban, CarbonImmutable $tanggal, Klasifikasi $klasifikasi, KodeArsip $kodeArsip): array
    {
        $nomor = (int) NomorCounter::where($this->kunci($irban, $this->tahun($tanggal)))->value('nomor_terakhir') + 1;

        return $this->nomorBebas($nomor, $irban, $tanggal, $klasifikasi, $kodeArsip);
    }

    /**
     * First number from $nomor whose nomor_lengkap is not issued yet: a changed format or an imported/manual number can rebuild
     * a string already in spt (unique), so it is skipped instead of failing the acceptance.
     *
     * @return array{nomor_urut: int, nomor_lengkap: string}
     */
    private function nomorBebas(int $nomor, Irban $irban, CarbonImmutable $tanggal, Klasifikasi $klasifikasi, KodeArsip $kodeArsip): array
    {
        for ($batas = $nomor + 1000; $nomor < $batas; $nomor++) {
            $lengkap = $this->format($nomor, $irban, $tanggal, $klasifikasi, $kodeArsip);
            if (! Spt::where('nomor_lengkap', $lengkap)->exists()) {
                return ['nomor_urut' => $nomor, 'nomor_lengkap' => $lengkap];
            }
        }

        throw new LogicException('Tidak ada nomor SPT bebas dalam 1.000 nomor berikutnya; periksa format penomoran.');
    }

    /** One register for every kode arsip (1.2 Task 2): the kode of the SPT only fills `{kode_arsip}`. */
    public function format(int $nomorUrut, Irban $irban, CarbonImmutable $tanggal, ?Klasifikasi $klasifikasi, KodeArsip $kodeArsip): string
    {
        $template = $this->settings->get('penomoran.format');
        if ($klasifikasi && $this->settings->get('penomoran.klasifikasi_di_nomor')) {
            $template = str_replace('{klasifikasi}', str_replace(' ', '-', mb_strtoupper($klasifikasi->nama)), $template);
        } else {
            // Off: the placeholder goes with one separator, the one before it or else the one after (`700/1-{klasifikasi}/2026` → `700/1/2026`).
            $template = preg_replace('~[/.\-_ ]\{klasifikasi\}|\{klasifikasi\}[/.\-_ ]?~', '', $template);
        }

        return strtr($template, [
            '{kode_arsip}' => $kodeArsip->kode,
            '{nomor_urut}' => (string) $nomorUrut,
            '{kode_irban}' => $irban->kode_nomor,
            '{tahun}' => (string) $this->tahun($tanggal),
        ]);
    }

    private function tahun(CarbonImmutable $tanggal): int
    {
        return $tanggal->setTimezone('Asia/Jakarta')->year;
    }
}
