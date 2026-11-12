<?php

namespace Tests\Feature\Impor;

use App\Models\Klasifikasi;
use App\Models\NomorCounter;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Services\Import\SptImportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SptImportParserTest extends TestCase
{
    use RefreshDatabase, SptImportTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedImpor();
    }

    private function pesan(array $row): array
    {
        return array_column($row['errors'], 'pesan');
    }

    public function test_header_wajib(): void
    {
        $header = array_values(array_diff(SptImportParser::HEADERS, ['nomor_spt', 'selesai']));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Kolom wajib tidak ditemukan: nomor_spt, selesai. Gunakan templat.');
        (new SptImportParser)->read($this->xlsx([$this->baris()], $header));
    }

    public function test_konstanta(): void
    {
        $this->assertSame(['nomor_spt', 'tanggal_spt', 'irban', 'klasifikasi', 'jenis_penugasan', 'uraian_untuk', 'periode_diperiksa', 'objek',
            'tanggal_mulai', 'tanggal_selesai', 'nip_supervisor', 'nip_ketua', 'nip_anggota', 'nomor_lhp', 'tanggal_lhp', 'selesai',
            'kendala_kategori', 'kendala_uraian'], SptImportParser::HEADERS);
        $this->assertSame(['nomor_spt', 'nip_supervisor', 'nip_ketua', 'nip_anggota', 'nomor_lhp'], SptImportParser::TEXT_COLUMNS);
        $this->assertSame(2000, SptImportParser::MAX_ROWS);
        $this->assertSame('Berkas terlalu besar: maksimal 2000 baris data per impor.', SptImportParser::capMessage());
    }

    public function test_contoh_valid(): void
    {
        [$r] = $this->analisis([$this->baris(['irban' => ' i ', 'klasifikasi' => 'reguler', 'objek' => 'kecamatan lawang kidul; Kecamatan Tanjung Agung'])]);
        $this->assertSame('valid', $r['status'], json_encode($r));
        $this->assertSame(2, $r['baris']);
        $this->assertSame([], $r['warnings']);
        $d = $r['data'];
        $this->assertSame($this->irban->id, $d['irban_id']);
        $this->assertSame($this->klasifikasiId('REGULER'), $d['klasifikasi_id']);
        $this->assertSame($this->jenis->id, $d['jenis_penugasan_id']);
        $this->assertSame($this->item->id, $d['pkpt_item_id']);
        $this->assertCount(2, $d['objek_ids']);
        $this->assertSame([
            ['pegawai_id' => $this->pegawai[self::S]->id, 'peran' => 'supervisor_dalnis'],
            ['pegawai_id' => $this->pegawai[self::K]->id, 'peran' => 'ketua'],
            ['pegawai_id' => $this->pegawai[self::A1]->id, 'peran' => 'anggota'],
            ['pegawai_id' => $this->pegawai[self::A2]->id, 'peran' => 'anggota'],
        ], $d['personel']);
        $this->assertSame(751, $d['nomor_urut']);
        $this->assertSame(2025, $d['tahun']);
        $this->assertSame(['2025-02-28', '2025-03-03', '2025-03-14', '2025-03-20'], [$d['tanggal_spt'], $d['tanggal_mulai'], $d['tanggal_selesai'], $d['tanggal_lhp']]);
        $this->assertTrue($d['selesai']);
        $this->assertSame(['Irban I', 'REGULER', 'Reviu LKjIP', '700/751/I/SPT/2025', 4], [$d['irban'], $d['klasifikasi'], $d['jenis'], $d['nomor_spt'], $d['jumlah_personel']]);
    }

    public function test_tanggal_serial_dan_iso(): void
    {
        [$r] = $this->analisis([$this->baris(['tanggal_spt' => '2025-02-28', 'tanggal_mulai' => 45719])]); // 45719 = 2025-03-03
        $this->assertSame('valid', $r['status'], json_encode($r));
        $this->assertSame(['2025-02-28', '2025-03-03'], [$r['data']['tanggal_spt'], $r['data']['tanggal_mulai']]);
    }

    public static function galat(): array
    {
        $date = 'Format tanggal tidak dikenali (gunakan dd/mm/yyyy).';

        return [
            'nomor kosong' => [['nomor_spt' => ''], 'nomor_spt', 'Nomor SPT wajib diisi.'],
            'nomor panjang' => [['nomor_spt' => str_repeat('A', 256)], 'nomor_spt', 'Nomor SPT maksimal 255 karakter.'],
            'nomor angka' => [['nomor_spt' => 751], 'nomor_spt', 'Nomor SPT tersimpan sebagai angka; ubah kolom menjadi teks.'],
            'tanggal spt kosong' => [['tanggal_spt' => ''], 'tanggal_spt', 'Tanggal SPT wajib diisi.'],
            'tanggal mulai kosong' => [['tanggal_mulai' => ''], 'tanggal_mulai', 'Tanggal mulai wajib diisi.'],
            'tanggal selesai kosong' => [['tanggal_selesai' => ''], 'tanggal_selesai', 'Tanggal selesai wajib diisi.'],
            'tanggal spt format' => [['tanggal_spt' => '1 Maret 2025'], 'tanggal_spt', $date],
            'tanggal mulai mustahil' => [['tanggal_mulai' => '31/02/2025'], 'tanggal_mulai', $date],
            'tanggal selesai format' => [['tanggal_selesai' => 'besok'], 'tanggal_selesai', $date],
            'urutan tanggal' => [['tanggal_selesai' => '01/03/2025'], 'tanggal_selesai', 'Tanggal selesai tidak boleh sebelum tanggal mulai.'],
            'irban kosong' => [['irban' => ''], 'irban', 'Irban wajib diisi.'],
            'irban asing' => [['irban' => 'Irban IX'], 'irban', 'Irban "Irban IX" tidak ditemukan.'],
            'klasifikasi kosong' => [['klasifikasi' => ''], 'klasifikasi', 'Klasifikasi wajib diisi.'],
            'klasifikasi asing' => [['klasifikasi' => 'RUTIN'], 'klasifikasi', 'Klasifikasi "RUTIN" tidak ditemukan.'],
            'jenis asing' => [['jenis_penugasan' => 'Reviu X'], 'jenis_penugasan', 'Jenis penugasan "Reviu X" tidak ditemukan.'],
            'uraian kosong' => [['uraian_untuk' => ''], 'uraian_untuk', 'Uraian wajib diisi.'],
            'uraian panjang' => [['uraian_untuk' => str_repeat('a', 1001)], 'uraian_untuk', 'Uraian maksimal 1000 karakter.'],
            'periode panjang' => [['periode_diperiksa' => str_repeat('a', 256)], 'periode_diperiksa', 'Periode maksimal 255 karakter.'],
            'objek asing' => [['objek' => 'Kecamatan Lawang Kidul; Kecamatan Entah'], 'objek', 'Objek "Kecamatan Entah" tidak ditemukan.'],
            'objek ganda' => [['objek' => 'Desa Ganda'], 'objek', 'Objek "Desa Ganda" lebih dari satu; gunakan nama yang unik.'],
            'nip angka' => [['nip_anggota' => 198001012005011003], 'nip_anggota', 'NIP tersimpan sebagai angka; ubah kolom menjadi teks.'],
            'nip asing' => [['nip_anggota' => '199999999999999999'], 'nip_anggota', 'NIP 199999999999999999 tidak ditemukan.'],
            'tanpa ketua' => [['nip_ketua' => ''], 'nip_ketua', 'Isi tepat satu NIP ketua.'],
            'dua ketua' => [['nip_ketua' => self::K.';'.self::X], 'nip_ketua', 'Isi tepat satu NIP ketua.'],
            'dua supervisor' => [['nip_supervisor' => self::S.';'.self::X], 'nip_supervisor', 'Isi paling banyak satu NIP supervisor.'],
            'nip dua kali' => [['nip_anggota' => self::A1.';'.self::A1], 'nip_anggota', 'NIP '.self::A1.' tercantum lebih dari sekali.'],
            'nip dua kali lintas peran' => [['nip_anggota' => self::K], 'nip_anggota', 'NIP '.self::K.' tercantum lebih dari sekali.'],
            'lhp tanpa tanggal' => [['tanggal_lhp' => ''], 'nomor_lhp', 'Nomor dan tanggal LHP diisi bersama.'],
            'lhp tanpa nomor' => [['nomor_lhp' => ''], 'nomor_lhp', 'Nomor dan tanggal LHP diisi bersama.'],
            'lhp panjang' => [['nomor_lhp' => str_repeat('A', 256)], 'nomor_lhp', 'Nomor LHP maksimal 255 karakter.'],
            'tanggal lhp format' => [['tanggal_lhp' => '20-03-2025'], 'tanggal_lhp', $date],
            'selesai asing' => [['selesai' => 'mungkin'], 'selesai', 'Kolom selesai diisi ya atau tidak.'],
            'kendala tanpa uraian' => [['kendala_kategori' => 'Lainnya'], 'kendala_kategori', 'Kategori dan uraian kendala diisi bersama.'],
            'kendala tanpa kategori' => [['kendala_uraian' => 'Hujan'], 'kendala_kategori', 'Kategori dan uraian kendala diisi bersama.'],
            'kendala asing' => [['kendala_kategori' => 'Cuaca', 'kendala_uraian' => 'Hujan'], 'kendala_kategori', 'Kategori kendala "Cuaca" tidak dikenal.'],
            'kendala panjang' => [['kendala_kategori' => 'Lainnya', 'kendala_uraian' => str_repeat('a', 2001)], 'kendala_uraian', 'Uraian kendala maksimal 2000 karakter.'],
        ];
    }

    #[DataProvider('galat')]
    public function test_pesan_galat(array $override, string $kolom, string $pesan): void
    {
        [$r] = $this->analisis([$this->baris($override)]);
        $this->assertSame('error', $r['status']);
        $this->assertContains(['kolom' => $kolom, 'pesan' => $pesan], $r['errors'], json_encode($r['errors']));
    }

    public function test_beberapa_galat_tercantum_semua(): void
    {
        [$r] = $this->analisis([$this->baris(['irban' => '', 'klasifikasi' => '', 'uraian_untuk' => ''])]);
        $this->assertSame(['Irban wajib diisi.', 'Klasifikasi wajib diisi.', 'Uraian wajib diisi.'], $this->pesan($r));
    }

    public function test_nip_angka(): void
    {
        // The NIP of an existing pegawai typed as a number: never matched, always the numeric message.
        $raw = (new SptImportParser)->read($this->xlsx([$this->baris(['nip_ketua' => 198001012005011002])]));
        $this->assertTrue($raw[0]['nip_ketua_numerik']);
        $this->assertSame('', $raw[0]['nip_ketua']);
        [$r] = (new SptImportParser)->analyze($raw);
        $this->assertSame('error', $r['status']);
        $this->assertSame(['NIP tersimpan sebagai angka; ubah kolom menjadi teks.'], $this->pesan($r));
        $this->assertNotContains($this->pegawai[self::K]->id, array_column($r['data']['personel'], 'pegawai_id'));
    }

    public function test_nomor_sudah_ada(): void
    {
        Spt::factory()->diterima()->create(['nomor_lengkap' => '700/751/I/SPT/2025']);
        [$r] = $this->analisis([$this->baris(['nomor_spt' => ' 700/751/I/SPT/2025 '])]);
        $this->assertSame(['Nomor SPT 700/751/I/SPT/2025 sudah ada.'], $this->pesan($r));
    }

    public function test_nomor_ganda_dalam_berkas(): void
    {
        $r = $this->analisis([$this->baris(), $this->baris(['nomor_spt' => '700/752/I/SPT/2025']), $this->baris()]);
        $this->assertSame(['valid', 'valid', 'error'], array_column($r, 'status'));
        $this->assertSame(['Nomor SPT 700/751/I/SPT/2025 ganda dengan baris 2.'], $this->pesan($r[2]));
    }

    public function test_personel_kosong_boleh(): void
    {
        [$r] = $this->analisis([$this->baris(['nip_supervisor' => '', 'nip_ketua' => '', 'nip_anggota' => ''])]);
        $this->assertSame('valid', $r['status']);
        $this->assertSame([], $r['data']['personel']);
    }

    public function test_peringatan_tanpa_item_pkpt(): void
    {
        $r = $this->analisis([
            $this->baris(['tanggal_mulai' => '01/04/2025', 'tanggal_selesai' => '10/04/2025', 'tanggal_lhp' => '15/04/2025']),
            $this->baris(['nomor_spt' => '700/752/I/SPT/2025', 'jenis_penugasan' => '']),
            $this->baris(['nomor_spt' => '700/753/I/SPT/2025', 'klasifikasi' => 'KHUSUS', 'jenis_penugasan' => '']), // not wajib_item_pkpt
        ]);
        $this->assertSame(['warning', 'warning', 'valid'], array_column($r, 'status'));
        $this->assertSame(['Tidak ada item PKPT yang cocok; SPT tercatat Non-PKPT.'], $r[0]['warnings']);
        $this->assertSame(['Tidak ada item PKPT yang cocok; SPT tercatat Non-PKPT.'], $r[1]['warnings']);
        $this->assertNull($r[0]['data']['pkpt_item_id']);
    }

    public function test_peringatan_dua_kandidat(): void
    {
        PkptItem::factory()->create(['pkpt_id' => $this->item->pkpt_id, 'irban_id' => $this->irban->id, 'jenis_penugasan_id' => $this->jenis->id,
            'tanggal_mulai' => '2025-03-14', 'tanggal_selesai' => '2025-04-30']); // touches 14 Maret: inclusive overlap
        PkptItem::factory()->dihapus()->create(['pkpt_id' => $this->item->pkpt_id, 'irban_id' => $this->irban->id, 'jenis_penugasan_id' => $this->jenis->id,
            'tanggal_mulai' => '2025-03-01', 'tanggal_selesai' => '2025-03-31']);
        $r = $this->analisis([$this->baris(), $this->baris(['nomor_spt' => '700/752/I/SPT/2025', 'klasifikasi' => 'KHUSUS'])]);
        $this->assertSame(['warning', 'warning'], array_column($r, 'status'));
        $this->assertSame(['Lebih dari satu item PKPT cocok; SPT tidak dikaitkan.'], $r[0]['warnings']);
        $this->assertSame(['Lebih dari satu item PKPT cocok; SPT tidak dikaitkan.'], $r[1]['warnings']);
        $this->assertNull($r[0]['data']['pkpt_item_id']);
    }

    public function test_pkpt_draf_tidak_dikaitkan(): void
    {
        $this->item->pkpt->forceFill(['status' => 'draf'])->save();
        [$r] = $this->analisis([$this->baris()]);
        $this->assertNull($r['data']['pkpt_item_id']);
        $this->assertSame(['Tidak ada item PKPT yang cocok; SPT tercatat Non-PKPT.'], $r['warnings']);
    }

    public function test_peringatan_nomor_urut_tak_terbaca(): void
    {
        $r = $this->analisis([$this->baris(['nomor_spt' => 'SPT-LAMA-01']), $this->baris(['nomor_spt' => '700/75a/I/SPT/2025'])]);
        $this->assertSame(['Nomor urut tidak terbaca; SPT tidak tampil di Register.'], $r[0]['warnings']);
        $this->assertSame(['Nomor urut tidak terbaca; SPT tidak tampil di Register.'], $r[1]['warnings']);
        $this->assertNull($r[0]['data']['nomor_urut']);
    }

    public function test_peringatan_bentrok_register(): void
    {
        NomorCounter::create(['tahun' => 2026, 'scope' => 'global'])->forceFill(['nomor_terakhir' => 5])->save();
        $tahunIni = ['tanggal_spt' => '01/10/2026', 'klasifikasi' => 'KHUSUS', 'tanggal_mulai' => '02/10/2026', 'tanggal_selesai' => '05/10/2026',
            'tanggal_lhp' => '06/10/2026'];
        $r = $this->analisis([
            $this->baris(['nomor_spt' => '700/10/I/SPT/2026'] + $tahunIni),
            $this->baris(['nomor_spt' => '700/5/I/SPT/2026'] + $tahunIni),
            $this->baris(['nomor_spt' => '700/900/I/SPT/2025', 'klasifikasi' => 'KHUSUS']), // past year: no risk
        ]);
        $this->assertSame(['Nomor urut 10 melebihi nomor terakhir register 2026; atur nomor awal di Register sebelum menerbitkan nomor baru.'], $r[0]['warnings']);
        $this->assertSame([[], []], [$r[1]['warnings'], $r[2]['warnings']]);
    }

    public function test_peringatan_selesai_tanpa_lhp(): void
    {
        $r = $this->analisis([
            $this->baris(['nomor_lhp' => '', 'tanggal_lhp' => '']),
            $this->baris(['nomor_spt' => '700/752/I/SPT/2025', 'klasifikasi' => 'DIKLAT', 'nomor_lhp' => '', 'tanggal_lhp' => '']), // konfirmasi
            $this->baris(['nomor_spt' => '700/753/I/SPT/2025', 'selesai' => 'tidak', 'nomor_lhp' => '', 'tanggal_lhp' => '']),
        ]);
        $this->assertSame(['SPT selesai tanpa nomor dan tanggal LHP.'], $r[0]['warnings']);
        $this->assertSame([[], []], [$r[1]['warnings'], $r[2]['warnings']]);
    }

    public function test_status_selesai(): void
    {
        $r = $this->analisis([
            $this->baris(['selesai' => '']),                                               // LHP pair filled -> selesai
            $this->baris(['nomor_spt' => '700/752/I/SPT/2025', 'selesai' => 'TIDAK']),        // LHP kept, not selesai
            $this->baris(['nomor_spt' => '700/753/I/SPT/2025', 'selesai' => '', 'nomor_lhp' => '', 'tanggal_lhp' => '']),
        ]);
        $this->assertSame([true, false, false], array_column(array_column($r, 'data'), 'selesai'));
        $this->assertSame('700/LHP/1/2025', $r[1]['data']['nomor_lhp']);
    }

    public function test_query_konstan(): void
    {
        $raw = [];
        foreach (range(1, 200) as $i) {
            $raw[] = ['baris' => $i + 1] + $this->baris(['nomor_spt' => "700/{$i}/I/SPT/2025", 'objek' => 'Kecamatan Lawang Kidul;Kecamatan Tanjung Agung']);
        }
        $p = new SptImportParser;
        DB::enableQueryLog();
        $r = $p->analyze($raw);
        $this->assertLessThanOrEqual(25, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertSame(array_fill(0, 200, 'valid'), array_column($r, 'status'));
    }

    public function test_raw_dirusak(): void
    {
        $p = new SptImportParser;
        $r = $p->analyze(['bukan baris', ['baris' => ['x'], 'nomor_spt' => ['a'], 'irban' => (object) [], 'nip_ketua_numerik' => 'ya'] + $this->baris(['nomor_spt' => ['a']])]);
        $this->assertSame(['error', 'error'], array_column($r, 'status'));
        $this->assertSame(0, $r[0]['baris']);
        $this->assertContains('Nomor SPT wajib diisi.', $this->pesan($r[1]));
        $this->assertContains('NIP tersimpan sebagai angka; ubah kolom menjadi teks.', $this->pesan($r[1]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(SptImportParser::capMessage());
        $p->analyze(array_fill(0, SptImportParser::MAX_ROWS + 1, $this->baris()));
    }

    public function test_baris_kosong_dilewati(): void
    {
        $raw = (new SptImportParser)->read($this->xlsx([$this->baris(), array_fill_keys(SptImportParser::HEADERS, ''), $this->baris(['nomor_spt' => 'B'])]));
        $this->assertSame([2, 4], array_column($raw, 'baris'));
    }

    public function test_nomor_sudah_ada_tanpa_peka_huruf(): void
    {
        Spt::factory()->diterima()->create(['nomor_lengkap' => '700/751/I/SPT/2025']);
        [$r] = $this->analisis([$this->baris(['nomor_spt' => '700/751/i/spt/2025'])]);
        $this->assertSame(['Nomor SPT 700/751/i/spt/2025 sudah ada.'], $this->pesan($r));
        [$r] = $this->analisis([$this->baris(['nomor_spt' => '700/752/I/SPT/2025'])]); // kontrol: nomor lain tidak bentrok
        $this->assertSame([], $this->pesan($r));
    }

    /** 1.2.1 Task 6: a deactivated klasifikasi cannot be imported for new SPT. */
    public function test_klasifikasi_nonaktif_ditolak(): void
    {
        Klasifikasi::where('kode', 'DIKLAT')->update(['aktif' => false]);
        [$r] = $this->analisis([$this->baris(['klasifikasi' => 'diklat'])]);
        $this->assertSame('error', $r['status']);
        $this->assertContains(['kolom' => 'klasifikasi', 'pesan' => 'Klasifikasi DIKLAT sudah nonaktif.'], $r['errors']);
    }
}
