<?php

namespace Tests\Feature\Spt;

use App\Actions\Spt\SimpanSpt;
use App\Enums\StatusPengajuan;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** 1.2 Task 4 (K3, R3): free-text "Lainnya" for jenis penugasan and objek on a non-PKPT SPT. */
class SptLainnyaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Pegawai $ppj;

    private Pegawai $anggota;

    private PkptItem $item;

    private JenisPenugasan $jenis;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Jakarta'));

        $irban = Irban::factory()->create();
        $this->user = User::factory()->irban()->create();
        $this->user->forceFill(['irban_id' => $irban->id])->save();
        $this->ppj = Pegawai::factory()->create();
        $this->anggota = Pegawai::factory()->create();
        IrbanTahunan::factory()->create(['irban_id' => $irban->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->ppj->id])
            ->anggota()->attach($this->anggota->id);
        $this->jenis = JenisPenugasan::factory()->create(['nama' => 'Reviu LKjIP']);
        $this->item = PkptItem::factory()->create([
            'pkpt_id' => Pkpt::factory()->disahkan()->create(['tahun' => 2026])->id, 'irban_id' => $irban->id,
            'jenis_penugasan_id' => $this->jenis->id, 'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20',
        ]);
    }

    private function klas(string $kode): int
    {
        return Klasifikasi::where('kode', $kode)->value('id');
    }

    /** A complete non-PKPT (KHUSUS, no item) submission. */
    private function data(array $over = []): array
    {
        return array_merge([
            'klasifikasi_id' => $this->klas('KHUSUS'), 'kode_arsip_id' => KodeArsip::where('kode', '700')->value('id'), 'pkpt_item_id' => null, 'non_pkpt' => true,
            'jenis_penugasan_id' => $this->jenis->id, 'jenis_penugasan_lainnya' => null, 'objek_ids' => [Objek::factory()->create(['nama' => 'Dinas PUPR'])->id],
            'objek_lainnya' => null, 'periode_diperiksa' => 'Tahun Anggaran 2025', 'uraian_untuk' => 'Melaksanakan tugas.',
            'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-10', 'ppj_pegawai_id' => $this->ppj->id, 'diinput_oleh' => $this->anggota->id,
            'personel' => [['pegawai_id' => $this->ppj->id, 'peran' => 'ketua', 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true]],
        ], $over);
    }

    private function simpan(array $over = [], string $mode = 'ajukan'): Spt
    {
        return app(SimpanSpt::class)->handle($this->user, null, $this->data($over), $mode);
    }

    private function assertRejected(array $over, string $key, string $message): void
    {
        try {
            $this->simpan($over);
            $this->fail("Expected a validation error on {$key}.");
        } catch (ValidationException $e) {
            $this->assertContains($message, $e->errors()[$key] ?? [], json_encode($e->errors()));
        }
    }

    public function test_non_pkpt_boleh_jenis_lainnya(): void
    {
        $spt = $this->simpan(['jenis_penugasan_id' => null, 'jenis_penugasan_lainnya' => '  Pendampingan BPK ']);

        $this->assertSame(StatusPengajuan::MenungguNomor, $spt->status_pengajuan);
        $this->assertNull($spt->jenis_penugasan_id);
        $this->assertSame('Pendampingan BPK', $spt->jenis_penugasan_lainnya);
        $this->assertSame('Pendampingan BPK', $spt->namaJenis());
    }

    public function test_non_pkpt_tanpa_jenis_dan_lainnya(): void
    {
        $this->assertRejected(['jenis_penugasan_id' => null, 'jenis_penugasan_lainnya' => '   '], 'jenis_penugasan_id', 'Pilih jenis penugasan atau isi Lainnya.');
    }

    public function test_master_menang_atas_lainnya(): void
    {
        $spt = $this->simpan(['jenis_penugasan_lainnya' => 'Pendampingan BPK']);

        $this->assertSame($this->jenis->id, $spt->jenis_penugasan_id);
        $this->assertNull($spt->jenis_penugasan_lainnya);
        $this->assertSame('Reviu LKjIP', $spt->namaJenis());
    }

    public function test_objek_lainnya_saja_sah(): void
    {
        $spt = $this->simpan(['objek_ids' => [], 'objek_lainnya' => ' Kantor BPK Perwakilan ']);

        $this->assertCount(0, $spt->objek);
        $this->assertSame(['Kantor BPK Perwakilan'], $spt->namaObjek());
    }

    public function test_objek_master_dan_lainnya_bersama(): void
    {
        $b = Objek::factory()->create(['nama' => 'Kecamatan Ujan Mas']);
        $a = Objek::factory()->create(['nama' => 'Dinas Kesehatan']);
        $spt = $this->simpan(['objek_ids' => [$b->id, $a->id], 'objek_lainnya' => 'Aula Kantor Bupati']);

        $this->assertSame(['Dinas Kesehatan', 'Kecamatan Ujan Mas', 'Aula Kantor Bupati'], $spt->namaObjek());
    }

    public function test_tanpa_objek_sama_sekali(): void
    {
        $this->assertRejected(['objek_ids' => [], 'objek_lainnya' => ' '], 'objek_ids', 'Pilih minimal satu objek atau isi objek lainnya.');
    }

    public function test_spt_dengan_item_mengabaikan_lainnya(): void
    {
        $spt = $this->simpan(['klasifikasi_id' => $this->klas('REGULER'), 'pkpt_item_id' => $this->item->id, 'non_pkpt' => false,
            'jenis_penugasan_id' => null, 'jenis_penugasan_lainnya' => 'Pendampingan BPK', 'objek_lainnya' => 'Aula Kantor Bupati']);

        $this->assertSame($this->jenis->id, $spt->jenis_penugasan_id);
        $this->assertNull($spt->jenis_penugasan_lainnya);
        $this->assertNull($spt->objek_lainnya);
        $this->assertSame(['Dinas PUPR'], $spt->namaObjek());
    }

    public function test_reguler_tidak_bisa_lainnya(): void
    {
        $this->assertRejected(['klasifikasi_id' => $this->klas('REGULER'), 'non_pkpt' => false, 'jenis_penugasan_id' => null,
            'jenis_penugasan_lainnya' => 'Pendampingan BPK'], 'pkpt_item_id', 'Pilih item PKPT untuk SPT Reguler.');
    }

    public function test_batas_panjang_teks_lainnya(): void
    {
        $this->assertRejected(['jenis_penugasan_id' => null, 'jenis_penugasan_lainnya' => str_repeat('a', 151)],
            'jenis_penugasan_lainnya', 'Jenis penugasan lainnya maksimal 150 karakter.');
        $this->assertRejected(['objek_lainnya' => str_repeat('a', 256)], 'objek_lainnya', 'Objek lainnya maksimal 255 karakter.');

        $spt = $this->simpan(['jenis_penugasan_id' => null, 'jenis_penugasan_lainnya' => str_repeat('a', 150), 'objek_lainnya' => str_repeat('b', 255)]);
        $this->assertSame(150, mb_strlen($spt->jenis_penugasan_lainnya));
    }

    public function test_perubahan_objek_lainnya_tercatat_di_log(): void
    {
        $spt = $this->simpan(['objek_lainnya' => 'Aula Kantor Bupati'], 'draf');
        app(SimpanSpt::class)->handle($this->user, $spt, $this->data(['objek_lainnya' => 'Gedung DPRD']), 'draf');

        $log = $spt->activities()->where('event', 'updated')->get()->first(fn ($a) => isset($a->properties['attributes']['objek_lainnya']));
        $this->assertSame('Aula Kantor Bupati', $log?->properties['old']['objek_lainnya']);
        $this->assertSame('Gedung DPRD', $log->properties['attributes']['objek_lainnya']);
    }
}
