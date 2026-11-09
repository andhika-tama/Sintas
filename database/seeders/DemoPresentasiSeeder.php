<?php

namespace Database\Seeders;

use App\Actions\Pkpt\Perubahan\AjukanPerubahan;
use App\Actions\Pkpt\Perubahan\BuatPerubahan;
use App\Actions\Pkpt\Perubahan\PutuskanPerubahan;
use App\Actions\Pkpt\Perubahan\SimpanOperasi;
use App\Actions\Pkpt\SahkanPkpt;
use App\Actions\Pkpt\SavePkpt;
use App\Actions\Pkpt\SavePkptItem;
use App\Actions\Spt\MintaRevisi;
use App\Actions\Spt\SimpanKendala;
use App\Actions\Spt\SimpanSpt;
use App\Actions\Spt\TandaiSelesai;
use App\Actions\Spt\TerimaPengajuan;
use App\Actions\Spt\TolakPengajuan;
use App\Actions\Spt\UnggahDokumenSpt;
use App\Enums\Role;
use App\Enums\StatusSpt;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\IrbanWilayah;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use App\Services\PenandaSpt;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Dev only (Fase 11 E11), run last: presentation data for Irban I–IV and Irban Khusus on top of the Irban I demo of the other seeders.
 * PKPT 2025 (made and disahkan through the actions), 8 SPT per irban in 2025, every 2026 status per irban, two Perubahan PKPT 2026.
 * Writes go through the real actions at a travelled clock, so numbering, riwayat, activity log, cache refresh and bell entries are
 * produced by the app itself. 2026 dates are relative to today (clamped into 2026 by DatabaseSeeder), so the statuses stay true on presentation day.
 */
class DemoPresentasiSeeder extends Seeder
{
    /** [irban, pegawai (PPJ first), kecamatan of its wilayah]; Irban I keeps the pegawai and wilayah of TimSeeder/IrbanWilayahSeeder. */
    private const IRBAN = [
        'Irban II' => [['Hendra Saputra, S.E., M.Si.', 'Rina Marlina, S.E.', 'Doni Prasetyo, S.H.', 'Yulia Anggraini, S.Ak.'], ['Tanjung Agung', 'Semende Darat Laut']],
        'Irban III' => [['Ir. Bambang Sutrisno, M.M.', 'Siti Rahmawati, S.E.', 'Fajar Nugroho, S.T.', 'Dewi Kartika, S.Ak.'], ['Rambang', 'Lubai']],
        'Irban IV' => [['Drs. Syamsul Bahri, M.Si.', 'Nurhayati, S.E.', 'Agus Setiawan, S.Kom.', 'Lestari Wulandari, S.H.'], ['Gelumbang', 'Lembak']],
        'Irban Khusus' => [['Zulkifli Hasan, S.H., M.H.', 'Mega Puspita, S.E.', 'Rudi Hartono, S.T.', 'Intan Permatasari, S.Ak.'], ['Sungai Rotan', 'Muara Belida']],
    ];

    /** Months of the eight 2025 items per irban; every 2025 SPT is marked Selesai with LHP (the late case is 2026 only). */
    private const BULAN_2025 = [2, 3, 4, 5, 7, 8, 9, 10];

    private CarbonImmutable $hari;

    private User $admin;

    private User $umum;

    private User $pimpinan;

    private Klasifikasi $reguler;

    private Klasifikasi $khusus;

    private string $pdf;

    /** The clock before seeding (a test's travelTo, or null = real time); restored after every travelled call. */
    private ?CarbonInterface $jam;

    public function run(): void
    {
        if (app()->isProduction() || ! User::where('username', 'irban2')->value('irban_id') || Pkpt::where('tahun', 2025)->exists()) {
            return;
        }

        $this->jam = Carbon::getTestNow();
        $asli = [config('queue.default'), config('mail.default')];
        // Bell entries are written at once (database channel is sync); the queued emails go nowhere instead of piling up in `jobs`.
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        $this->hari = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $this->umum = User::where('username', 'bagianumum')->firstOrFail();
        $this->pimpinan = User::where('username', 'pimpinan')->firstOrFail();
        $this->reguler = Klasifikasi::where('kode', 'REGULER')->firstOrFail();
        $this->khusus = Klasifikasi::where('kode', 'KHUSUS')->firstOrFail();
        $this->pdf = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($this->pdf, PdfDemo::isi());

        try {
            $tim = $this->siapkanIrban();
            $this->tahun2025($tim);
            foreach ($tim as $t) {
                $this->tahun2026($t);
            }
            $this->perubahan();
            // Older bell entries count as read; the last week stays unread so every role opens on something new.
            DatabaseNotification::whereNull('read_at')->where('created_at', '<', $this->hari->subDays(7))->update(['read_at' => DB::raw('created_at')]);
        } finally {
            config(['queue.default' => $asli[0], 'mail.default' => $asli[1]]);
            Auth::forgetUser();
            Context::forget('diinput_oleh');
            @unlink($this->pdf);
        }
    }

