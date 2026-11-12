<?php

namespace Tests\Feature\Notifikasi;

use App\Actions\Spt\UbahTanggalRealisasi;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\Pegawai;
use App\Models\Spt;
use App\Models\User;
use App\Notifications\TanggalRealisasiDiubah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** 1.2.1 Task 5 (K6): date change of a numbered SPT notifies Bagian Umum + Pimpinan, bell only. */
class NotifikasiTanggalRealisasiTest extends TestCase
{
    use RefreshDatabase;

    private function siapkan(): array
    {
        $irban = Irban::factory()->create();
        $irbanUser = User::factory()->irban()->create();
        $irbanUser->forceFill(['irban_id' => $irban->id])->save();
        $penginput = Pegawai::factory()->create(['nama_bergelar' => 'Budi Santoso, S.E.']);
        IrbanTahunan::factory()->create(['irban_id' => $irban->id, 'tahun' => 2026, 'ppj_pegawai_id' => $penginput->id]);
        $spt = Spt::factory()->diterima()->create(['irban_id' => $irban->id, 'tanggal_mulai' => '2026-04-01', 'tanggal_selesai' => '2026-04-01']);

        return [$irbanUser, $spt, $penginput];
    }

    private function ubah(User $u, Spt $spt, Pegawai $p, array $over = []): void
    {
        app(UbahTanggalRealisasi::class)->handle($u, $spt, [
            'tanggal_mulai' => '2026-04-03', 'tanggal_selesai' => '2026-04-05', 'alasan' => 'Menyesuaikan jadwal pemeriksaan.', 'diinput_oleh' => $p->id, ...$over,
        ]);
    }

    public function test_bell_only_ke_bagian_umum_dan_pimpinan(): void
    {
        Notification::fake();
        [$irbanUser, $spt, $p] = $this->siapkan();
        $bu = User::factory()->bagianUmum()->create(['email' => 'bu@contoh.test']);
        $pim = User::factory()->pimpinan()->create(['email' => 'pim@contoh.test']);

        $this->ubah($irbanUser, $spt, $p);

        foreach ([$bu, $pim] as $u) {
            Notification::assertSentTo($u, TanggalRealisasiDiubah::class, fn ($n, $ch) => $ch === ['database']);
        }
        Notification::assertNotSentTo($irbanUser, TanggalRealisasiDiubah::class);
    }

    public function test_isi_memuat_nomor_tanggal_alasan_dan_penginput(): void
    {
        Notification::fake();
        [$irbanUser, $spt, $p] = $this->siapkan();
        $bu = User::factory()->bagianUmum()->create();

        $this->ubah($irbanUser, $spt, $p);

        Notification::assertSentTo($bu, TanggalRealisasiDiubah::class, function ($n) use ($bu, $spt, $p) {
            $d = $n->toArray($bu);
            $teks = ($d['konteks'] ?? '').' '.implode(' ', $n->isi());

            return $d['jenis'] === 'tanggal_realisasi_diubah'
                && $d['judul'] === 'Tanggal realisasi SPT diubah'
                && str_contains($d['konteks'], $spt->nomor_lengkap)
                && str_contains($d['konteks'], '1 April 2026 → 3–5 April 2026')
                && str_contains($d['konteks'], 'Diinput oleh '.$p->nama_bergelar)
                && $d['catatan'] === 'Menyesuaikan jadwal pemeriksaan.'
                && str_contains($teks, '1 April 2026 → 3–5 April 2026')
                && ! str_starts_with($d['url'], 'http');
        });
    }

    public function test_tanggal_tak_berubah_tidak_mengirim(): void
    {
        Notification::fake();
        [$irbanUser, $spt, $p] = $this->siapkan();
        User::factory()->bagianUmum()->create();

        try {
            $this->ubah($irbanUser, $spt, $p, ['tanggal_mulai' => '2026-04-01', 'tanggal_selesai' => '2026-04-01']);
            $this->fail('ValidationException expected');
        } catch (ValidationException) {
        }
        Notification::assertNothingSent();
    }
}
