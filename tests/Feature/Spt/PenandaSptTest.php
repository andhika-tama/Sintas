<?php

namespace Tests\Feature\Spt;

use App\Enums\KejadianSpt;
use App\Enums\SumberSpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\SptDokumen;
use App\Models\SptKendala;
use App\Models\User;
use App\Services\PenandaSpt;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\JadwalItem;
use Tests\TestCase;

class PenandaSptTest extends TestCase
{
    use RefreshDatabase;

    private function penanda(Spt $spt): array
    {
        return app(PenandaSpt::class)->untuk($spt->fresh());
    }

    private function hari(string $tanggal): void
    {
        $this->travelTo(CarbonImmutable::parse("$tanggal 12:00", 'Asia/Jakarta'));
    }

    /** Diterima, Non-PKPT, 10..20 Maret 2026 with a final SPT so only the flag under test shows. */
    private function spt(array $attrs = [], bool $final = true): Spt
    {
        $spt = Spt::factory()->diterima()->create(['tanggal_mulai' => '2026-03-10', 'tanggal_selesai' => '2026-03-20', ...$attrs]);
        if ($final) {
            $this->dokumen($spt, 'final');
        }

        return $spt;
    }

    private function dokumen(Spt $spt, string $jenis): void
    {
        SptDokumen::create(['spt_id' => $spt->id, 'jenis' => $jenis, 'path' => "spt/{$spt->id}/x.pdf", 'nama_asli' => 'x.pdf',
            'ukuran' => 10, 'sha256' => str_repeat('a', 64), 'diunggah_oleh' => User::factory()->create()->id]);
    }

    public function test_non_pkpt_except_draf(): void
    {
        $this->hari('2026-03-05');
        $this->assertSame([], $this->penanda(Spt::factory()->create()));
        $this->assertSame(['non_pkpt'], $this->penanda(Spt::factory()->menungguNomor()->create()));
        $this->assertSame(['non_pkpt'], $this->penanda($this->spt()));
        $this->assertSame([], $this->penanda($this->spt(['pkpt_item_id' => PkptItem::factory()->create(['tanggal_mulai' => '2026-03-01', 'tanggal_selesai' => '2026-03-31'])->id])));
    }

    public function test_terlambat_after_batas_hari(): void
    {
        $spt = $this->spt();
        $this->hari('2026-03-20');
        $this->assertNotContains('terlambat', $this->penanda($spt));
        $this->hari('2026-04-03'); // selesai + 14
        $this->assertNotContains('terlambat', $this->penanda($spt));
        $this->hari('2026-04-04'); // selesai + 15 (PRD §14 #4)
        $this->assertSame(['non_pkpt', 'terlambat'], $this->penanda($spt));

        app(Settings::class)->set('penanda.batas_terlambat_hari', 3);
        $this->hari('2026-03-23');
        $this->assertNotContains('terlambat', $this->penanda($spt));
        $this->hari('2026-03-24');
        $this->assertContains('terlambat', $this->penanda($spt));

        $spt->forceFill(['ditandai_selesai_pada' => now()])->save();
        $this->assertNotContains('terlambat', $this->penanda($spt));
    }

    public function test_spt_final_belum_diupload(): void
    {
        $spt = $this->spt(final: false);
        $this->hari('2026-03-10');
        $this->assertNotContains('spt_final_belum_diupload', $this->penanda($spt));
        $this->hari('2026-03-11');
        $this->assertContains('spt_final_belum_diupload', $this->penanda($spt));
        $this->dokumen($spt, 'draf');
        $this->assertContains('spt_final_belum_diupload', $this->penanda($spt));
        $this->dokumen($spt, 'final');
        $this->assertNotContains('spt_final_belum_diupload', $this->penanda($spt));

        $this->assertNotContains('spt_final_belum_diupload', $this->penanda(Spt::factory()->menungguNomor()->create(['tanggal_mulai' => '2026-03-01'])));
    }

    public function test_spt_impor_tanpa_final_tidak_ditandai(): void
    {
        $impor = $this->spt(final: false);
        $impor->forceFill(['sumber' => SumberSpt::Impor])->save();
        $sistem = $this->spt(final: false);
        $this->hari('2026-03-11');
        $this->assertNotContains('spt_final_belum_diupload', $this->penanda($impor));
        $this->assertNotContains('spt_final_belum_diupload', app(PenandaSpt::class)->untukBanyak(collect([$impor->fresh()]))[$impor->id]);
        $this->assertContains('spt_final_belum_diupload', $this->penanda($sistem));
    }

    public function test_untuk_banyak_leaves_the_callers_models_untouched(): void
    {
        $this->hari('2026-03-11');
        $tanpa = $this->spt(final: false);
        $dengan = $this->spt();
        $spts = Spt::whereKey([$tanpa->id, $dengan->id])->get();

        $penanda = app(PenandaSpt::class)->untukBanyak($spts);

        // Positive control: the batch still tells the two apart.
        $this->assertContains('spt_final_belum_diupload', $penanda[$tanpa->id]);
        $this->assertNotContains('spt_final_belum_diupload', $penanda[$dengan->id]);
        foreach ($spts as $spt) {
            $this->assertArrayNotHasKey('final_ada', $spt->getAttributes());
        }
    }

