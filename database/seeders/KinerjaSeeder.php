<?php

namespace Database\Seeders;

use App\Enums\KejadianSpt;
use App\Enums\Role;
use App\Enums\StatusPengajuan;
use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\User;
use App\Services\ColorRules;
use App\Services\Penomoran;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Performance data (Fase 11 E5): 5 irban, the last 5 years, 1.000 accepted SPT per year, Disahkan PKPT with 24 items per
 * irban per year, objek, personel, dokumen rows (no files), a Diterima riwayat row per SPT, ±300 komentar and ±2.000 activity_log rows.
 * Run after `migrate:fresh` only: `php artisan db:seed --class=KinerjaSeeder`. Never called by DatabaseSeeder.
 * Deterministic (seeded Mt19937); statuses of the current year follow today's date. Seed data, so bulk query-builder inserts.
 */
class KinerjaSeeder extends Seeder
{
    /** E5 shape; KinerjaDasborTest seeds a smaller set with the same code. */
    public int $sptPerTahun = 1000;

    public int $jumlahTahun = 5;

    private array $buffer = [];

    /** Own generator: other code consuming the global mt_rand state cannot change the data. */
    private Randomizer $rng;

    public function run(): void
    {
        // Creates accounts with password `password` and bulk-inserts SPT past the cache refresh: never on a real server.
        if (app()->isProduction()) {
            $this->command?->error('KinerjaSeeder hanya untuk pengukuran lokal; tidak dijalankan di produksi.');

            return;
        }

        $this->rng = new Randomizer(new Mt19937(2026));
        $sekarang = CarbonImmutable::now('Asia/Jakarta');
        $hariIni = $sekarang->toDateString();
        $tahunIni = $sekarang->year;
        $stempel = $sekarang->format('Y-m-d H:i:s');

        $irban = collect(IrbanSeeder::DAFTAR)->map(fn ($kode, $nama) => Irban::firstOrCreate(['nama' => $nama], ['kode_nomor' => $kode]))->values();
        $user = [];
        foreach ([['admin', 'Administrator', Role::Admin], ['pimpinan', 'Inspektur', Role::Pimpinan], ['bagianumum', 'Bagian Umum', Role::BagianUmum]] as [$u, $nama, $role]) {
            $user[$u] = $this->akun($u, $nama, $role, null);
        }
        foreach ($irban as $i => $ir) {
            $user['irban'.($i + 1)] = $this->akun('irban'.($i + 1), $ir->nama, Role::Irban, $ir->id);
        }

        // 12 pegawai per irban.
        $pegawaiAwal = (int) DB::table('pegawai')->max('id');
        for ($p = 1; $p <= 60; $p++) {
            $this->tambah('pegawai', ['nama_bergelar' => "Pegawai Kinerja {$p}, S.E.", 'nip' => sprintf('%018d', 198001012000000000 + $p),
                'jabatan' => 'Auditor', 'aktif' => true, 'created_at' => $stempel, 'updated_at' => $stempel]);
        }
        $this->kirim('pegawai');
        // Auto-increment ids need not be contiguous (MySQL keeps rolled-back ids), so read them back.
        $idPegawai = DB::table('pegawai')->where('id', '>', $pegawaiAwal)->orderBy('id')->pluck('id')->all();
        $pegawai = fn (int $i) => array_slice($idPegawai, $i * 12, 12);

        $jenis = collect(range(0, 5))->map(fn ($i) => JenisPenugasan::firstOrCreate(['nama' => 'Jenis Kinerja '.($i + 1)],
            ['warna' => ColorRules::SWATCHES[$i]['hex'], 'warna_teks' => ColorRules::SWATCHES[$i]['label']])->id)->all();
        $klasifikasi = Klasifikasi::pluck('id', 'kode');

        // Objek: 20 kecamatan (each with 3 desa, a sekolah and a puskesmas), 15 dinas/badan, 2 BUMD; per irban 4 kecamatan + 3–4 dinas/BUMD.
        $objekIrban = array_fill(0, 5, []);
        $wilayah = array_fill(0, 5, []);
        for ($k = 0; $k < 20; $k++) {
            $kec = DB::table('objek')->insertGetId(['nama' => "Kecamatan Kinerja {$k}", 'jenis' => 'kecamatan', 'parent_id' => null, 'created_at' => $stempel, 'updated_at' => $stempel]);
            $wilayah[$k % 5][] = $kec;
            $objekIrban[$k % 5][] = $kec;
            foreach ([['Desa A', 'desa'], ['Desa B', 'desa'], ['Desa C', 'desa'], ['SD Negeri', 'sekolah'], ['Puskesmas', 'puskesmas']] as [$n, $j]) {
                $objekIrban[$k % 5][] = DB::table('objek')->insertGetId(['nama' => "{$n} Kinerja {$k}", 'jenis' => $j, 'parent_id' => $kec, 'created_at' => $stempel, 'updated_at' => $stempel]);
            }
        }
        for ($d = 0; $d < 17; $d++) {
            $id = DB::table('objek')->insertGetId(['nama' => $d < 15 ? "Dinas Kinerja {$d}" : "BUMD Kinerja {$d}", 'jenis' => $d < 15 ? 'dinas_badan' : 'bumd',
                'parent_id' => null, 'created_at' => $stempel, 'updated_at' => $stempel]);
            $wilayah[$d % 5][] = $id;
            $objekIrban[$d % 5][] = $id;
        }

        $penomoran = app(Penomoran::class);
        $sptId = (int) DB::table('spt')->max('id');
        $diterima = [];
        foreach (range($tahunIni - $this->jumlahTahun + 1, $tahunIni) as $tahun) {
            foreach ($wilayah as $i => $ids) {
                foreach ($ids as $o) {
                    $this->tambah('irban_wilayah', ['irban_id' => $irban[$i]->id, 'tahun' => $tahun, 'objek_id' => $o, 'created_at' => $stempel, 'updated_at' => $stempel]);
                }
            }
            $this->kirim('irban_wilayah');

            $pkptId = DB::table('pkpt')->insertGetId(['tahun' => $tahun, 'nomor_sk' => "100.3.3.2/{$tahun}/KPTS/INSPEKTORAT/{$tahun}", 'tanggal_sk' => "{$tahun}-01-02",
                'status' => 'disahkan', 'disahkan_pada' => "{$tahun}-01-02 08:00:00", 'disahkan_oleh' => $user['admin']->id, 'created_at' => $stempel, 'updated_at' => $stempel]);
            $item = array_fill(0, 5, []);
            foreach ($irban as $i => $ir) {
                for ($k = 0; $k < 24; $k++) {
                    $mulai = CarbonImmutable::create($tahun, intdiv($k, 2) + 1, $k % 2 ? 15 : 2);
                    $selesai = $mulai->addDays(10 + $this->angka(0, 3));
                    $j = $jenis[($i + $k) % count($jenis)];
                    $item[$i][] = [DB::table('pkpt_item')->insertGetId(['pkpt_id' => $pkptId, 'irban_id' => $ir->id, 'jenis_penugasan_id' => $j,
                        'tanggal_mulai' => $mulai->toDateString(), 'tanggal_selesai' => $selesai->toDateString(), 'target_spt' => 7, 'status' => 'aktif',
                        'tanggal_mulai_disahkan' => $mulai->toDateString(), 'tanggal_selesai_disahkan' => $selesai->toDateString(),
                        'created_at' => $stempel, 'updated_at' => $stempel]), $mulai, $selesai, $j];
                }
            }

            $counter = DB::table('nomor_counter')->insertGetId(['tahun' => $tahun, 'scope' => 'global', 'nomor_terakhir' => $this->sptPerTahun, 'created_at' => $stempel, 'updated_at' => $stempel]);
            for ($n = 1; $n <= $this->sptPerTahun; $n++) {
                $i = $n % 5;
                $sptId++;
                if ($this->angka(1, 100) <= 80) {
                    [$itemId, $im, $is, $j] = $item[$i][$this->angka(0, 23)];
                    $kode = 'REGULER';
                    // ±10 % outside the item (after it in the first half year, before it in the second, so the year stays): Tidak Sesuai PKPT.
                    $diluar = $this->angka(1, 100) <= 10;
                    $mulai = ! $diluar ? $im->addDays($this->angka(0, 2)) : ($im->month <= 6 ? $is->addDays($this->angka(1, 20)) : $im->subDays($this->angka(3, 20)));
                    $selesai = $diluar ? $mulai->addDays($this->angka(4, 9)) : $mulai->addDays($this->angka(4, 7))->min($is);
                } else {
                    [$itemId, $j, $diluar] = [null, $jenis[$this->angka(0, count($jenis) - 1)], false];
                    $kode = ['KHUSUS', 'DIKLAT', 'LUAR_DAERAH', 'LAIN_LAIN'][$this->angka(0, 3)];
                    $mulai = CarbonImmutable::create($tahun, 1, 2)->addDays($this->angka(0, 340));
                    $selesai = $mulai->addDays($this->angka(2, 10));
                }
                $selesaiDitandai = null;
                if ($selesai->toDateString() < $hariIni && $this->angka(1, 100) <= ($tahun < $tahunIni ? 95 : 80)) {
                    $selesaiDitandai = $selesai->addDays($this->angka(0, 20))->min($sekarang)->setTime(15, 0);
                }
                $tim = $pegawai($i);
                $this->tambah('spt', [
                    'id' => $sptId, 'irban_id' => $irban[$i]->id, 'klasifikasi_id' => $klasifikasi[$kode], 'pkpt_item_id' => $itemId, 'jenis_penugasan_id' => $j,
                    'ppj_pegawai_id' => $tim[0], 'periode_diperiksa' => 'Tahun Anggaran '.($tahun - 1), 'uraian_untuk' => "Penugasan kinerja {$tahun} nomor {$n}.",
                    'tanggal_mulai' => $mulai->toDateString(), 'tanggal_selesai' => $selesai->toDateString(), 'status_pengajuan' => StatusPengajuan::Diterima->value,
                    'nomor_urut' => $n, 'nomor_lengkap' => $penomoran->format($n, $irban[$i], $mulai, null), 'nomor_counter_id' => $counter, 'tahun' => $tahun,
                    'tanggal_spt' => $mulai->toDateString(), 'diajukan_pada' => $mulai->subDays(3)->format('Y-m-d 09:00:00'), 'sumber' => 'sistem',
                    'diinput_oleh' => $tim[1], 'dibuat_oleh' => $user['irban'.($i + 1)]->id,
                    'nomor_lhp' => $selesaiDitandai ? "700/LHP/{$sptId}/{$tahun}" : null, 'tanggal_lhp' => $selesaiDitandai?->toDateString(),
                    'ditandai_selesai_pada' => $selesaiDitandai?->format('Y-m-d H:i:s'), 'ditandai_selesai_oleh' => $selesaiDitandai ? $user['irban'.($i + 1)]->id : null,
                    'created_at' => $mulai->subDays(5)->format('Y-m-d 09:00:00'), 'updated_at' => $stempel,
                ]);
                $diterima[] = $sptId;
                $this->tambah('spt_riwayat', ['spt_id' => $sptId, 'kejadian' => KejadianSpt::DITERIMA, 'nilai_lama' => null, 'nilai_baru' => null, 'alasan' => null,
                    'oleh' => $user['bagianumum']->id, 'diinput_oleh' => null, 'created_at' => $mulai->format('Y-m-d 10:00:00'), 'updated_at' => $mulai->format('Y-m-d 10:00:00')]);
                $this->anak($sptId, $i, $objekIrban[$i], $tim, $user['irban'.($i + 1)]->id, $mulai->toDateString() < $hariIni, $selesaiDitandai !== null, $diluar, $stempel);
            }
        }
        // Inbox and drafts of the current year (not accepted): Bagian Umum and Irban lists are not empty.
        foreach ([[StatusPengajuan::MenungguNomor, 15], [StatusPengajuan::Draf, 10], [StatusPengajuan::PerluRevisi, 5], [StatusPengajuan::Ditolak, 5]] as [$status, $jumlah]) {
            for ($n = 0; $n < $jumlah; $n++) {
                $i = $n % 5;
                $sptId++;
                $mulai = $sekarang->startOfDay()->addDays($this->angka(7, 40));
                $tim = $pegawai($i);
                $this->tambah('spt', [
                    'id' => $sptId, 'irban_id' => $irban[$i]->id, 'klasifikasi_id' => $klasifikasi['KHUSUS'], 'pkpt_item_id' => null, 'jenis_penugasan_id' => $jenis[$n % count($jenis)],
                    'ppj_pegawai_id' => $tim[0], 'periode_diperiksa' => 'Tahun Anggaran '.($tahunIni - 1), 'uraian_untuk' => "Pengajuan kinerja {$status->value} {$n}.",
                    'tanggal_mulai' => $mulai->toDateString(), 'tanggal_selesai' => $mulai->addDays(9)->toDateString(), 'status_pengajuan' => $status->value,
                    'nomor_urut' => null, 'nomor_lengkap' => null, 'nomor_counter_id' => null, 'tahun' => null, 'tanggal_spt' => null,
                    'diajukan_pada' => $status === StatusPengajuan::Draf ? null : $stempel, 'sumber' => 'sistem', 'diinput_oleh' => $tim[1],
                    'dibuat_oleh' => $user['irban'.($i + 1)]->id, 'nomor_lhp' => null, 'tanggal_lhp' => null, 'ditandai_selesai_pada' => null, 'ditandai_selesai_oleh' => null,
                    'created_at' => $stempel, 'updated_at' => $stempel,
                ]);
                $this->anak($sptId, $i, $objekIrban[$i], $tim, $user['irban'.($i + 1)]->id, false, false, false, $stempel);
            }
        }
        foreach (['spt', 'spt_objek', 'spt_personel', 'spt_dokumen', 'spt_kendala', 'spt_riwayat'] as $tabel) {
            $this->kirim($tabel);
        }

        $this->komentar($diterima, $user, $stempel);
        $this->log($diterima, $user, $sekarang);
    }