    /** Runs $f as $user at $waktu (Jakarta), like one request: the "Diinput oleh" log context does not leak into the next call. */
    private function pada(CarbonImmutable $waktu, User $user, callable $f): mixed
    {
        Carbon::setTestNow($waktu);
        Auth::setUser($user);
        try {
            return $f();
        } finally {
            Context::forget('diinput_oleh');
            Carbon::setTestNow($this->jam);
        }
    }

    private function berkas(string $nama): UploadedFile
    {
        return new UploadedFile($this->pdf, $nama, 'application/pdf', null, true);
    }

    /**
     * Pegawai, komposisi 2025 + 2026 and wilayah 2026 for Irban II–V; komposisi 2025 for Irban I.
     *
     * @return list<array{irban: Irban, user: User, pegawai: list<int>, kecamatan: list<int>}>
     */
    private function siapkanIrban(): array
    {
        $tahunIni = $this->hari->year;
        $irbanI = Irban::where('nama', 'Irban I')->firstOrFail();
        $komposisi = IrbanTahunan::where(['irban_id' => $irbanI->id, 'tahun' => $tahunIni])->firstOrFail();
        $data = [['Irban I', [$komposisi->ppj_pegawai_id, ...$komposisi->anggota()->orderBy('pegawai.id')->pluck('pegawai.id')->all()], null]];
        foreach (self::IRBAN as $nama => [$pegawai, $kecamatan]) {
            $ids = array_map(fn ($n) => Pegawai::factory()->create(['nama_bergelar' => $n, 'jabatan' => 'Auditor'])->id, $pegawai);
            $data[] = [$nama, $ids, $kecamatan];
        }

        $hasil = [];
        foreach ($data as [$nama, $pegawai, $kecamatan]) {
            $irban = Irban::where('nama', $nama)->firstOrFail();
            foreach ([2025, $tahunIni] as $tahun) {
                $k = IrbanTahunan::firstOrCreate(['irban_id' => $irban->id, 'tahun' => $tahun], ['ppj_pegawai_id' => $pegawai[0]]);
                if ($k->wasRecentlyCreated) {
                    $k->anggota()->attach(array_slice($pegawai, 1));
                }
            }
            foreach ($kecamatan ?? [] as $kec) {
                IrbanWilayah::firstOrCreate(['tahun' => $tahunIni, 'objek_id' => Objek::firstOrCreate(['nama' => $kec, 'jenis' => 'kecamatan', 'parent_id' => null])->id],
                    ['irban_id' => $irban->id]);
            }
            $hasil[] = [
                'irban' => $irban, 'user' => User::where(['irban_id' => $irban->id, 'role' => Role::Irban])->orderBy('id')->firstOrFail(), 'pegawai' => $pegawai,
                'kecamatan' => IrbanWilayah::where(['irban_id' => $irban->id, 'tahun' => $tahunIni])->whereHas('objek', fn ($q) => $q->where('jenis', 'kecamatan'))
                    ->orderBy('objek_id')->pluck('objek_id')->all(),
            ];
        }

        return $hasil;
    }

    /** SimpanSpt payload: PPJ = first pegawai, the second one is "Diinput oleh" and Ketua, the rest Anggota. */
    private function isi(array $t, Klasifikasi $k, ?PkptItem $item, CarbonImmutable $mulai, CarbonImmutable $selesai, int $objek, string $uraian): array
    {
        $p = $t['pegawai'];

        return [
            'klasifikasi_id' => $k->id, 'pkpt_item_id' => $item?->id, 'jenis_penugasan_id' => $item?->jenis_penugasan_id ?? JenisPenugasan::orderBy('id')->value('id'),
            'objek_ids' => [$objek], 'periode_diperiksa' => 'Tahun Anggaran '.($mulai->year - 1), 'uraian_untuk' => $uraian,
            'tanggal_mulai' => $mulai->toDateString(), 'tanggal_selesai' => $selesai->toDateString(), 'ppj_pegawai_id' => $p[0], 'diinput_oleh' => $p[1],
            'personel' => [['pegawai_id' => $p[1], 'peran' => 'ketua'], ['pegawai_id' => $p[2], 'peran' => 'anggota'], ['pegawai_id' => $p[3], 'peran' => 'anggota']],
        ];
    }

