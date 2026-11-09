<?php

namespace Tests\Feature\Impor;

use App\Actions\MasterData\ImportPegawai;
use App\Actions\Pkpt\ImportPkptItems;
use App\Actions\Spt\ImporSpt;
use App\Enums\KejadianSpt;
use App\Enums\PeranTim;
use App\Enums\StatusItemPkpt;
use App\Enums\StatusPengajuan;
use App\Enums\StatusSpt;
use App\Enums\SumberSpt;
use App\Models\Aktivitas;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\NomorCounter;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\Pkpt;
use App\Models\Spt;
use App\Models\SptKendala;
use App\Models\SptPersonel;
use App\Models\User;
use App\Services\Import\PegawaiImportParser;
use App\Services\Import\PkptItemImportParser;
use App\Services\Import\SptImportParser;
use App\Services\PenandaSpt;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImporSptTest extends TestCase
{
    use RefreshDatabase, SptImportTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedImpor();
    }

    /** Row 2: Reguler finished with LHP, personel, two objek, kendala; row 3: Khusus unfinished without personel; row 4: error. */
    private function berkasContoh(): array
    {
        return $this->analisis([
            $this->baris(['objek' => 'Kecamatan Lawang Kidul;Kecamatan Tanjung Agung', 'kendala_kategori' => 'Lainnya', 'kendala_uraian' => 'Hujan deras']),
            $this->baris(['nomor_spt' => '700/120/I/SPT/2025', 'tanggal_spt' => '01/06/2025', 'klasifikasi' => 'KHUSUS', 'jenis_penugasan' => '',
                'objek' => '', 'tanggal_mulai' => '02/06/2025', 'tanggal_selesai' => '10/06/2025', 'nip_supervisor' => '', 'nip_ketua' => '',
                'nip_anggota' => '', 'nomor_lhp' => '', 'tanggal_lhp' => '', 'selesai' => 'tidak', 'periode_diperiksa' => '']),
            $this->baris(['nomor_spt' => '700/121/I/SPT/2025', 'irban' => 'Irban IX']),
        ]);
    }

    public function test_impor_berkas_contoh(): void
    {
        Notification::fake();
        $ppj = Pegawai::factory()->create();
        IrbanTahunan::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2025, 'ppj_pegawai_id' => $ppj->id]);
        $rows = $this->berkasContoh();
        $this->assertSame(['valid', 'valid', 'error'], array_column($rows, 'status'), json_encode(array_column($rows, 'errors')));
        $counter = NomorCounter::count();

        $this->assertSame(2, app(ImporSpt::class)->handle($this->admin, $rows, 'spt-lama.xlsx'));

        $this->assertSame(2, Spt::count());
        $a = Spt::where('nomor_lengkap', '700/751/I/SPT/2025')->firstOrFail();
        $this->assertSame(StatusPengajuan::Diterima, $a->status_pengajuan);
        $this->assertSame(SumberSpt::Impor, $a->sumber);
        $this->assertSame($this->admin->id, $a->dibuat_oleh);
        $this->assertNull($a->diinput_oleh);
        $this->assertSame('2025-02-28 00:00:00', $a->diajukan_pada->format('Y-m-d H:i:s'));
        $this->assertSame('2025-02-28', $a->tanggal_spt->toDateString());
        $this->assertSame(2025, $a->tahun);
        $this->assertSame(751, $a->nomor_urut);
        $this->assertNull($a->nomor_counter_id);
        $this->assertSame($ppj->id, $a->ppj_pegawai_id);
        $this->assertSame($this->item->id, $a->pkpt_item_id);
        $this->assertSame([$this->irban->id, $this->klasifikasiId('REGULER'), $this->jenis->id], [$a->irban_id, $a->klasifikasi_id, $a->jenis_penugasan_id]);
        $this->assertSame(['Reviu LKjIP Tahun 2024', 'Tahun 2024', '2025-03-03', '2025-03-14'],
            [$a->uraian_untuk, $a->periode_diperiksa, $a->tanggal_mulai->toDateString(), $a->tanggal_selesai->toDateString()]);
        $this->assertSame(['700/LHP/1/2025', '2025-03-20'], [$a->nomor_lhp, $a->tanggal_lhp->toDateString()]);
        $this->assertSame('2025-03-20 00:00:00', $a->ditandai_selesai_pada->format('Y-m-d H:i:s'));
        $this->assertSame($this->admin->id, $a->ditandai_selesai_oleh);
        $this->assertSame(StatusSpt::Selesai, $a->status());
        $this->assertNotNull($a->terlambat_diberitahu_pada);
        $this->assertNotNull($a->pengingat_final_pada);
        $this->assertEqualsCanonicalizing(Objek::whereIn('nama', ['Kecamatan Lawang Kidul', 'Kecamatan Tanjung Agung'])->pluck('id')->all(), $a->objek->pluck('id')->all());
        $this->assertSame([
            [$this->pegawai[self::S]->id, PeranTim::SupervisorDalnis, 1, 'tambahan', null],
            [$this->pegawai[self::K]->id, PeranTim::Ketua, 2, 'tambahan', null],
            [$this->pegawai[self::A1]->id, PeranTim::Anggota, 3, 'tambahan', null],
            [$this->pegawai[self::A2]->id, PeranTim::Anggota, 4, 'tambahan', null],
        ], $a->personel->map(fn ($p) => [$p->pegawai_id, $p->peran, $p->urutan, $p->sumber, $p->tim_id])->all());
        $this->assertSame(['Lainnya', 'Hujan deras', null, $this->admin->id],
            [$a->kendala->kategori, $a->kendala->uraian, $a->kendala->diinput_oleh, $a->kendala->dibuat_oleh]);
        $riwayat = $a->riwayat()->sole();
        $this->assertSame([KejadianSpt::DIIMPOR, ['nomor_lengkap' => '700/751/I/SPT/2025', 'berkas' => 'spt-lama.xlsx'], $this->admin->id],
            [$riwayat->kejadian, $riwayat->nilai_baru, $riwayat->oleh]);
        $this->assertSame('Diimpor dari data lama', KejadianSpt::label(KejadianSpt::DIIMPOR));

        $b = Spt::where('nomor_lengkap', '700/120/I/SPT/2025')->firstOrFail();
        $this->assertSame([120, 2025, null, null, null, null], [$b->nomor_urut, $b->tahun, $b->pkpt_item_id, $b->jenis_penugasan_id, $b->ditandai_selesai_pada, $b->nomor_lhp]);
        $this->assertSame(StatusSpt::PenyusunanLaporan, $b->status());
        $this->assertSame([0, 0, null], [$b->personel()->count(), $b->objek()->count(), $b->kendala]);
        $this->assertSame(1, $b->riwayat()->where('kejadian', KejadianSpt::DIIMPOR)->count());

        $this->assertSame($counter, NomorCounter::count());
        Notification::assertNothingSent();
        $log = Aktivitas::where('log_name', 'impor')->sole();
        $this->assertSame(['impor', $this->admin->id], [$log->event, $log->causer_id]);
        $this->assertSame(['jenis' => 'spt', 'berkas' => 'spt-lama.xlsx', 'jumlah' => 2], $log->properties->all());
    }

    public function test_impor_hanya_mencatat_spt_dan_ringkasan(): void
    {
        $rows = $this->analisis([
            $this->baris(['nomor_spt' => '700/1/I/SPT/2025', 'kendala_kategori' => 'Lainnya', 'kendala_uraian' => 'Hujan']),
            $this->baris(['nomor_spt' => '700/2/I/SPT/2025']),
            $this->baris(['nomor_spt' => '700/3/I/SPT/2025']),
        ]);
        $this->assertSame(['valid', 'valid', 'valid'], array_column($rows, 'status'), json_encode(array_column($rows, 'errors')));
        $sebelum = Aktivitas::max('id') ?? 0;

        $this->assertSame(3, app(ImporSpt::class)->handle($this->admin, $rows, 'a.xlsx'));

        $this->assertSame(12, SptPersonel::count());
        $this->assertSame(1, SptKendala::count());
        $baru = Aktivitas::where('id', '>', $sebelum)->get();
        $this->assertSame(3, $baru->where('subject_type', (new Spt)->getMorphClass())->where('event', 'created')->count());
        $this->assertSame(1, $baru->where('log_name', 'impor')->count());
        $this->assertCount(4, $baru, 'no SptPersonel/SptKendala/objek rows');

        $pegawai = Pegawai::factory()->create(); // logging is enabled again after the import
        $pegawai->update(['jabatan' => 'Auditor Madya Baru']);
        $this->assertSame(1, Aktivitas::where('subject_type', $pegawai->getMorphClass())->where('subject_id', $pegawai->id)->where('event', 'updated')->count());
    }

    /** 1.2 Task 2 (R4): an imported SPT takes the kode arsip of its number's first segment when it is in the master, else 700. */
    public function test_kode_arsip_dari_segmen_pertama_nomor(): void
    {
        $rows = $this->analisis([
            $this->baris(['nomor_spt' => '800/1/I/SPT/2025']),
            $this->baris(['nomor_spt' => 'X9/2/I/SPT/2025']),
        ]);
        $this->assertSame(['valid', 'valid'], array_column($rows, 'status'), json_encode(array_column($rows, 'errors')));
        app(ImporSpt::class)->handle($this->admin, $rows, 'a.xlsx');

        $this->assertSame(['800/1/I/SPT/2025' => '800', 'X9/2/I/SPT/2025' => '700'],
            Spt::with('kodeArsip')->orderBy('id')->get()->mapWithKeys(fn ($s) => [$s->nomor_lengkap => $s->kodeArsip->kode])->all());
    }

    public function test_tidak_selesai_dengan_lhp_tetap_menyimpan_lhp(): void
    {
        app(ImporSpt::class)->handle($this->admin, $this->analisis([$this->baris(['selesai' => 'tidak'])]), 'a.xlsx');
        $spt = Spt::sole();
        $this->assertNull($spt->ditandai_selesai_pada);
        $this->assertSame('700/LHP/1/2025', $spt->nomor_lhp);
    }

    public function test_selesai_tanpa_lhp_memakai_tanggal_selesai(): void
    {
        app(ImporSpt::class)->handle($this->admin, $this->analisis([$this->baris(['nomor_lhp' => '', 'tanggal_lhp' => ''])]), 'a.xlsx');
        $this->assertSame('2025-03-14 00:00:00', Spt::sole()->ditandai_selesai_pada->format('Y-m-d H:i:s'));
    }

    public function test_impor_ulang_semua_galat(): void
    {
        $rows = $this->berkasContoh();
        app(ImporSpt::class)->handle($this->admin, $rows, 'spt-lama.xlsx');

        $ulang = $this->berkasContoh();
        $this->assertSame(['error', 'error', 'error'], array_column($ulang, 'status'));
        $this->assertSame(['Nomor SPT 700/751/I/SPT/2025 sudah ada.'], array_column($ulang[0]['errors'], 'pesan'));
        $this->assertSame(['Nomor SPT 700/120/I/SPT/2025 sudah ada.'], array_column($ulang[1]['errors'], 'pesan'));
        $this->assertSame(0, app(ImporSpt::class)->handle($this->admin, $ulang, 'spt-lama.xlsx'));
        $this->assertSame(2, Spt::count());
        $this->assertSame(1, Aktivitas::where('log_name', 'impor')->count()); // nothing imported: no summary
    }

    /** Item deleted or moved to another irban between preview and import: nothing is written; untouched item imports (control). */
    public function test_item_pkpt_berubah_setelah_pratinjau(): void
    {
        $rows = $this->analisis([$this->baris()]);
        $this->assertSame($this->item->id, $rows[0]['data']['pkpt_item_id']);

        foreach ([['status' => StatusItemPkpt::Dihapus], ['irban_id' => Irban::factory()->create()->id]] as $ubah) {
            $asli = $this->item->only(array_keys($ubah));
            $this->item->update($ubah);
            try {
                app(ImporSpt::class)->handle($this->admin, $rows, 'a.xlsx');
                $this->fail('ValidationException expected');
            } catch (ValidationException $e) {
                $this->assertSame(['impor' => ['Item PKPT untuk SPT 700/751/I/SPT/2025 sudah berubah. Muat ulang pratinjau.']], $e->errors());
            }
            $this->assertSame(0, Spt::count());
            $this->item->update($asli);
        }

        $this->assertSame(1, app(ImporSpt::class)->handle($this->admin, $rows, 'a.xlsx'));
        $this->assertSame($this->item->id, Spt::sole()->pkpt_item_id);
    }

    /** The parser's matching rule is re-run at import: an item period moved off the SPT dates, or a second overlapping item, rejects the file. */
    public function test_item_pkpt_tidak_lagi_tunggal_atau_tumpang_tindih(): void
    {
        $rows = $this->analisis([$this->baris()]);
        $this->assertSame($this->item->id, $rows[0]['data']['pkpt_item_id']);
        $pesan = 'Item PKPT untuk SPT 700/751/I/SPT/2025 sudah berubah. Muat ulang pratinjau.';

        $this->item->update(['tanggal_mulai' => '2025-05-01', 'tanggal_selesai' => '2025-05-31']);
        $this->assertThrows(fn () => app(ImporSpt::class)->handle($this->admin, $rows, 'a.xlsx'), ValidationException::class, $pesan);
        $this->item->update(['tanggal_mulai' => '2025-03-01', 'tanggal_selesai' => '2025-03-31']);

        $kedua = $this->item->replicate();
        $kedua->save();
        $this->assertThrows(fn () => app(ImporSpt::class)->handle($this->admin, $rows, 'a.xlsx'), ValidationException::class, $pesan);
        $this->assertSame(0, Spt::count());

        $kedua->update(['tanggal_mulai' => '2025-08-01', 'tanggal_selesai' => '2025-08-31']); // control: no longer overlaps
        $this->assertSame(1, app(ImporSpt::class)->handle($this->admin, $rows, 'a.xlsx'));
        $this->assertSame($this->item->id, Spt::sole()->pkpt_item_id);
    }

    public function test_balapan_nomor(): void
    {
        $rows = $this->berkasContoh();
        Spt::factory()->diterima()->create(['nomor_lengkap' => '700/120/I/SPT/2025']);

        try {
            app(ImporSpt::class)->handle($this->admin, $rows, 'spt-lama.xlsx');
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertSame(['impor' => ['Nomor SPT 700/120/I/SPT/2025 sudah dipakai. Muat ulang pratinjau.']], $e->errors());
        }
        $this->assertSame(0, Spt::where('sumber', SumberSpt::Impor)->count());
        $this->assertSame(0, Aktivitas::where('log_name', 'impor')->count());
    }

    public function test_harian_tidak_memberi_tahu(): void
    {
        User::factory()->irban()->create(['irban_id' => $this->irban->id]);
        $rows = $this->analisis([$this->baris(['nomor_spt' => '700/1/I/SPT/2026', 'tanggal_spt' => '01/09/2026', 'klasifikasi' => 'KHUSUS',
            'tanggal_mulai' => '02/09/2026', 'tanggal_selesai' => '05/09/2026', 'nomor_lhp' => '', 'tanggal_lhp' => '', 'selesai' => 'tidak'])]);
        Notification::fake();
        $this->assertSame(1, app(ImporSpt::class)->handle($this->admin, $rows, 'a.xlsx'));
        $this->travelTo(CarbonImmutable::parse('2026-10-26 06:00', 'Asia/Jakarta'));
        $this->assertContains('terlambat', app(PenandaSpt::class)->untuk(Spt::sole()));

        $this->artisan('notifikasi:harian')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_hanya_admin(): void
    {
        $rows = $this->berkasContoh();
        $this->expectException(AuthorizationException::class);
        app(ImporSpt::class)->handle(User::factory()->pimpinan()->create(), $rows, 'a.xlsx');
    }

    public function test_impor_pegawai_dan_pkpt_mencatat_ringkasan(): void
    {
        $this->actingAs($this->admin);
        $pegawai = (new PegawaiImportParser)->analyze([['baris' => 2, 'nama_bergelar' => 'Budi, S.E.', 'nip' => '199001012015011001', 'pangkat' => 'Penata',
            'golongan' => 'III/c', 'jabatan' => 'Auditor', 'aktif' => 'ya']]);
        $this->assertSame(1, app(ImportPegawai::class)->handle($this->admin, $pegawai, 'pegawai.xlsx'));

        $pkpt = Pkpt::factory()->create(['tahun' => 2027]);
        $p = new PkptItemImportParser;
        $items = $p->analyze($pkpt, [['baris' => 2, 'irban' => 'Irban I', 'jenis_penugasan' => 'Reviu LKjIP', 'tanggal_mulai' => '02/03/2027',
            'tanggal_selesai' => '20/03/2027', 'keterangan' => '', 'target_spt' => '']]);
        $this->assertSame(1, app(ImportPkptItems::class)->handle($this->admin, $pkpt, $items, true, 'pkpt.xlsx'));
        $this->assertSame(0, app(ImportPkptItems::class)->handle($this->admin, $pkpt, [], true, 'kosong.xlsx'));

        $log = Aktivitas::where('log_name', 'impor')->orderBy('id')->get();
        $this->assertSame([['jenis' => 'pegawai', 'berkas' => 'pegawai.xlsx', 'jumlah' => 1], ['jenis' => 'pkpt', 'berkas' => 'pkpt.xlsx', 'jumlah' => 1]],
            $log->map(fn ($a) => $a->properties->all())->all());
        $this->assertSame([$this->admin->id, $this->admin->id], $log->pluck('causer_id')->all());
    }

    public function test_hanya_baris_valid_dan_peringatan(): void
    {
        $rows = $this->analisis([$this->baris(['nomor_spt' => 'LAMA-1']), $this->baris(['nomor_spt' => ''])]);
        $this->assertSame(['warning', 'error'], array_column($rows, 'status'));
        $this->assertSame(1, app(ImporSpt::class)->handle($this->admin, $rows, 'a.xlsx'));
        $this->assertNull(Spt::sole()->nomor_urut);
        $this->assertTrue(in_array('nomor_spt', SptImportParser::TEXT_COLUMNS, true));
    }
}