    private function akun(string $username, string $nama, Role $role, ?int $irbanId): User
    {
        $u = User::firstOrNew(['username' => $username]);
        $u->forceFill(['name' => $nama, 'email' => "{$username}@sintas.test", 'role' => $role, 'irban_id' => $irbanId,
            ...($u->exists ? [] : ['password' => 'password'])])->save();

        return $u;
    }

    /** Objek, personel, dokumen (final once started, cover LHP when done) and a kendala for most Tidak Sesuai SPT. */
    private function anak(int $id, int $i, array $objek, array $tim, int $userId, bool $mulai, bool $selesai, bool $diluar, string $stempel): void
    {
        $t = ['created_at' => $stempel, 'updated_at' => $stempel];
        foreach ($this->acak($objek, $this->angka(1, 3)) as $o) {
            $this->tambah('spt_objek', ['spt_id' => $id, 'objek_id' => $o, ...$t]);
        }
        foreach ($this->acak($tim, $this->angka(3, 6)) as $u => $p) {
            $this->tambah('spt_personel', ['spt_id' => $id, 'pegawai_id' => $p, 'peran' => [0 => 'supervisor_dalnis', 1 => 'ketua'][$u] ?? 'anggota',
                'urutan' => $u + 1, 'sumber' => 'tim', 'tim_id' => null, ...$t]);
        }
        foreach (array_filter(['final' => $mulai && $this->angka(1, 100) <= 92, 'cover_lhp' => $selesai]) as $jenis => $_) {
            $this->tambah('spt_dokumen', ['spt_id' => $id, 'jenis' => $jenis, 'path' => "spt/{$id}/{$jenis}.pdf", 'nama_asli' => "{$jenis}-{$id}.pdf",
                'ukuran' => 245760, 'sha256' => hash('sha256', "{$id}-{$jenis}"), 'diunggah_oleh' => $userId, 'diinput_oleh' => $tim[1], ...$t]);
        }
        if ($diluar && $this->angka(1, 100) <= 70) {
            $this->tambah('spt_kendala', ['spt_id' => $id, 'kategori' => 'Penjadwalan ulang', 'uraian' => 'Jadwal objek bergeser.', 'diinput_oleh' => $tim[1], 'dibuat_oleh' => $userId, ...$t]);
        }
    }