    /** Submit → accept → final SPT uploaded → (optionally) Selesai with LHP, each step at its own moment. */
    private function jalankan(array $t, array $isi, CarbonImmutable $mulai, CarbonImmutable $selesai, ?CarbonImmutable $lhp, int $diajukanHari = 12): Spt
    {
        $u = $t['user'];
        $spt = $this->pada($mulai->subDays($diajukanHari)->setTime(9, 10), $u, fn () => app(SimpanSpt::class)->handle($u, null, $isi, 'ajukan'));
        $this->pada($mulai->subDays($diajukanHari - 2)->setTime(10, 30), $this->umum, fn () => app(TerimaPengajuan::class)->handle($this->umum, $spt));
        $diinput = ['diinput_oleh' => $isi['diinput_oleh']];
        $unggah = $mulai->subDays(2)->min($this->hari->subDay()); // never later than yesterday
        $this->pada($unggah->setTime(14, 0), $u, fn () => app(UnggahDokumenSpt::class)->handle($u, $spt->fresh(), $diinput, $this->berkas('SPT-final-bertanda-tangan.pdf')));
        if ($lhp) {
            $this->pada($lhp->setTime(11, 0), $u, fn () => app(TandaiSelesai::class)->handle($u, $spt->fresh(), $diinput + [
                'nomor_lhp' => '700/'.$spt->fresh()->nomor_urut.'/LHP/'.$t['irban']->kode_nomor.'/'.$lhp->year, 'tanggal_lhp' => $lhp->toDateString(),
            ], $this->berkas('Cover-LHP.pdf')));
        }

        return $spt->fresh();
    }

    /** PKPT 2025 drafted, filled and disahkan by Admin on 2 January 2025; eight SPT per irban on its items. */
    private function tahun2025(array $tim): void
    {
        $jenis = JenisPenugasan::orderBy('id')->limit(8)->pluck('id')->all();
        $pkpt = $this->pada(CarbonImmutable::create(2025, 1, 2, 8, 0, 0, 'Asia/Jakarta'), $this->admin, function () use ($tim, $jenis) {
            $pkpt = app(SavePkpt::class)->handle(null, ['tahun' => 2025, 'nomor_sk' => '100.3.3.2/012/KPTS/INSPEKTORAT/2025', 'tanggal_sk' => '2025-01-02']);
            foreach ($tim as $n => $t) {
                foreach (self::BULAN_2025 as $k => $bulan) {
                    app(SavePkptItem::class)->handle($pkpt, null, ['irban_id' => $t['irban']->id, 'jenis_penugasan_id' => $jenis[($n + $k) % count($jenis)],
                        'tanggal_mulai' => sprintf('2025-%02d-02', $bulan), 'tanggal_selesai' => sprintf('2025-%02d-24', $bulan), 'target_spt' => 1]);
                }
            }

            return $pkpt;
        });
        $this->pada(CarbonImmutable::create(2025, 1, 2, 10, 0, 0, 'Asia/Jakarta'), $this->admin, fn () => app(SahkanPkpt::class)->handle($pkpt, $this->admin));

        foreach ($tim as $n => $t) {
            $item = $pkpt->items()->where('irban_id', $t['irban']->id)->orderBy('tanggal_mulai')->with('jenisPenugasan')->get();
            foreach ($item as $k => $i) {
                $mulai = CarbonImmutable::parse($i->tanggal_mulai, 'Asia/Jakarta')->addDays(2 + $n);
                $selesai = $mulai->addDays(9);
                $objek = $t['kecamatan'][$k % count($t['kecamatan'])];
                $uraian = $i->jenisPenugasan->nama.' di Kecamatan '.Objek::whereKey($objek)->value('nama').'.';
                $this->jalankan($t, $this->isi($t, $this->reguler, $i, $mulai, $selesai, $objek, $uraian), $mulai, $selesai,
                    $selesai->addDays(6));
            }
        }
    }

