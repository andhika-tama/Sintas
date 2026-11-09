<?php

namespace Database\Factories;

use App\Enums\StatusPengajuan;
use App\Models\Irban;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Spt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Spt> Default: Draf, klasifikasi KHUSUS (no PKPT item required), kode arsip 700, no number. */
class SptFactory extends Factory
{
    protected $model = Spt::class;

    public function definition(): array
    {
        return [
            'irban_id' => Irban::factory(),
            'klasifikasi_id' => fn () => Klasifikasi::where('kode', 'KHUSUS')->value('id'),
            'kode_arsip_id' => fn () => KodeArsip::firstOrCreate(['kode' => '700'], ['nama' => 'Pengawasan Umum'])->id,
            'periode_diperiksa' => 'Tahun Anggaran 2025',
            'uraian_untuk' => 'Melaksanakan pemeriksaan khusus.',
            'tanggal_mulai' => '2026-03-02',
            'tanggal_selesai' => '2026-03-20',
            'status_pengajuan' => StatusPengajuan::Draf,
            'dibuat_oleh' => User::factory(),
        ];
    }

    public function menungguNomor(): static
    {
        return $this->state(['status_pengajuan' => StatusPengajuan::MenungguNomor, 'diajukan_pada' => now()]);
    }

    public function perluRevisi(): static
    {
        return $this->state(['status_pengajuan' => StatusPengajuan::PerluRevisi, 'diajukan_pada' => now()]);
    }

    public function ditolak(): static
    {
        return $this->state(['status_pengajuan' => StatusPengajuan::Ditolak, 'diajukan_pada' => now()]);
    }

    /** Placeholder number outside any counter; tests that need real numbering use Penomoran. */
    public function diterima(): static
    {
        return $this->state(fn () => [
            'status_pengajuan' => StatusPengajuan::Diterima, 'diajukan_pada' => now(), 'tanggal_spt' => now()->toDateString(), 'tahun' => now()->year,
            'nomor_urut' => $n = fake()->unique()->numberBetween(1, 999999), 'nomor_lengkap' => "FACTORY/{$n}",
        ]);
    }

    public function selesai(): static
    {
        return $this->diterima()->state(['ditandai_selesai_pada' => now(), 'ditandai_selesai_oleh' => User::factory()->irban()]);
    }

    public function dibatalkan(): static
    {
        return $this->diterima()->state(['status_pengajuan' => StatusPengajuan::Dibatalkan]);
    }
}
