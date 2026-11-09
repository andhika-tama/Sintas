<?php

namespace Tests\Feature\Rekap;

use App\Models\Irban;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Services\RekapTriwulan;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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
class RekapTriwulanTest extends TestCase
{
    use RefreshDatabase;
    use RekapFixture;

    /** @param list<array{0:int,1:int,2:?int}> $tw rencana, realisasi, persen per TW I–IV */
    private function rr(array $tw, array $total): array
    {
        $sel = fn (array $v) => ['rencana' => $v[0], 'realisasi' => $v[1], 'persen' => $v[2]];

        return ['tw' => [1 => $sel($tw[0]), 2 => $sel($tw[1]), 3 => $sel($tw[2]), 4 => $sel($tw[3])], 'total' => $sel($total)];
    }

    private function kl(array $tw, array $total): array
    {
        $sel = fn (array $v) => ['jumlah' => $v[0], 'berjalan' => $v[1], 'selesai' => $v[2]];

        return ['tw' => [1 => $sel($tw[0]), 2 => $sel($tw[1]), 3 => $sel($tw[2]), 4 => $sel($tw[3])], 'total' => $sel($total)];
    }

    private function jn(array $tw, array $total): array
    {
        $sel = fn (array $v) => ['rencana' => $v[0], 'realisasi' => $v[1], 'persen' => $v[2], 'jumlah' => $v[3]];

        return ['tw' => [1 => $sel($tw[0]), 2 => $sel($tw[1]), 3 => $sel($tw[2]), 4 => $sel($tw[3])], 'total' => $sel($total)];
    }

    /** Strip id/nama so the cells can be compared; returns rows keyed by id. */
    private function sel(array $baris): array
    {
        return collect($baris)->keyBy('id')->map(fn ($b) => ['tw' => $b['tw'], 'total' => $b['total']])->all();
    }

    public function test_rencana_vs_realisasi_per_irban_and_gabungan(): void
    {
        $rekap = app(RekapTriwulan::class)->hitung(2026);

        $this->assertSame(2026, $rekap['tahun']);
        $this->assertTrue($rekap['pkpt_disahkan']);
        $this->assertSame([$this->a->id, $this->b->id], array_column($rekap['irban']['baris'], 'id'));
        $this->assertSame(['Irban A', 'Irban B'], array_column($rekap['irban']['baris'], 'nama'));
        $irban = $this->sel($rekap['irban']['baris']);
        // A: rencana 2,1,0,3 = 6; realisasi 1,1,0,1 = 3; persen 50, 100, –, round(33.3)=33; total 3/6 = 50.
        $this->assertSame($this->rr([[2, 1, 50], [1, 1, 100], [0, 0, null], [3, 1, 33]], [6, 3, 50]), $irban[$this->a->id]);
        // B: rencana 0,2,1,0 = 3; realisasi 0,1,0,0 = 1; persen –, 50, 0, –; total round(33.3)=33.
        $this->assertSame($this->rr([[0, 0, null], [2, 1, 50], [1, 0, 0], [0, 0, null]], [3, 1, 33]), $irban[$this->b->id]);
        // Gabungan: rencana 2,3,1,3 = 9; realisasi 1,2,0,1 = 4; persen 50, round(66.7)=67, 0, 33; total round(44.4)=44 (not the 41.5 average).
        $this->assertSame($this->rr([[2, 1, 50], [3, 2, 67], [1, 0, 0], [3, 1, 33]], [9, 4, 44]), $rekap['irban']['total']);
    }