    /** Labels of this irban's 2026 SPT: Spt::status(), with a late Penyusunan Laporan counted as `terlambat` (PenandaSpt). @return list<string> */
    private function adaStatus(Irban $irban): array
    {
        $penanda = app(PenandaSpt::class);

        return Spt::where('irban_id', $irban->id)->tahunPenugasan($this->hari->year)->get()
            ->map(fn (Spt $s) => in_array('terlambat', $penanda->untuk($s), true) ? 'terlambat' : $s->status()->value)->unique()->values()->all();
    }

    /** A Disahkan item written as SahkanPkpt would (snapshot = dates); a Disahkan PKPT takes new items only through a Perubahan otherwise. */
    private function item(Irban $irban, int $jenis, CarbonImmutable $mulai, CarbonImmutable $selesai): PkptItem
    {
        $pkpt = Pkpt::where('tahun', $this->hari->year)->firstOrFail();

        return $pkpt->items()->create(['irban_id' => $irban->id, 'jenis_penugasan_id' => $jenis, 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai,
            'tanggal_mulai_disahkan' => $mulai, 'tanggal_selesai_disahkan' => $selesai, 'target_spt' => 1]);
    }

    /**
     * Each 2026 status this irban does not have yet (Irban I already has most from SptSeeder). Dates are relative to today.
     * Dates run from today−62 to today+39 days; DatabaseSeeder clamps "today" to DEMO_AWAL…DEMO_AKHIR so they stay inside 2026.
     * shortcut: seeded on a real date after DEMO_AKHIR, statuses computed against that date drift (Siap/Pelaksanaan become late); move the demo year when 2026 is over.
     */
    private function tahun2026(array $t): void
    {
        $h = $this->hari;
        $ada = $this->adaStatus($t['irban']);
        $u = $t['user'];
        $jenis = JenisPenugasan::orderBy('id')->pluck('id')->all();
        $j = fn (int $n) => $jenis[($t['irban']->id + $n) % count($jenis)];
        $kec = fn (int $n) => $t['kecamatan'][$n % count($t['kecamatan'])];
        $ajukan = fn (array $isi, int $hariLalu) => $this->pada($h->subDays($hariLalu)->setTime(9, 0), $u, fn () => app(SimpanSpt::class)->handle($u, null, $isi, 'ajukan'));

        $buat = [
            StatusSpt::Draf->value => fn () => $this->pada($h->subDay()->setTime(8, 30), $u, fn () => app(SimpanSpt::class)->handle($u, null,
                $this->isi($t, $this->khusus, null, $h->addDays(30), $h->addDays(39), $kec(0), 'Pemeriksaan khusus atas pengaduan masyarakat (draf).'), 'draf')),
            StatusSpt::MenungguNomor->value => fn () => $ajukan($this->isi($t, $this->reguler, $this->item($t['irban'], $j(1), $h->addDays(14), $h->addDays(25)),
                $h->addDays(15), $h->addDays(24), $kec(1), 'Pemeriksaan reguler sesuai jadwal PKPT.'), 1),
            StatusSpt::PerluRevisi->value => function () use ($t, $h, $kec, $ajukan) {
                $spt = $ajukan($this->isi($t, $this->khusus, null, $h->addDays(20), $h->addDays(29), $kec(0), 'Pemeriksaan khusus pengelolaan aset daerah.'), 4);
                $this->pada($h->subDays(3)->setTime(13, 0), $this->umum, fn () => app(MintaRevisi::class)->handle($this->umum, $spt,
                    'Lengkapi periode yang diperiksa dan tambahkan anggota tim sesuai surat permintaan.'));
            },
            StatusSpt::Ditolak->value => function () use ($t, $h, $kec, $ajukan) {
                $spt = $ajukan($this->isi($t, $this->khusus, null, $h->addDays(10), $h->addDays(19), $kec(1), 'Pemeriksaan khusus belanja perjalanan dinas.'), 6);
                $this->pada($h->subDays(5)->setTime(15, 0), $this->umum, fn () => app(TolakPengajuan::class)->handle($this->umum, $spt,
                    'Penugasan ini sudah tercakup dalam SPT lain pada bulan yang sama.'));
            },
            // Non-PKPT (Khusus): no item.
            StatusSpt::SiapDilaksanakan->value => fn () => $this->jalankan($t, $this->isi($t, $this->khusus, null, $h->addDays(7), $h->addDays(16), $kec(0),
                'Pemeriksaan khusus atas permintaan Bupati.'), $h->addDays(7), $h->addDays(16), null, 11),
            // Outside its item's window: Tidak Sesuai PKPT, so the Irban filled in a kendala.
            StatusSpt::Pelaksanaan->value => function () use ($t, $h, $j, $kec, $u) {
                $spt = $this->jalankan($t, $this->isi($t, $this->reguler, $this->item($t['irban'], $j(2), $h->subDays(20), $h->subDays(8)), $h->subDays(3), $h->addDays(5),
                    $kec(1), 'Pemeriksaan reguler yang bergeser dari jadwal PKPT.'), $h->subDays(3), $h->addDays(5), null, 18);
                $this->pada($h->subDays(3)->setTime(10, 5), $u, fn () => app(SimpanKendala::class)->handle($u, $spt, ['diinput_oleh' => $t['pegawai'][1],
                    'kategori' => 'Objek tidak siap', 'uraian' => 'Pemeriksaan bergeser dari jadwal PKPT karena objek belum siap menyerahkan dokumen.']));
            },
            StatusSpt::PenyusunanLaporan->value => fn () => $this->jalankan($t, $this->isi($t, $this->reguler, $this->item($t['irban'], $j(3), $h->subDays(14), $h->subDay()),
                $h->subDays(12), $h->subDays(3), $kec(0), 'Pemeriksaan reguler masa penyusunan laporan.'), $h->subDays(12), $h->subDays(3), null),
            'terlambat' => fn () => $this->jalankan($t, $this->isi($t, $this->reguler, $this->item($t['irban'], $j(4), $h->subDays(42), $h->subDays(28)),
                $h->subDays(40), $h->subDays(30), $kec(1), 'Pemeriksaan reguler yang laporannya terlambat.'), $h->subDays(40), $h->subDays(30), null),
            StatusSpt::Selesai->value => fn () => $this->jalankan($t, $this->isi($t, $this->reguler, $this->item($t['irban'], $j(5), $h->subDays(62), $h->subDays(48)),
                $h->subDays(60), $h->subDays(50), $kec(0), 'Pemeriksaan reguler yang sudah selesai.'), $h->subDays(60), $h->subDays(50), $h->subDays(45)),
        ];
        foreach ($buat as $status => $f) {
            if (! in_array($status, $ada, true)) {
                $f();
            }
        }
    }

