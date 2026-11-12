<?php

namespace Tests\Feature\Notifikasi;

use App\Actions\MasterData\SaveIrban;
use App\Models\Irban;
use App\Models\PkptPerubahan;
use App\Models\Spt;
use App\Models\User;
use App\Notifications\EksporGagal;
use App\Notifications\KataSandiDiubah;
use App\Notifications\NomorDibatalkan;
use App\Notifications\NotifikasiSintas;
use App\Notifications\PengingatSptFinal;
use App\Notifications\PerubahanPkptDiajukan;
use App\Notifications\RingkasanMingguan;
use App\Notifications\SptTerlambat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

/** Fase 11.2 Task 2: email content gaps (CTA per jenis, toMail bodies, payload scalars, tembusan trimming, escape round trip). */
class IsiNotifikasiTest extends TestCase
{
    use RefreshDatabase;

    private function hasil(int $lainnya = 0): array
    {
        return ['awal' => CarbonImmutable::parse('2026-04-06'), 'akhir' => CarbonImmutable::parse('2026-04-12'), 'nomor' => 1, 'selesai' => 2, 'menunggu_nomor' => 3,
            'perubahan' => 4, 'terlambat' => 5, 'daftar' => ['Irban I · 71 hari sejak tanggal selesai'], 'lainnya' => $lainnya];
    }

    public function test_cta_label_and_url_per_notification_with_a_url(): void
    {
        $spt = Spt::factory()->diterima()->create();
        $user = User::factory()->pimpinan()->create();
        $perubahan = PkptPerubahan::factory()->diajukan()->create();
        $kasus = [
            [new SptTerlambat($spt, 14), 'Buka SPT', '/spt/'.$spt->id],
            [new PengingatSptFinal($spt), 'Buka SPT', '/spt/'.$spt->id],
            [new PerubahanPkptDiajukan($perubahan), 'Buka SINTAS', '/pimpinan/perubahan-pkpt/'.$perubahan->id],
            [new EksporGagal('rekap.xlsx', '/rekap'), 'Buka SINTAS', '/rekap'],
            [new KataSandiDiubah('budi'), 'Buka SINTAS', '/profil'],
        ];
        foreach ($kasus as [$n, $label, $path]) {
            $mail = $n->toMail($user);
            $this->assertSame($label, $mail->actionText, $n::class);
            $this->assertSame(url($path), $mail->actionUrl, $n::class);
            $this->assertStringContainsString($label, (string) $mail->render(), $n::class);
        }
    }

    public function test_spt_terlambat_and_pengingat_final_mail_content(): void
    {
        $spt = Spt::factory()->diterima()->create(['nomor_lengkap' => '700/9/INSPEKTORAT-I/SPT/2026', 'tanggal_mulai' => '2026-03-10', 'tanggal_selesai' => '2026-03-20']);
        $user = User::factory()->irban()->create();

        $terlambat = (new SptTerlambat($spt, 14))->toMail($user);
        $this->assertSame('[SINTAS] SPT terlambat diselesaikan', $terlambat->subject);
        $this->assertSame(['Penyusunan laporan sudah lebih dari 14 hari sejak tanggal selesai 20 Maret 2026.'], $terlambat->introLines);

        $final = (new PengingatSptFinal($spt))->toMail($user);
        $this->assertSame('[SINTAS] Pengingat: SPT final belum diunggah', $final->subject);
        $this->assertSame(['Penugasan dimulai 10 Maret 2026. Unggah SPT final bertanda tangan di halaman detail SPT.'], $final->introLines);
        $this->assertStringContainsString('10 Maret 2026', (string) $final->render());
    }

    public function test_ringkasan_lists_the_remainder_line_only_when_there_is_one(): void
    {
        $this->assertContains('dan 3 lainnya.', (new RingkasanMingguan($this->hasil(3)))->isi());
        $this->assertNotContains('dan 0 lainnya.', (new RingkasanMingguan($this->hasil(0)))->isi());
        foreach ((new RingkasanMingguan($this->hasil(0)))->isi() as $baris) {
            $this->assertStringNotContainsString('lainnya', $baris);
        }
    }

    public function test_ringkasan_payload_is_scalar_and_konteks_is_rebuilt_on_render(): void
    {
        $n = new RingkasanMingguan($this->hasil());
        $mentah = serialize($n);

        $this->assertStringNotContainsString('Carbon', $mentah);
        $kembali = unserialize($mentah);
        $this->assertSame('06 April 2026 – 12 April 2026', $kembali->konteks());
        $this->assertSame($n->isi(), $kembali->isi());
    }

    public function test_tembusan_is_trimmed_when_read_and_when_saved(): void
    {
        $irban = Irban::factory()->create(['email_notifikasi' => ' irban@example.test ', 'email_tembusan' => ['  kasubag@example.test', '   ', 'IRBAN@example.test  ']]);
        $user = User::factory()->irban()->create(['irban_id' => $irban->id]);
        $this->assertSame(['irban@example.test', 'kasubag@example.test'], $user->routeNotificationForMail());

        $disimpan = app(SaveIrban::class)->handle(null, ['nama' => 'Irban Z', 'kodeNomor' => 'Z', 'emailNotifikasi' => null, 'tembusan' => [' a@example.test ', '', '  ']]);
        $this->assertSame(['a@example.test'], $disimpan->email_tembusan);
        $kosong = app(SaveIrban::class)->handle(null, ['nama' => 'Irban Y', 'kodeNomor' => 'Y', 'emailNotifikasi' => null, 'tembusan' => ['  ']]);
        $this->assertNull($kosong->email_tembusan);
    }

    public function test_aman_and_tanpa_escape_are_inverse_over_every_escaped_character(): void
    {
        $semua = '\`*_{}[]()#+!|~:-';
        $aman = NotifikasiSintas::aman($semua);

        $this->assertSame(strlen($semua) * 2, strlen($aman)); // each one got exactly one backslash
        $this->assertSame($semua, NotifikasiSintas::tanpaEscape($aman));
        $this->assertSame('a b', NotifikasiSintas::aman("a\r\nb"));
    }

    /** versi 1.2.1 Task 4: the email names the number, the canceller and the reason; the canceller's name is user text (escaped), and the payload survives the queue. */
    public function test_nomor_dibatalkan_names_the_canceller(): void
    {
        $spt = Spt::factory()->diterima()->create(['nomor_lengkap' => '700/9/INSPEKTORAT-I/SPT/2026']);
        $user = User::factory()->bagianUmum()->create();
        $n = unserialize(serialize(new NomorDibatalkan($spt, 'Penugasan digabung.', 'Irban I (diinput oleh [Budi](http://x))')));

        $mail = $n->toMail($user);
        $html = (string) $mail->render();
        $this->assertStringContainsString('Nomor SPT 700/9/INSPEKTORAT-I/SPT/2026 dibatalkan oleh Irban I (diinput oleh [Budi](http://x)).', strip_tags($html));
        $this->assertStringNotContainsString('<a href="http://x"', $html);
        $this->assertStringContainsString('Catatan: Penugasan digabung.', strip_tags($html));
        $this->assertStringContainsString('dibatalkan oleh Irban I (diinput oleh [Budi](http://x)).', (string) app(Markdown::class)->renderText($mail->markdown, $mail->data()));
        $this->assertSame('Penugasan digabung.', $n->toArray($user)['catatan']);
    }
}
