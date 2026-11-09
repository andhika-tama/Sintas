<?php

namespace Tests\Feature\Rekap;

use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\PerubahanPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use Carbon\CarbonImmutable;

/**
 * Hand-computed fixture (setting `rekap.dasar_triwulan` = tanggal_selesai, today 15 Desember 2026):
 *
 * PKPT 2026 Disahkan, items (TW by effective end date):
 *   a1 Irban A, J1, 10 Jan–31 Mar, target 2        → TW I   (31 Maret edge)
 *   a2 Irban A, J2, 20 Mar–1 Apr, target null (=1)  → TW II  (1 April edge)
 *   a3 Irban A, J1, disahkan 1–31 Jul, moved by an approved Perubahan to 1–20 Okt, target 3 → TW IV
 *   b1 Irban B, J2, 1 Mei–30 Jun, target 2         → TW II
 *   b2 Irban B, J1, 1 Agu–30 Sep, target null (=1), pending Perubahan Penugasan to Des (ignored) → TW III
 * PKPT 2027 Draf with an item dated Februari 2026, target 5 → ignored in 2026 (other year) and 2027 (Draf).
 *
 * SPT (R = REGULER, K = KHUSUS):
 *   s1  A a1 J1 R  1–10 Feb   Selesai   → TW I   realisasi
 *   s2  A a1 J1 R 25–31 Mar   berjalan  → TW I
 *   s3  A a2 J2 R 28 Mar–1 Apr Selesai  → TW II  realisasi
 *   s4  A -  J1 K  5–6 Mei    Selesai   → TW II  (non-PKPT: jumlah only)
 *   s5  A a3 J1 R  5–15 Okt   Selesai   → TW IV  realisasi
 *   s6  B b1 J2 R  1–15 Jun   Selesai   → TW II  realisasi
 *   s7  B b1 J2 R 20–25 Jun   Dibatalkan (after Selesai) → excluded
 *   s8  B b2 J1 R  1–10 Sep   Ditolak   → excluded
 *   s9  B b2 J1 R 10–20 Agu   berjalan  → TW III
 *   s10 B -  J2 K  1–5 Nov    berjalan  → TW IV  (non-PKPT)
 *   s11 B b1 J2 R  1–10 Des 2025 Selesai → year 2025 by basis date (excluded from 2026)
 *   s12 A a1 J1 R  Feb, Draf → excluded
 */
trait RekapFixture
{
    protected Irban $a;

    protected Irban $b;

    protected JenisPenugasan $j1;

    protected JenisPenugasan $j2;

    protected int $r;

    protected int $k;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00', 'Asia/Jakarta'));

        $this->a = Irban::factory()->create(['nama' => 'Irban A']);
        $this->b = Irban::factory()->create(['nama' => 'Irban B']);
        $this->j1 = JenisPenugasan::factory()->create(['nama' => 'Jenis Satu']);
        $this->j2 = JenisPenugasan::factory()->create(['nama' => 'Jenis Dua']);
        $this->r = Klasifikasi::where('kode', 'REGULER')->value('id');
        $this->k = Klasifikasi::where('kode', 'KHUSUS')->value('id');

        $pkpt = Pkpt::factory()->disahkan()->create(['tahun' => 2026]);
        $item = fn (Irban $i, JenisPenugasan $j, string $m, string $s, ?int $t) => PkptItem::factory()->disahkanDari()->create(
            ['pkpt_id' => $pkpt->id, 'irban_id' => $i->id, 'jenis_penugasan_id' => $j->id, 'tanggal_mulai' => $m, 'tanggal_selesai' => $s, 'target_spt' => $t]);
        $a1 = $item($this->a, $this->j1, '2026-01-10', '2026-03-31', 2);
        $a2 = $item($this->a, $this->j2, '2026-03-20', '2026-04-01', null);
        $a3 = $item($this->a, $this->j1, '2026-07-01', '2026-07-31', 3);
        $a3->update(['tanggal_mulai' => '2026-10-01', 'tanggal_selesai' => '2026-10-20']);
        $b1 = $item($this->b, $this->j2, '2026-05-01', '2026-06-30', 2);
        $b2 = $item($this->b, $this->j1, '2026-08-01', '2026-09-30', null);
        PerubahanPenugasan::factory()->create(['pkpt_item_id' => $b2->id, 'tanggal_mulai' => '2026-12-01', 'tanggal_selesai' => '2026-12-20']);
        $draf = Pkpt::factory()->create(['tahun' => 2027]);
        PkptItem::factory()->create(['pkpt_id' => $draf->id, 'irban_id' => $this->a->id, 'jenis_penugasan_id' => $this->j1->id,
            'tanggal_mulai' => '2026-02-01', 'tanggal_selesai' => '2026-02-28', 'target_spt' => 5]);

        $spt = function (string $state, Irban $i, ?PkptItem $it, JenisPenugasan $j, int $kl, string $m, string $s) {
            $f = Spt::factory();
            $f = $state === 'draf' ? $f : $f->{$state}();

            return $f->create(['irban_id' => $i->id, 'pkpt_item_id' => $it?->id, 'jenis_penugasan_id' => $j->id, 'klasifikasi_id' => $kl,
                'tanggal_mulai' => $m, 'tanggal_selesai' => $s]);
        };
        $spt('selesai', $this->a, $a1, $this->j1, $this->r, '2026-02-01', '2026-02-10');
        $spt('diterima', $this->a, $a1, $this->j1, $this->r, '2026-03-25', '2026-03-31');
        $spt('selesai', $this->a, $a2, $this->j2, $this->r, '2026-03-28', '2026-04-01');
        $spt('selesai', $this->a, null, $this->j1, $this->k, '2026-05-05', '2026-05-06');
        $spt('selesai', $this->a, $a3, $this->j1, $this->r, '2026-10-05', '2026-10-15');
        $spt('selesai', $this->b, $b1, $this->j2, $this->r, '2026-06-01', '2026-06-15');
        $spt('selesai', $this->b, $b1, $this->j2, $this->r, '2026-06-20', '2026-06-25')->forceFill(['status_pengajuan' => 'dibatalkan'])->save();
        $spt('ditolak', $this->b, $b2, $this->j1, $this->r, '2026-09-01', '2026-09-10');
        $spt('diterima', $this->b, $b2, $this->j1, $this->r, '2026-08-10', '2026-08-20');
        $spt('diterima', $this->b, null, $this->j2, $this->k, '2026-11-01', '2026-11-05');
        $spt('selesai', $this->b, $b1, $this->j2, $this->r, '2025-12-01', '2025-12-10');
        $spt('draf', $this->a, $a1, $this->j1, $this->r, '2026-02-01', '2026-02-10');
    }
}