    /**
     * Perubahan PKPT this year: one approved, one waiting for Pimpinan; each brings forward by a week a new future item of Irban II / III.
     * Not an Irban I item: PkptIrbanIDemo2026Seeder matches those by their dates on the next seed run.
     */
    private function perubahan(): void
    {
        $pkpt = Pkpt::where('tahun', $this->hari->year)->firstOrFail();
        $jenis = JenisPenugasan::orderBy('id')->value('id');
        $bebas = array_map(fn ($nama) => $this->item(Irban::where('nama', $nama)->firstOrFail(), $jenis, $this->hari->addDays(28), $this->hari->addDays(39)), ['Irban II', 'Irban III']);
        $geser = fn (PkptItem $i) => ['aksi' => 'ubah', 'pkpt_item_id' => $i->id,
            'tanggal_mulai' => CarbonImmutable::parse($i->tanggal_mulai)->subWeek()->toDateString(), 'tanggal_selesai' => CarbonImmutable::parse($i->tanggal_selesai)->subWeek()->toDateString()];

        foreach ([[$bebas[0], 5, 'Jadwal dimajukan satu minggu karena bersamaan dengan pemeriksaan BPK.'], [$bebas[1], 2, 'Jadwal dimajukan satu minggu menyesuaikan agenda pemerintah daerah.']] as $n => [$item, $hariLalu, $alasan]) {
            $mulai = $this->hari->subDays($hariLalu);
            $p = $this->pada($mulai->setTime(9, 0), $this->admin, function () use ($pkpt, $alasan, $geser, $item) {
                $p = app(BuatPerubahan::class)->handle($this->admin, $pkpt, ['jenis' => 'perubahan', 'alasan' => $alasan]);
                app(SimpanOperasi::class)->handle($this->admin, $p, null, $geser($item));

                return app(AjukanPerubahan::class)->handle($this->admin, $p);
            });
            if ($n === 0) {
                $this->pada($mulai->addDay()->setTime(10, 0), $this->pimpinan, fn () => app(PutuskanPerubahan::class)->handle($this->pimpinan, $p, true, 'Disetujui.'));
            }
        }
    }
}