    public function test_per_klasifikasi(): void
    {
        $rekap = app(RekapTriwulan::class)->hitung(2026);
        $kl = $this->sel($rekap['klasifikasi']['baris']);

        $this->assertSame(Klasifikasi::orderBy('id')->pluck('id')->all(), array_column($rekap['klasifikasi']['baris'], 'id'));
        $this->assertSame('REGULER', collect($rekap['klasifikasi']['baris'])->firstWhere('id', $this->r)['nama']);
        // REGULER: TW I s1(S)+s2(B); TW II s3(S)+s6(S); TW III s9(B); TW IV s5(S) → 6 = 2 berjalan + 4 selesai.
        $this->assertSame($this->kl([[2, 1, 1], [2, 0, 2], [1, 1, 0], [1, 0, 1]], [6, 2, 4]), $kl[$this->r]);
        // KHUSUS: TW II s4(S); TW IV s10(B).
        $this->assertSame($this->kl([[0, 0, 0], [1, 0, 1], [0, 0, 0], [1, 1, 0]], [2, 1, 1]), $kl[$this->k]);
        foreach (array_diff(array_keys($kl), [$this->r, $this->k]) as $id) {
            $this->assertSame($this->kl([[0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0]], [0, 0, 0]), $kl[$id]);
        }
        // Total: 2+0, 2+1, 1+0, 1+1 → 2,3,1,2 = 8 (3 berjalan, 5 selesai).
        $this->assertSame($this->kl([[2, 1, 1], [3, 0, 3], [1, 1, 0], [2, 1, 1]], [8, 3, 5]), $rekap['klasifikasi']['total']);
    }

    public function test_per_jenis_penugasan(): void
    {
        $rekap = app(RekapTriwulan::class)->hitung(2026);
        $jn = $this->sel($rekap['jenis']['baris']);

        $this->assertSame(['Jenis Dua', 'Jenis Satu'], array_column($rekap['jenis']['baris'], 'nama')); // by nama
        // J1: rencana a1 TW I 2, b2 TW III 1, a3 TW IV 3 = 6; realisasi s1 TW I, s5 TW IV = 2;
        //     jumlah s1+s2 TW I, s4 TW II (non-PKPT), s9 TW III, s5 TW IV = 5; persen 50, –, 0, 33; total round(33.3)=33.
        $this->assertSame($this->jn([[2, 1, 50, 2], [0, 0, null, 1], [1, 0, 0, 1], [3, 1, 33, 1]], [6, 2, 33, 5]), $jn[$this->j1->id]);
        // J2: rencana a2 1 + b1 2 = 3 in TW II; realisasi s3+s6 TW II = 2; jumlah s3+s6 TW II, s10 TW IV = 3; persen 67.
        $this->assertSame($this->jn([[0, 0, null, 0], [3, 2, 67, 2], [0, 0, null, 0], [0, 0, null, 1]], [3, 2, 67, 3]), $jn[$this->j2->id]);
        $this->assertSame($this->jn([[2, 1, 50, 2], [3, 2, 67, 3], [1, 0, 0, 1], [3, 1, 33, 2]], [9, 4, 44, 8]), $rekap['jenis']['total']);
    }

    public function test_irban_scope(): void
    {
        $rekap = app(RekapTriwulan::class)->hitung(2026, $this->b->id);

        $this->assertSame([$this->b->id], array_column($rekap['irban']['baris'], 'id'));
        $b = $this->rr([[0, 0, null], [2, 1, 50], [1, 0, 0], [0, 0, null]], [3, 1, 33]);
        $this->assertSame($b, $this->sel($rekap['irban']['baris'])[$this->b->id]);
        $this->assertSame($b, $rekap['irban']['total']);
        // B only: REGULER s6 TW II (S), s9 TW III (B); KHUSUS s10 TW IV (B).
        $kl = $this->sel($rekap['klasifikasi']['baris']);
        $this->assertSame($this->kl([[0, 0, 0], [1, 0, 1], [1, 1, 0], [0, 0, 0]], [2, 1, 1]), $kl[$this->r]);
        $this->assertSame($this->kl([[0, 0, 0], [0, 0, 0], [0, 0, 0], [1, 1, 0]], [1, 1, 0]), $kl[$this->k]);
        $this->assertSame($this->kl([[0, 0, 0], [1, 0, 1], [1, 1, 0], [1, 1, 0]], [3, 2, 1]), $rekap['klasifikasi']['total']);
        // J1: rencana b2 TW III 1, jumlah s9; J2: rencana b1 TW II 2, realisasi s6, jumlah s6 TW II + s10 TW IV.
        $jn = $this->sel($rekap['jenis']['baris']);
        $this->assertSame($this->jn([[0, 0, null, 0], [0, 0, null, 0], [1, 0, 0, 1], [0, 0, null, 0]], [1, 0, 0, 1]), $jn[$this->j1->id]);
        $this->assertSame($this->jn([[0, 0, null, 0], [2, 1, 50, 1], [0, 0, null, 0], [0, 0, null, 1]], [2, 1, 50, 2]), $jn[$this->j2->id]);
        $this->assertSame($this->jn([[0, 0, null, 0], [2, 1, 50, 1], [1, 0, 0, 1], [0, 0, null, 1]], [3, 1, 33, 3]), $rekap['jenis']['total']);
    }

