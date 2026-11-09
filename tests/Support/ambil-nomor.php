<?php

// Child process for PenomoranKonkurensiTest: boots the app against the throwaway database given by DB_CONNECTION/DB_DATABASE (env),
// takes one number inside a transaction and prints it as JSON. Args: irban id, decision date (Y-m-d), barrier dir
// [, spt id, user id: run TerimaPengajuan on that SPT as that user instead; its decision date is today].
// Start barrier: after booting, touch {dir}/siap-{pid} and wait for {dir}/mulai, so all children take a number together.

use App\Actions\Spt\TerimaPengajuan;
use App\Models\Irban;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Spt;
use App\Models\User;
use App\Services\Penomoran;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dir = $argv[3];
touch($dir.'/siap-'.getmypid());
for ($batas = microtime(true) + 60; ! file_exists($dir.'/mulai'); usleep(5000)) {
    if (microtime(true) > $batas) {
        fwrite(STDERR, 'Barrier timeout');
        exit(1);
    }
}

try {
    if (isset($argv[5])) {
        $hasil = app(TerimaPengajuan::class)->handle(User::findOrFail((int) $argv[5]), Spt::findOrFail((int) $argv[4]))->only(['nomor_urut', 'nomor_lengkap']);
    } else {
        $irban = Irban::findOrFail((int) $argv[1]);
        $hasil = DB::transaction(fn () => app(Penomoran::class)->ambil($irban, CarbonImmutable::parse($argv[2]),
            Klasifikasi::where('kode', 'KHUSUS')->firstOrFail(), KodeArsip::where('kode', '700')->firstOrFail()));
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}

echo json_encode(['nomor_urut' => $hasil['nomor_urut'], 'nomor_lengkap' => $hasil['nomor_lengkap']]);