    public function test_tidak_sesuai_pkpt_kendala_and_moved_item(): void
    {
        $this->hari('2026-03-15');
        $item = PkptItem::factory()->create(['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20']);
        $spt = $this->spt(['pkpt_item_id' => $item->id, 'tanggal_selesai' => '2026-03-25']);
        $penanda = app(PenandaSpt::class);

        $this->assertSame(['tidak_sesuai_pkpt'], $this->penanda($spt));
        $this->assertTrue($penanda->perluKendala($spt->fresh()));

        SptKendala::factory()->create(['spt_id' => $spt->id]);
        $this->assertFalse($penanda->perluKendala($spt->fresh()));
        $this->assertSame(['tidak_sesuai_pkpt'], $this->penanda($spt));

        // PRD §14 #3: an approved Perubahan PKPT that moves the item over the dates removes the flag.
        JadwalItem::geser($item, '2026-03-02', '2026-03-31');
        $this->assertSame([], $this->penanda($spt));
        $this->assertFalse($penanda->perluKendala($spt->fresh()));
    }

    public function test_tidak_sesuai_pkpt_only_when_diterima_with_both_dates(): void
    {
        $this->hari('2026-03-15');
        $item = PkptItem::factory()->create(['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20']);
        $this->assertSame([], $this->penanda(Spt::factory()->menungguNomor()->create(['pkpt_item_id' => $item->id, 'tanggal_selesai' => '2026-03-25'])));
        $this->assertSame([], $this->penanda($this->spt(['pkpt_item_id' => $item->id, 'tanggal_selesai' => null])));
        $this->assertFalse(app(PenandaSpt::class)->perluKendala($this->spt(['pkpt_item_id' => $item->id])->fresh()));
    }

    public function test_flag_order(): void
    {
        $item = PkptItem::factory()->create(['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-05']);
        $this->hari('2026-04-30');
        $this->assertSame(['tidak_sesuai_pkpt', 'terlambat', 'spt_final_belum_diupload'], $this->penanda($this->spt(['pkpt_item_id' => $item->id], final: false)));
        $this->assertSame(['non_pkpt', 'terlambat', 'spt_final_belum_diupload'], $this->penanda($this->spt(final: false)));
    }

    public function test_untuk_banyak_runs_a_constant_number_of_queries(): void
    {
        $this->hari('2026-04-30');
        $buat = function (int $n): void {
            $item = PkptItem::factory()->create(['tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-05']);
            JadwalItem::geser($item, '2026-03-01', '2026-03-31');
            $user = User::factory()->create();
            for ($i = 0; $i < $n; $i++) {
                $this->spt(['pkpt_item_id' => $i % 2 ? $item->id : null, 'dibuat_oleh' => $user->id], final: $i % 3 === 0);
            }
        };
        $hitung = function (): int {
            $spts = Spt::all();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(PenandaSpt::class)->untukBanyak($spts);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $buat(5);
        $hitung(); // settings cache warm-up
        $lima = $hitung();
        $buat(45);
        $this->assertSame(50, Spt::count());
        $this->assertSame($lima, $hitung());
        $this->assertLessThanOrEqual(4, $lima);
    }

    public function test_kendala_relation(): void
    {
        $kendala = SptKendala::factory()->create();
        $this->assertTrue($kendala->spt->kendala->is($kendala));
        $this->assertNotNull($kendala->dibuatOleh);
    }

    public function test_kejadian_labels(): void
    {
        $expected = [
            KejadianSpt::DIBUAT => 'Dibuat', KejadianSpt::DIUBAH => 'Diubah', KejadianSpt::DIAJUKAN => 'Diajukan',
            KejadianSpt::PERLU_REVISI => 'Perlu Revisi', KejadianSpt::DITOLAK => 'Ditolak', KejadianSpt::DITERIMA => 'Nomor diterbitkan',
            KejadianSpt::DICATAT_MANUAL => 'Nomor dicatat manual', KejadianSpt::DIBATALKAN => 'Nomor dibatalkan',
            KejadianSpt::NOMOR_AWAL => 'Nomor awal diatur', KejadianSpt::TANGGAL_DIUBAH => 'Tanggal realisasi diubah',
            KejadianSpt::FINAL_DIUNGGAH => 'SPT final diunggah', KejadianSpt::DITANDAI_SELESAI => 'Ditandai Selesai',
            KejadianSpt::KENDALA_DIISI => 'Kendala diisi', KejadianSpt::DIIMPOR => 'Diimpor dari data lama',
            KejadianSpt::SPPD_DIUBAH => 'Nomor SPPD diubah', KejadianSpt::DANA_DIUBAH => 'Realisasi dana diubah',
        ];
        $this->assertCount(16, (new \ReflectionClass(KejadianSpt::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC));
        foreach ($expected as $kejadian => $label) {
            $this->assertSame($label, KejadianSpt::label($kejadian));
        }
        $this->assertSame('tidak_dikenal', KejadianSpt::label('tidak_dikenal'));
    }
}
