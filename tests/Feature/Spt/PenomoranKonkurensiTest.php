<?php

namespace Tests\Feature\Spt;

use Illuminate\Support\Facades\DB;
use Tests\Support\KonkurensiProses;
use Tests\TestCase;

/** PRD Fase 4 acceptance: 20 processes taking a number at once get 20 unique, sequential numbers. */
class PenomoranKonkurensiTest extends TestCase
{
    private const PROSES = 20;

    use KonkurensiProses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanKonkurensi();
    }

    protected function tearDown(): void
    {
        $this->bersihkanKonkurensi();
        parent::tearDown();
    }

    /** Migrates the throwaway database and inserts the irban; returns its id. */
    private function siapkan(): int
    {
        $this->migrasiKonkurensi();

        return DB::connection('konkurensi')->table('irban')->insertGetId([
            'nama' => 'Irban II', 'kode_nomor' => 'INSPEKTORAT-II', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_twenty_concurrent_processes_get_twenty_sequential_numbers(): void
    {
        $irbanId = $this->siapkan();
        $hasil = $this->jalankan('tests/Support/ambil-nomor.php', array_fill(0, self::PROSES, [(string) $irbanId, '2026-05-04', $this->barrier]));

        $urut = array_column($hasil, 'nomor_urut');
        sort($urut);
        $this->assertSame(range(1, self::PROSES), $urut);
        $lengkap = array_column($hasil, 'nomor_lengkap');
        $this->assertCount(self::PROSES, array_unique($lengkap));
        $this->assertContains('700/20/INSPEKTORAT-II/SPT/2026', $lengkap);

        $this->assertSame(self::PROSES, (int) DB::connection('konkurensi')->table('nomor_counter')->value('nomor_terakhir'));
        $this->assertSame(1, DB::connection('konkurensi')->table('nomor_counter')->count());
    }

    /** PRD §14 #2 at decision level: Bagian Umum accepting different SPT from several processes at once gets distinct consecutive numbers. */
    public function test_concurrent_terima_pengajuan_on_different_spt(): void
    {
        $irbanId = $this->siapkan();
        $db = DB::connection('konkurensi');
        $userId = $db->table('users')->insertGetId(['name' => 'Bagian Umum', 'username' => 'bu', 'email' => 'bu@example.test', 'password' => 'x',
            'role' => 'bagian_umum', 'created_at' => now(), 'updated_at' => now()]);
        $klasifikasi = $db->table('klasifikasi')->where('kode', 'KHUSUS')->value('id');
        $kode = $db->table('kode_arsip')->where('kode', '700')->value('id');
        $sptIds = [];
        for ($i = 0; $i < 5; $i++) {
            $sptIds[] = $db->table('spt')->insertGetId(['irban_id' => $irbanId, 'klasifikasi_id' => $klasifikasi, 'kode_arsip_id' => $kode, 'status_pengajuan' => 'menunggu_nomor',
                'diajukan_pada' => now(), 'dibuat_oleh' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $hasil = $this->jalankan('tests/Support/ambil-nomor.php', array_map(fn ($id) => [(string) $irbanId, '', $this->barrier, (string) $id, (string) $userId], $sptIds));

        $urut = array_column($hasil, 'nomor_urut');
        sort($urut);
        $this->assertSame(range(1, 5), $urut);
        $db = DB::connection('konkurensi');
        $this->assertSame(5, $db->table('spt')->where('status_pengajuan', 'diterima')->distinct()->count('nomor_lengkap'));
        $this->assertSame(5, (int) $db->table('nomor_counter')->value('nomor_terakhir'));
        $this->assertSame(5, $db->table('spt_riwayat')->where('kejadian', 'diterima')->count());
    }
}
