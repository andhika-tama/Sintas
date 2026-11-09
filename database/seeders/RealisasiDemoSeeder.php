<?php

namespace Database\Seeders;

use App\Enums\StatusPengajuan;
use App\Enums\StatusPkpt;
use App\Models\Irban;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use App\Services\Penomoran;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Dev only (Fase 9 demo for the public Realisasi page). Run after PkptSeeder and SptSeeder: REGULER SPT marked Selesai for the current
// year's Disahkan PKPT items that have already ended, 1–2 per item, each on one kecamatan, so the public charts and table have data.
class RealisasiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('username', 'irban1')->first();
        $reguler = Klasifikasi::where('kode', 'REGULER')->first();
        $kode = KodeArsip::where('kode', '700')->firstOrFail();
        $hari = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        if (app()->isProduction() || ! $user || ! $reguler || Spt::where('klasifikasi_id', $reguler->id)->whereNotNull('ditandai_selesai_pada')->exists()) {
            return;
        }

        $item = PkptItem::aktif()->select('pkpt_item.*')->join('pkpt', 'pkpt.id', '=', 'pkpt_item.pkpt_id')
            ->where('pkpt.tahun', $hari->year)->where('pkpt.status', StatusPkpt::Disahkan->value)
            ->whereDate('pkpt_item.tanggal_selesai', '<', $hari)->orderBy('pkpt_item.id')->get();
        $kecamatan = Objek::where('jenis', 'kecamatan')->orderBy('id')->pluck('id')->all();
        $penginput = Pegawai::orderBy('id')->value('id');
        $penomoran = app(Penomoran::class);
        if ($kecamatan === []) {
            return;
        }

        $k = 0;
        foreach ($item as $n => $i) {
            $irban = Irban::find($i->irban_id);
            for ($s = 0; $s < ($n % 3 === 1 ? 1 : 2); $s++) {
                $mulai = CarbonImmutable::parse($i->tanggal_mulai)->addDays($s * 7);
                $selesai = $mulai->addDays(9);
                DB::transaction(function () use ($irban, $reguler, $kode, $i, $mulai, $selesai, $user, $penginput, $penomoran, $kecamatan, &$k) {
                    $nomor = $penomoran->ambil($irban, $mulai, $reguler, $kode);
                    $spt = (new Spt)->forceFill([
                        'irban_id' => $irban->id, 'klasifikasi_id' => $reguler->id, 'kode_arsip_id' => $kode->id, 'pkpt_item_id' => $i->id, 'jenis_penugasan_id' => $i->jenis_penugasan_id,
                        'uraian_untuk' => 'Pemeriksaan reguler sesuai PKPT.', 'periode_diperiksa' => 'Tahun Anggaran '.($mulai->year - 1),
                        'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai, 'status_pengajuan' => StatusPengajuan::Diterima,
                        'dibuat_oleh' => $user->id, 'diinput_oleh' => $penginput, 'diajukan_pada' => $mulai->subDays(10),
                        'nomor_urut' => $nomor['nomor_urut'], 'nomor_lengkap' => $nomor['nomor_lengkap'], 'nomor_counter_id' => $nomor['counter']->id,
                        'tahun' => $mulai->year, 'tanggal_spt' => $mulai->subDays(7),
                        'nomor_lhp' => 'LHP/'.$mulai->year.'/R'.($k + 1), 'tanggal_lhp' => $selesai->addDays(5)->toDateString(),
                        'ditandai_selesai_pada' => $selesai->addDays(5)->setTime(10, 0), 'ditandai_selesai_oleh' => $user->id,
                    ]);
                    $spt->save();
                    DB::table('spt_objek')->insert(['spt_id' => $spt->id, 'objek_id' => $kecamatan[$k % count($kecamatan)]]);
                    $k++;
                }, attempts: 3);
            }
        }
    }
}
