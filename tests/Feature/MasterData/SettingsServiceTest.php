<?php

namespace Tests\Feature\MasterData;

use App\Models\Aktivitas;
use App\Services\Settings;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_returned_when_unset(): void
    {
        $s = app(Settings::class);
        $this->assertSame('{kode_arsip}/{nomor_urut}/{kode_irban}/SPT/{tahun}', $s->get('penomoran.format'));
        $this->assertSame('700', $s->get('penomoran.kode_arsip'));
        $this->assertSame(14, $s->get('penanda.batas_terlambat_hari'));
        $this->assertTrue($s->get('penomoran.reset_tahunan'));
        $this->assertSame('Lainnya', last($s->get('kendala.kategori')));
        $this->assertCount(19, Settings::DEFAULTS);
        $this->assertNotContains('penomoran.kode_sekretariat', Settings::GROUPS['penomoran']);
        $this->assertArrayNotHasKey('penomoran.kode_sekretariat', Settings::DEFAULTS);
        $this->assertSame('', $s->get('kontak.peta_url'));
    }

    public function test_set_get_round_trip_clears_cache(): void
    {
        $s = app(Settings::class);
        $this->assertSame(10, $s->get('upload.maks_mb')); // warms the cache
        $s->set('upload.maks_mb', 25);
        $this->assertSame(25, $s->get('upload.maks_mb'));
        $this->assertSame(25, app(Settings::class)->get('upload.maks_mb'));
        $this->assertDatabaseHas('settings', ['key' => 'upload.maks_mb', 'value' => '25']);
    }

    public function test_unknown_keys_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(Settings::class)->set('nope.nope', 1);
    }

    public function test_unknown_key_get_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(Settings::class)->get('nope.nope');
    }

    public function test_settings_is_a_singleton(): void
    {
        $this->assertSame(app(Settings::class), app(Settings::class));
    }

    public function test_nilai_dan_log_satu_transaksi(): void
    {
        $s = app(Settings::class);
        $s->set('upload.maks_mb', 11); // kontrol: nilai dan satu baris log
        $this->assertSame(1, Aktivitas::where('log_name', 'pengaturan')->count());

        Aktivitas::creating(fn () => throw new DomainException('log gagal'));
        try {
            $s->set('upload.maks_mb', 12);
            $this->fail('Seharusnya gagal.');
        } catch (DomainException $e) {
            $this->assertSame('log gagal', $e->getMessage());
        }
        $this->assertSame(11, app(Settings::class)->get('upload.maks_mb'));
        $this->assertDatabaseHas('settings', ['key' => 'upload.maks_mb', 'value' => '11']);

        Aktivitas::flushEventListeners();
        try {
            DB::transaction(function () use ($s) {
                $s->set('upload.maks_mb', 9);
                throw new DomainException('pemanggil gagal');
            });
        } catch (DomainException) {
        }
        $this->assertSame(11, app(Settings::class)->get('upload.maks_mb'));
        $this->assertSame(1, Aktivitas::where('log_name', 'pengaturan')->count());
    }
}
