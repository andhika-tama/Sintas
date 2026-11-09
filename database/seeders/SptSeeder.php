<?php

namespace Database\Seeders;

use App\Enums\KejadianSpt;
use App\Enums\StatusPengajuan;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Pegawai;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use App\Services\Penomoran;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Dev only. Run after IrbanSeeder and UserSeeder. Numbered SPT take their numbers through Penomoran.
class SptSeeder extends Seeder
{
    public function run(): void
    {
        $irban = Irban::where('nama', 'Irban I')->first();
        $user = User::where('username', 'irban1')->first();
        if (app()->isProduction() || ! $irban || ! $user || Spt::where('irban_id', $irban->id)->exists()) {
            return;
        }

        $tahun = now('Asia/Jakarta')->year;
        $khusus = Klasifikasi::where('kode', 'KHUSUS')->first();
        $kode = KodeArsip::where('kode', '700')->firstOrFail();
        $penginput = Pegawai::orderBy('id')->value('id');
        $penomoran = app(Penomoran::class);

        $buat = function (StatusPengajuan $status, string $uraian, int $bulan, bool $nomor = false) use ($irban, $user, $khusus, $kode, $tahun, $penginput, $penomoran) {
            $mulai = CarbonImmutable::create($tahun, $bulan, 5);
            DB::transaction(function () use ($status, $uraian, $mulai, $nomor, $irban, $user, $khusus, $kode, $penginput, $penomoran) {
                $spt = (new Spt)->forceFill([
                    'irban_id' => $irban->id, 'klasifikasi_id' => $khusus->id, 'kode_arsip_id' => $kode->id, 'uraian_untuk' => $uraian, 'periode_diperiksa' => 'Tahun Anggaran '.($mulai->year - 1),
                    'tanggal_mulai' => $mulai, 'tanggal_selesai' => $mulai->addDays(9), 'status_pengajuan' => $status, 'dibuat_oleh' => $user->id,
                    'diinput_oleh' => $penginput, 'diajukan_pada' => $status === StatusPengajuan::Draf ? null : now(),
                ]);
                if ($nomor) {
                    $n = $penomoran->ambil($irban, $mulai, $khusus, $kode);
                    $spt->forceFill(['nomor_urut' => $n['nomor_urut'], 'nomor_lengkap' => $n['nomor_lengkap'], 'nomor_counter_id' => $n['counter']->id,
                        'tahun' => $mulai->year, 'tanggal_spt' => $mulai]);
                }
                $spt->save();
            }, attempts: 3);
        };

        $buat(StatusPengajuan::Draf, 'Pemeriksaan khusus pengelolaan dana desa (draf).', 11);
        foreach ([1, 2, 3] as $i) {
            $buat(StatusPengajuan::MenungguNomor, "Pemeriksaan khusus menunggu nomor #{$i}.", 10 + ($i % 3));
        }
        $buat(StatusPengajuan::PerluRevisi, 'Pemeriksaan khusus yang perlu direvisi.', 9);
        $buat(StatusPengajuan::Diterima, 'Pemeriksaan khusus yang sudah bernomor.', 3, true);
        $buat(StatusPengajuan::Diterima, 'Pemeriksaan khusus kedua yang sudah bernomor.', 4, true);
        $buat(StatusPengajuan::Dibatalkan, 'Pemeriksaan khusus yang nomornya dibatalkan.', 5, true);

        $this->pelaksanaan($irban, $user, $khusus, $kode, $penginput, $penomoran);
    }

