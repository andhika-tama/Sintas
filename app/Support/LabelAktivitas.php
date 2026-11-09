<?php

namespace App\Support;

use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\IrbanWilayah;
use App\Models\JenisPenugasan;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Komentar;
use App\Models\LandingSlide;
use App\Models\NomorCounter;
use App\Models\Objek;
use App\Models\Pegawai;
use App\Models\PerubahanPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\PkptPerubahanItem;
use App\Models\PkptUsulan;
use App\Models\Spt;
use App\Models\SptDokumen;
use App\Models\SptKendala;
use App\Models\SptPersonel;
use App\Models\Tim;
use App\Models\TimAnggota;
use App\Models\User;
use Carbon\CarbonInterface;

/** Labels of the activity log (Fase 10 E2): one source for the log page, SPT detail and dashboard. */
class LabelAktivitas
{
    /** key => [class|null, label]; null class = a log without subject, keyed by its log name. */
    private const OBJEK = [
        'spt' => [Spt::class, 'SPT'],
        'spt_personel' => [SptPersonel::class, 'Personel SPT'],
        'spt_dokumen' => [SptDokumen::class, 'Dokumen SPT'],
        'spt_kendala' => [SptKendala::class, 'Kendala SPT'],
        'komentar' => [Komentar::class, 'Komentar'],
        'pkpt' => [Pkpt::class, 'PKPT'],
        'pkpt_item' => [PkptItem::class, 'Item PKPT'],
        'pkpt_perubahan' => [PkptPerubahan::class, 'Perubahan PKPT'],
        'pkpt_perubahan_item' => [PkptPerubahanItem::class, 'Operasi Perubahan PKPT'],
        'pkpt_usulan' => [PkptUsulan::class, 'Usulan Perubahan'],
        'perubahan_penugasan' => [PerubahanPenugasan::class, 'Perubahan Penugasan'],
        'user' => [User::class, 'Pengguna'],
        'pegawai' => [Pegawai::class, 'Pegawai'],
        'irban' => [Irban::class, 'Irban'],
        'irban_tahunan' => [IrbanTahunan::class, 'Komposisi Irban Tahunan'],
        'tim' => [Tim::class, 'Tim'],
        'tim_anggota' => [TimAnggota::class, 'Anggota Tim'],
        'objek' => [Objek::class, 'Objek Pemeriksaan'],
        'irban_wilayah' => [IrbanWilayah::class, 'Wilayah Irban'],
        'jenis_penugasan' => [JenisPenugasan::class, 'Jenis Penugasan'],
        'klasifikasi' => [Klasifikasi::class, 'Klasifikasi'],
        'kode_arsip' => [KodeArsip::class, 'Kode Arsip'],
        'landing_slide' => [LandingSlide::class, 'Slide Beranda'],
        'nomor_counter' => [NomorCounter::class, 'Nomor SPT'],
        'pengaturan' => [null, 'Pengaturan Sistem'],
        'impor' => [null, 'Impor'],
    ];

    private const AKSI = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'impor' => 'Impor data',
        'profil.foto' => 'Foto profil diubah',
        'profil.latar' => 'Gambar latar diubah',
        'profil.hapus_foto' => 'Foto profil dihapus',
        'profil.hapus_latar' => 'Gambar latar dihapus',
        'profil.kata_sandi' => 'Kata sandi diubah',
        'pengguna.kata_sandi' => 'Kata sandi diatur ulang',
    ];

    private const JENIS_IMPOR = ['spt' => 'SPT data lama', 'pegawai' => 'Pegawai', 'pkpt' => 'Item PKPT'];

    public static function objek(?string $subjectType, ?string $logName = null): string
    {
        foreach (self::OBJEK as $key => [$kelas, $label]) {
            if ($subjectType ? $kelas === $subjectType : ($kelas === null && $key === $logName)) {
                return $label;
            }
        }

        return $subjectType ? class_basename($subjectType) : (string) $logName;
    }

    public static function aksi(?string $event): string
    {
        return self::AKSI[$event ?? ''] ?? ($event ?? '—');
    }

    /** One time format for every log line: Jakarta time, e.g. "05 Maret 2026 14.30 WIB". */
    public static function waktu(CarbonInterface $waktu): string
    {
        return $waktu->copy()->setTimezone('Asia/Jakarta')->translatedFormat('d F Y H.i').' WIB';
    }

    public static function jenisImpor(string $jenis): string
    {
        return self::JENIS_IMPOR[$jenis] ?? $jenis;
    }

    /** @return array<string, string> key => label, in E2 order */
    public static function opsiObjek(): array
    {
        return array_map(fn (array $o) => $o[1], self::OBJEK);
    }

    public static function kelas(string $key): ?string
    {
        return self::OBJEK[$key][0] ?? null;
    }
}