    /** ±300 rows: 100 Minta Penjelasan (40 open), their replies and plain comments. */
    private function komentar(array $spt, array $user, string $stempel): void
    {
        $t = ['created_at' => $stempel, 'updated_at' => $stempel];
        $irbanUser = User::whereNotNull('irban_id')->pluck('id', 'irban_id');
        $irbanSpt = DB::table('spt')->whereIn('id', $spt)->pluck('irban_id', 'id');
        foreach ($this->acak($spt, 200) as $k => $id) {
            $minta = $k < 100;
            $terbuka = $k < 40;
            $root = DB::table('komentar')->insertGetId(['spt_id' => $id, 'parent_id' => null, 'user_id' => $user['pimpinan']->id, 'isi' => "Komentar kinerja {$k}.",
                'minta_penjelasan' => $minta, 'status_permintaan' => $minta ? ($terbuka ? 'terbuka' : 'selesai') : null,
                'ditutup_oleh' => $minta && ! $terbuka ? $user['pimpinan']->id : null, 'ditutup_pada' => $minta && ! $terbuka ? $stempel : null, ...$t]);
            if ($minta && ($k % 2 === 0 || ! $terbuka)) {
                $this->tambah('komentar', ['spt_id' => $id, 'parent_id' => $root, 'user_id' => $irbanUser[$irbanSpt[$id]], 'isi' => "Penjelasan kinerja {$k}.",
                    'minta_penjelasan' => false, 'status_permintaan' => null, 'ditutup_oleh' => null, 'ditutup_pada' => null, ...$t]);
            }
        }
        $this->kirim('komentar');
    }