    public function test_no_disahkan_pkpt_gives_zero_rencana(): void
    {
        // 2025: no PKPT at all; s11 (B, b1 of 2026, Selesai, 10 Des 2025) is realisasi in TW IV but rencana is 0 → persen null.
        $rekap = app(RekapTriwulan::class)->hitung(2025);
        $this->assertFalse($rekap['pkpt_disahkan']);
        $this->assertSame($this->rr([[0, 0, null], [0, 0, null], [0, 0, null], [0, 1, null]], [0, 1, null]), $this->sel($rekap['irban']['baris'])[$this->b->id]);
        $this->assertSame($this->rr([[0, 0, null], [0, 0, null], [0, 0, null], [0, 1, null]], [0, 1, null]), $rekap['irban']['total']);

        // 2027: only a Draf PKPT → not disahkan, rencana 0 everywhere.
        $rekap = app(RekapTriwulan::class)->hitung(2027);
        $this->assertFalse($rekap['pkpt_disahkan']);
        $nol = $this->rr([[0, 0, null], [0, 0, null], [0, 0, null], [0, 0, null]], [0, 0, null]);
        $this->assertSame($nol, $rekap['irban']['total']);
    }

    public function test_tanggal_lhp_basis_moves_spt_between_quarters(): void
    {
        app(Settings::class)->set('rekap.dasar_triwulan', 'tanggal_lhp');
        // s1 (selesai 10 Feb) gets an LHP dated 2 April → realisasi moves from TW I to TW II; the others fall back to tanggal_selesai.
        Spt::where('irban_id', $this->a->id)->where('tanggal_selesai', 'like', '2026-02-10%')->whereNotNull('ditandai_selesai_pada')
            ->update(['tanggal_lhp' => '2026-04-02']);
        $rekap = app(RekapTriwulan::class)->hitung(2026, $this->a->id);
        // A realisasi 0,2,0,1; rencana unchanged (items use their end date) 2,1,0,3 → persen 0, 200, –, 33; total 50.
        $this->assertSame($this->rr([[2, 0, 0], [1, 2, 200], [0, 0, null], [3, 1, 33]], [6, 3, 50]), $rekap['irban']['total']);
    }

    public function test_tanggal_mulai_basis_moves_item_rencana_and_spt_realisasi_to_the_start_quarter(): void
    {
        app(Settings::class)->set('rekap.dasar_triwulan', 'tanggal_mulai');
        $rekap = app(RekapTriwulan::class)->hitung(2026, $this->a->id);
        // Items by start: a1 10 Jan (2) and a2 20 Mar (1) in TW I, a3 1 Okt (3) in TW IV. Realisasi by SPT start: s1 1 Feb, s3 28 Mar (was TW II by end), s5 5 Okt.
        $this->assertSame($this->rr([[3, 2, 67], [0, 0, null], [0, 0, null], [3, 1, 33]], [6, 3, 50]), $rekap['irban']['total']);
    }

