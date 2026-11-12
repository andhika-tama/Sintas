<?php

namespace Tests\Feature\Notifikasi;

use App\Actions\Spt\BatalkanNomor;
use App\Actions\Spt\MintaRevisi;
use App\Actions\Spt\SimpanSpt;
use App\Actions\Spt\TerimaPengajuan;
use App\Actions\Spt\TolakPengajuan;
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
use App\Notifications\NomorDibatalkan;
use App\Notifications\PengajuanBaru;
use App\Notifications\PengajuanDiputuskan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Fase 6 Task 2: notifications sent by the pengajuan actions (the revisi-jadwal ones were removed in Fase 9.2). */
class NotifikasiPengajuanRevisiTest extends TestCase
{
    use RefreshDatabase;

    private Irban $irban;

    private User $irbanUser;

    private User $irbanLain;

    private User $bu;

    private User $pimpinan;

    private User $admin;

    private Pegawai $ppj;

    private Pegawai $anggota;

    private PkptItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Jakarta'));
        $this->irban = Irban::factory()->create(['nama' => 'Irban I']);
        $this->irbanUser = User::factory()->irban()->create(['irban_id' => $this->irban->id]);
        $this->irbanLain = User::factory()->irban()->create(['irban_id' => Irban::factory()->create()->id]);
        $this->bu = User::factory()->bagianUmum()->create();
        $this->pimpinan = User::factory()->pimpinan()->create();
        $this->admin = User::factory()->admin()->create();
        $this->ppj = Pegawai::factory()->create();
        $this->anggota = Pegawai::factory()->create();
        IrbanTahunan::factory()->create(['irban_id' => $this->irban->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->ppj->id])->anggota()->attach($this->anggota->id);
        $this->item = PkptItem::factory()->create([
            'pkpt_id' => Pkpt::factory()->disahkan()->create(['tahun' => 2026])->id, 'irban_id' => $this->irban->id,
            'jenis_penugasan_id' => JenisPenugasan::factory()->create(['nama' => 'Reviu LKjIP'])->id,
            'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-20',
        ]);
    }

    private function data(): array
    {
        return [
            'klasifikasi_id' => Klasifikasi::where('kode', 'REGULER')->value('id'), 'kode_arsip_id' => KodeArsip::where('kode', '700')->value('id'), 'pkpt_item_id' => $this->item->id,
            'objek_ids' => [Objek::factory()->create()->id], 'periode_diperiksa' => 'Tahun Anggaran 2025',
            'uraian_untuk' => 'Melaksanakan Reviu LKjIP.', 'tanggal_mulai' => '2026-03-02', 'tanggal_selesai' => '2026-03-10',
            'ppj_pegawai_id' => $this->ppj->id, 'diinput_oleh' => $this->anggota->id,
            'personel' => [
                ['pegawai_id' => $this->ppj->id, 'peran' => 'ketua', 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true],
                ['pegawai_id' => $this->anggota->id, 'peran' => 'anggota', 'sumber' => 'tambahan', 'tim_id' => null, 'ikut' => true],
            ],
        ];
    }

    private function simpan(string $mode, ?Spt $spt = null, array $over = []): Spt
    {
        return app(SimpanSpt::class)->handle($this->irbanUser, $spt, $over + $this->data(), $mode);
    }

    /** The single notification of this class sent to the user. */
    private function dikirim(User $user, string $kelas): Notification
    {
        $dapat = NotificationFacade::sent($user, $kelas);
        $this->assertCount(1, $dapat);

        return $dapat->first();
    }

    private function assertHanya(string $kelas, User ...$penerima): void
    {
        foreach ([$this->irbanUser, $this->irbanLain, $this->bu, $this->pimpinan, $this->admin] as $u) {
            in_array($u, $penerima, true) ? NotificationFacade::assertSentTo($u, $kelas) : NotificationFacade::assertNotSentTo($u, $kelas);
        }
    }

    /** The action must throw a ValidationException and send nothing. */
    private function gagal(callable $fn): void
    {
        NotificationFacade::fake();
        try {
            $fn();
            $this->fail('ValidationException expected');
        } catch (ValidationException) {
            NotificationFacade::assertNothingSent();
        }
    }

    public function test_pengajuan_baru_goes_to_bagian_umum_on_submit_and_resubmit_only(): void
    {
        NotificationFacade::fake();
        $draf = $this->simpan('draf');
        $this->simpan('draf', $draf);
        NotificationFacade::assertNothingSent();

        $spt = $this->simpan('ajukan', $draf);

        $this->assertHanya(PengajuanBaru::class, $this->bu);
        $this->assertSame([
            'jenis' => 'pengajuan_baru', 'judul' => 'Pengajuan nomor SPT baru dari Irban I', 'konteks' => 'Reviu LKjIP · Melaksanakan Reviu LKjIP.',
            'url' => '/bagian-umum/pengajuan?pilih='.$spt->id, 'catatan' => null,
        ], $this->dikirim($this->bu, PengajuanBaru::class)->toArray($this->bu));

        // Perlu Revisi, then resubmit: sent again.
        $spt->forceFill(['status_pengajuan' => StatusPengajuan::PerluRevisi])->save();
        $this->simpan('ajukan', $spt);
        $this->assertCount(2, NotificationFacade::sent($this->bu, PengajuanBaru::class));
    }

    public function test_pengajuan_baru_not_sent_on_validation_error_or_locked_spt(): void
    {
        $this->gagal(fn () => $this->simpan('ajukan', null, ['periode_diperiksa' => '']));

        $terkunci = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id, 'dibuat_oleh' => $this->irbanUser->id]);
        $this->gagal(fn () => $this->simpan('ajukan', $terkunci));
    }

    public function test_pengajuan_diputuskan_goes_to_the_irban_with_catatan_and_subjects(): void
    {
        NotificationFacade::fake();
        $kasus = [
            ['terima', 'pengajuan_diterima', 'Nomor SPT terbit', 'SPT 700/', null],
            ['revisi', 'pengajuan_perlu_revisi', 'Pengajuan nomor SPT perlu direvisi', 'Reviu LKjIP · Melaksanakan', 'Perbaiki objek pemeriksaan.'],
            ['tolak', 'pengajuan_ditolak', 'Pengajuan nomor SPT ditolak', 'Reviu LKjIP · Melaksanakan', 'Tidak sesuai ketentuan.'],
        ];
        foreach ($kasus as [$aksi, $jenis, $judul, $konteks, $catatan]) {
            $spt = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id, 'jenis_penugasan_id' => $this->item->jenis_penugasan_id, 'uraian_untuk' => 'Melaksanakan Reviu LKjIP.']);
            match ($aksi) {
                'terima' => app(TerimaPengajuan::class)->handle($this->bu, $spt),
                'revisi' => app(MintaRevisi::class)->handle($this->bu, $spt, $catatan),
                'tolak' => app(TolakPengajuan::class)->handle($this->bu, $spt, $catatan),
            };
            $n = NotificationFacade::sent($this->irbanUser, PengajuanDiputuskan::class)->last();
            $data = $n->toArray($this->irbanUser);
            $this->assertSame([$jenis, $judul, '/spt/'.$spt->id, $catatan], [$data['jenis'], $data['judul'], $data['url'], $data['catatan']]);
            $this->assertStringStartsWith($konteks, $data['konteks']);
            $mail = $n->toMail($this->irbanUser);
            $this->assertSame('[SINTAS] '.$judul, $mail->subject);
            if ($aksi === 'terima') {
                $this->assertContains('Nomor SPT: '.$spt->fresh()->nomor_lengkap, $mail->introLines);
                $this->assertSame('SPT '.$spt->fresh()->nomor_lengkap, $data['konteks']);
            }
        }
        $this->assertCount(3, NotificationFacade::sent($this->irbanUser, PengajuanDiputuskan::class));
        foreach ([$this->irbanLain, $this->bu, $this->pimpinan, $this->admin] as $u) {
            NotificationFacade::assertNotSentTo($u, PengajuanDiputuskan::class);
        }
    }

    public function test_pengajuan_diputuskan_not_sent_on_conflict_or_invalid_catatan(): void
    {
        $diterima = Spt::factory()->diterima()->create(['irban_id' => $this->irban->id]);
        $this->gagal(fn () => app(TolakPengajuan::class)->handle($this->bu, $diterima, 'Catatan yang cukup.'));

        $menunggu = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id]);
        $this->gagal(fn () => app(MintaRevisi::class)->handle($this->bu, $menunggu, 'pendek'));
    }

    /** versi 1.2.1 K5: cancelled by Bagian Umum goes to the irban and every Pimpinan. */
    public function test_nomor_dibatalkan_goes_to_the_irban_with_alasan(): void
    {
        NotificationFacade::fake();
        $spt = Spt::factory()->diterima()->create(['irban_id' => $this->irban->id, 'nomor_lengkap' => '700/1/INSPEKTORAT-I/SPT/2026']);

        app(BatalkanNomor::class)->handle($this->bu, $spt, 'Salah ketik perihal SPT');

        $this->assertHanya(NomorDibatalkan::class, $this->irbanUser, $this->pimpinan);
        $this->assertSame([
            'jenis' => 'nomor_dibatalkan', 'judul' => 'Nomor SPT dibatalkan', 'konteks' => 'SPT 700/1/INSPEKTORAT-I/SPT/2026',
            'url' => '/spt/'.$spt->id, 'catatan' => 'Salah ketik perihal SPT',
        ], $this->dikirim($this->irbanUser, NomorDibatalkan::class)->toArray($this->irbanUser));

        // Already cancelled, and alasan too short.
        $this->gagal(fn () => app(BatalkanNomor::class)->handle($this->bu, $spt->fresh(), 'Alasan yang cukup panjang'));
        $this->gagal(fn () => app(BatalkanNomor::class)->handle($this->bu, $spt->fresh(), 'pendek'));
    }

    public function test_notification_inside_a_rolled_back_transaction_is_not_delivered(): void
    {
        $spt = Spt::factory()->menungguNomor()->create(['irban_id' => $this->irban->id]);

        try {
            DB::transaction(function () use ($spt) {
                $this->bu->notify(new PengajuanBaru($spt->load('irban')));
                // afterCommit: nothing is written while the transaction is still open (fails if afterCommit is removed).
                $this->assertSame(0, DB::table('notifications')->count());
                throw new \DomainException('rollback'); // not RuntimeException: PHPUnit assertion failures extend it
            });
        } catch (\DomainException) {
        }
        $this->assertSame(0, DB::table('notifications')->count());

        // Control: the same notification does arrive once the transaction commits.
        DB::transaction(fn () => $this->bu->notify(new PengajuanBaru($spt->load('irban'))));
        $this->assertSame(1, DB::table('notifications')->count());
    }
}