    /** SPT diterima in every execution state. Dates are relative to today so each status stays true over time. */
    private function pelaksanaan(Irban $irban, User $user, Klasifikasi $khusus, KodeArsip $kode, ?int $penginput, Penomoran $penomoran): void
    {
        $hari = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        // An item whose window does not include today, so an SPT linked to it is Tidak Sesuai PKPT (PRD §9.3).
        $item = PkptItem::aktif()->where('irban_id', $irban->id)->whereDate('tanggal_selesai', '<', $hari)->orderByDesc('tanggal_selesai')->first();

        $riwayat = function (Spt $spt, string $kejadian, CarbonImmutable $waktu, ?array $lama = null, ?array $baru = null, ?string $alasan = null) use ($user, $penginput) {
            $spt->riwayat()->create(['kejadian' => $kejadian, 'nilai_lama' => $lama, 'nilai_baru' => $baru, 'alasan' => $alasan, 'oleh' => $user->id, 'diinput_oleh' => $penginput])
                ->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->save();
        };
        $pdf = function (Spt $spt, string $jenis, string $nama, CarbonImmutable $waktu) use ($user, $penginput, $riwayat) {
            $isi = PdfDemo::isi();
            $path = "spt/{$spt->id}/".Str::uuid().'.pdf';
            Storage::disk('local')->put($path, $isi);
            $spt->dokumen()->create(['jenis' => $jenis, 'path' => $path, 'nama_asli' => $nama, 'ukuran' => strlen($isi), 'sha256' => hash('sha256', $isi),
                'diunggah_oleh' => $user->id, 'diinput_oleh' => $penginput])->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->save();
            if ($jenis === 'final') {
                $riwayat($spt, KejadianSpt::FINAL_DIUNGGAH, $waktu, null, ['nama_asli' => $nama, 'sha256' => hash('sha256', $isi)]);
            }
        };

        // [uraian, mulai, selesai, final?, item?, selesai pada (hari ke belakang)?, kendala?]
        $daftar = [
            ['Pemeriksaan khusus yang sedang dilaksanakan.', -5, 10, true, false, null, false],
            ['Pemeriksaan khusus masa penyusunan laporan.', -20, -5, false, false, null, false],
            ['Pemeriksaan khusus penyusunan laporan terlambat.', -45, -30, true, false, null, false],
            ['Pemeriksaan khusus yang sudah selesai.', -60, -50, true, false, 47, false],
            ['Pemeriksaan khusus di luar jadwal PKPT.', -2, 8, true, true, null, true],
        ];
        // Personel come from this irban's komposisi (PPJ + anggota): Ketua and one Anggota, so ValidatePersonel and the PPJ rule hold.
        $komposisi = IrbanTahunan::where(['irban_id' => $irban->id, 'tahun' => $hari->year])->first();
        $ppj = $komposisi?->ppj_pegawai_id;
        $tim = $komposisi ? $komposisi->anggota()->orderBy('pegawai.id')->limit(2)->pluck('pegawai.id')->all() : Pegawai::orderBy('id')->limit(2)->pluck('id')->all();
        foreach ($daftar as [$uraian, $m, $s, $final, $denganItem, $selesaiHari, $kendala]) {
            if ($denganItem && ! $item) {
                continue;
            }
            $mulai = $hari->addDays($m);
            $dibuat = $mulai->subDays(12)->setTime(9, 15);
            DB::transaction(function () use ($uraian, $mulai, $hari, $s, $final, $denganItem, $selesaiHari, $kendala, $item, $irban, $user, $khusus, $kode, $penginput, $penomoran, $riwayat, $pdf, $dibuat, $tim, $ppj) {
                $n = $penomoran->ambil($irban, $mulai, $khusus, $kode);
                $spt = (new Spt)->forceFill([
                    'irban_id' => $irban->id, 'klasifikasi_id' => $khusus->id, 'kode_arsip_id' => $kode->id, 'pkpt_item_id' => $denganItem ? $item->id : null,
                    'jenis_penugasan_id' => $denganItem ? $item->jenis_penugasan_id : null, 'ppj_pegawai_id' => $ppj, 'uraian_untuk' => $uraian,
                    'periode_diperiksa' => 'Tahun Anggaran '.($mulai->year - 1), 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $hari->addDays($s),
                    'status_pengajuan' => StatusPengajuan::Diterima, 'dibuat_oleh' => $user->id, 'diinput_oleh' => $penginput, 'diajukan_pada' => $dibuat->addHour(),
                    'nomor_urut' => $n['nomor_urut'], 'nomor_lengkap' => $n['nomor_lengkap'], 'nomor_counter_id' => $n['counter']->id,
                    'tahun' => $mulai->year, 'tanggal_spt' => $mulai->subDays(7),
                ]);
                $spt->save();
                $riwayat($spt, KejadianSpt::DIBUAT, $dibuat, null, ['status_pengajuan' => 'draf']);
                $riwayat($spt, KejadianSpt::DIAJUKAN, $dibuat->addHour(), ['status_pengajuan' => 'draf'], ['status_pengajuan' => 'menunggu_nomor']);
                $riwayat($spt, KejadianSpt::DITERIMA, $dibuat->addDay(), null, ['nomor_lengkap' => $n['nomor_lengkap']]);
                foreach ($tim as $u => $pegawaiId) {
                    $spt->personel()->create(['pegawai_id' => $pegawaiId, 'peran' => $u === 0 ? 'ketua' : 'anggota', 'urutan' => $u + 1, 'sumber' => 'tambahan']);
                }
                if ($final) {
                    $pdf($spt, 'final', 'SPT-final-bertanda-tangan.pdf', $mulai->subDays(3)->setTime(14, 30));
                }
                if ($kendala) {
                    $isi = ['kategori' => 'Objek tidak siap', 'uraian' => 'Pemeriksaan bergeser dari jadwal PKPT karena objek belum siap menyerahkan dokumen.'];
                    $spt->kendala()->create($isi + ['diinput_oleh' => $penginput, 'dibuat_oleh' => $user->id])
                        ->forceFill(['created_at' => $mulai->setTime(10, 5), 'updated_at' => $mulai->setTime(10, 5)])->save();
                    $riwayat($spt, KejadianSpt::KENDALA_DIISI, $mulai->setTime(10, 5), null, $isi);
                }
                if ($selesaiHari) {
                    $waktu = $hari->subDays($selesaiHari)->setTime(11, 20);
                    $pdf($spt, 'cover_lhp', 'Cover-LHP.pdf', $waktu);
                    $lhp = ['nomor_lhp' => 'LHP/'.$mulai->year.'/001', 'tanggal_lhp' => $waktu->toDateString()];
                    $spt->forceFill($lhp + ['ditandai_selesai_pada' => $waktu, 'ditandai_selesai_oleh' => $user->id])->save();
                    $riwayat($spt, KejadianSpt::DITANDAI_SELESAI, $waktu, null, ['syarat' => 'lhp_lengkap'] + $lhp);
                }
            }, attempts: 3);
        }
    }
}