    public function test_item_revised_into_another_year_leaves_this_year_s_rencana(): void
    {
        $sebelum = app(RekapTriwulan::class)->hitung(2026, $this->a->id)['irban']['total'];
        $pkpt = Pkpt::where('tahun', 2026)->firstOrFail();
        $baru = PkptItem::factory()->disahkanDari()->create(['pkpt_id' => $pkpt->id, 'irban_id' => $this->a->id, 'jenis_penugasan_id' => $this->j1->id,
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2026-07-31', 'target_spt' => 4]);
        // Control: while it sits in July it adds 4 to TW III.
        $this->assertSame(4, app(RekapTriwulan::class)->hitung(2026, $this->a->id)['irban']['total']['tw'][3]['rencana']);

        $baru->update(['tanggal_mulai' => '2027-01-05', 'tanggal_selesai' => '2027-01-20']);

        $this->assertSame($sebelum, app(RekapTriwulan::class)->hitung(2026, $this->a->id)['irban']['total']);
        $this->assertSame(0, app(RekapTriwulan::class)->hitung(2027, $this->a->id)['irban']['total']['total']['rencana']); // its PKPT is the 2026 one
    }

    public function test_spt_without_jenis_gets_its_own_row_after_the_named_jenis(): void
    {
        $this->assertNotContains(null, array_column(app(RekapTriwulan::class)->hitung(2026)['jenis']['baris'], 'id'));

        Spt::factory()->selesai()->create(['irban_id' => $this->a->id, 'pkpt_item_id' => null, 'jenis_penugasan_id' => null, 'klasifikasi_id' => $this->r,
            'tanggal_mulai' => '2026-02-01', 'tanggal_selesai' => '2026-02-10']);

        $jenis = app(RekapTriwulan::class)->hitung(2026)['jenis'];
        $baris = collect($jenis['baris']);
        $this->assertNull($baris->last()['id']);
        $this->assertSame('Tanpa jenis penugasan', $baris->last()['nama']);
        $this->assertSame(['rencana' => 0, 'realisasi' => 0, 'persen' => null, 'jumlah' => 1], $baris->last()['tw'][1]);
        $this->assertSame(9, $jenis['total']['total']['jumlah']); // 8 named + the new one
    }

    /** A jenis (and its item and SPT) created between the list read and the item/SPT reads must not crash the recap with an undefined key. */
    public function test_ids_created_while_calculating_count_under_tanpa_jenis_instead_of_crashing(): void
    {
        $sekali = false;
        DB::listen(function ($q) use (&$sekali) {
            if ($sekali || ! preg_match('/from ["`]jenis_penugasan["`]/', $q->sql)) {
                return;
            }
            $sekali = true;
            $baru = JenisPenugasan::factory()->create(['nama' => 'Jenis Baru']);
            $item = PkptItem::factory()->disahkanDari()->create(['pkpt_id' => Pkpt::where('tahun', 2026)->value('id'), 'irban_id' => $this->a->id,
                'jenis_penugasan_id' => $baru->id, 'tanggal_mulai' => '2026-05-01', 'tanggal_selesai' => '2026-05-20', 'target_spt' => 2]);
            Spt::factory()->selesai()->create(['irban_id' => $this->a->id, 'pkpt_item_id' => $item->id, 'jenis_penugasan_id' => $baru->id,
                'klasifikasi_id' => $this->r, 'tanggal_mulai' => '2026-05-02', 'tanggal_selesai' => '2026-05-10']);
        });

        $jenis = app(RekapTriwulan::class)->hitung(2026)['jenis'];

        $this->assertTrue($sekali);
        $this->assertNotContains('Jenis Baru', array_column($jenis['baris'], 'nama'));
        $tanpa = collect($jenis['baris'])->last();
        $this->assertSame('Tanpa jenis penugasan', $tanpa['nama']);
        $this->assertSame(['rencana' => 2, 'realisasi' => 1, 'persen' => 50, 'jumlah' => 1], $tanpa['tw'][2]);
    }
}