    /** ±2.000 activity_log rows written directly (created + status changes of SPT), spread over the last five years. */
    private function log(array $spt, array $user, CarbonImmutable $sekarang): void
    {
        foreach ($this->acak($spt, 1000) as $k => $id) {
            foreach ([['created', ['attributes' => ['status_pengajuan' => 'menunggu_nomor']]], ['updated', ['old' => ['status_pengajuan' => 'menunggu_nomor'], 'attributes' => ['status_pengajuan' => 'diterima']]]] as $n => [$event, $props]) {
                $waktu = $sekarang->subDays($this->angka(0, 1800))->addMinutes($n)->format('Y-m-d H:i:s');
                $this->tambah('activity_log', ['log_name' => 'default', 'description' => $event, 'subject_type' => 'App\\Models\\Spt', 'subject_id' => $id,
                    'causer_type' => 'App\\Models\\User', 'causer_id' => $n ? $user['bagianumum']->id : $user['irban1']->id, 'properties' => json_encode($props),
                    'event' => $event, 'batch_uuid' => null, 'diinput_oleh' => null, 'ip' => '127.0.0.1', 'created_at' => $waktu, 'updated_at' => $waktu]);
            }
        }
        $this->kirim('activity_log');
    }

    /** @return list<mixed> $n distinct values of $daftar, in a deterministic order */
    private function acak(array $daftar, int $n): array
    {
        $daftar = array_values($daftar);
        for ($a = count($daftar) - 1; $a > 0; $a--) {
            $b = $this->angka(0, $a);
            [$daftar[$a], $daftar[$b]] = [$daftar[$b], $daftar[$a]];
        }

        return array_slice($daftar, 0, $n);
    }

    private function angka(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    private function tambah(string $tabel, array $baris): void
    {
        $this->buffer[$tabel][] = $baris;
        if (count($this->buffer[$tabel]) >= 500) {
            $this->kirim($tabel);
        }
    }

    private function kirim(string $tabel): void
    {
        if ($tabel !== 'spt' && str_starts_with($tabel, 'spt_')) {
            $this->kirim('spt'); // children reference SPT rows that may still be buffered
        }
        if (! empty($this->buffer[$tabel])) {
            DB::table($tabel)->insert($this->buffer[$tabel]);
        }
        $this->buffer[$tabel] = [];
    }
}
