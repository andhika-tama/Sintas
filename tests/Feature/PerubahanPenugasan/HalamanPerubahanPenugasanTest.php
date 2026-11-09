<?php

namespace Tests\Feature\PerubahanPenugasan;

use App\Actions\PerubahanPenugasan\AjukanPerubahanPenugasan;
use App\Actions\PerubahanPenugasan\PutuskanPerubahanPenugasan;
use App\Enums\Role;
use App\Enums\StatusItemPkpt;
use App\Enums\StatusPerubahanPenugasan;
use App\Livewire\Irban\Pkpt as HalamanIrban;
use App\Livewire\Irban\SptForm;
use App\Livewire\Pimpinan\PerubahanPenugasan as HalamanPimpinan;
use App\Models\Irban;
use App\Models\IrbanTahunan;
use App\Models\JenisPenugasan;
use App\Models\Pegawai;
use App\Models\PerubahanPenugasan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use App\Notifications\PerubahanPenugasanDiajukan;
use App\Notifications\PerubahanPenugasanDiputuskan;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** 1.2 Task 7: Perubahan Penugasan screens (Irban PKPT page, Pimpinan list), SPT form hint and SPT detail reason. */
class HalamanPerubahanPenugasanTest extends TestCase
{
    use RefreshDatabase;

    private User $pimpinan;

    private User $irban;

    private Irban $irban1;

    private Irban $irban2;

    private Pegawai $penginput;

    private PkptItem $a;

    private PkptItem $milikLain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 3, 25));
        Notification::fake();
        $this->pimpinan = User::factory()->pimpinan()->create();
        $this->irban1 = Irban::factory()->create(['nama' => 'Irban I']);
        $this->irban2 = Irban::factory()->create(['nama' => 'Irban II']);
        $this->irban = User::factory()->irban()->create(['irban_id' => $this->irban1->id]);
        $this->penginput = Pegawai::factory()->create(['nama_bergelar' => 'Dewi Penginput']);
        IrbanTahunan::factory()->create(['irban_id' => $this->irban1->id, 'tahun' => 2026, 'ppj_pegawai_id' => $this->penginput->id]);
        $pkpt = Pkpt::factory()->disahkan()->create(['tahun' => 2026]);
        $jenis = JenisPenugasan::factory()->create(['nama' => 'Reviu LKjIP']);
        $item = fn (Irban $i, string $mulai, string $selesai) => PkptItem::factory()->disahkanDari()->create([
            'pkpt_id' => $pkpt->id, 'irban_id' => $i->id, 'jenis_penugasan_id' => $jenis->id, 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai]);
        $this->a = $item($this->irban1, '2026-03-02', '2026-03-20');
        $this->milikLain = $item($this->irban2, '2026-08-03', '2026-08-14');
    }

    private function ajukan(?PkptItem $item = null, array $data = []): PerubahanPenugasan
    {
        return app(AjukanPerubahanPenugasan::class)->handle($this->irban, $item ?? $this->a, $data + [
            'tanggal_mulai' => '2026-04-06', 'tanggal_selesai' => '2026-04-24', 'alasan' => 'OPD sedang diaudit BPK.', 'diinput_oleh' => $this->penginput->id]);
    }

    private function pimpinanPage()
    {
        return Livewire::actingAs($this->pimpinan)->test(HalamanPimpinan::class);
    }

    public function test_route_matches_notification_url_and_menu(): void
    {
        $this->assertSame(PerubahanPenugasanDiajukan::URL, route('pimpinan.perubahan-penugasan', absolute: false));
        $this->assertSame('/irban/pkpt?tahun=2026&tampilan=perubahan',
            (new PerubahanPenugasanDiputuskan($this->ajukan()))->toArray($this->irban)['url']);
        $this->assertContains('Perubahan Penugasan', array_column(Navigation::groups(Role::Pimpinan)[1]['items'], 'label'));
        $this->actingAs($this->pimpinan)->get('/pimpinan/perubahan-penugasan')->assertOk()->assertSee('Perubahan Penugasan');
        foreach ([$this->irban, User::factory()->admin()->create(), User::factory()->bagianUmum()->create()] as $u) {
            $this->actingAs($u)->get('/pimpinan/perubahan-penugasan')->assertForbidden();
            Livewire::actingAs($u)->test(HalamanPimpinan::class)->assertForbidden();
        }
    }

    public function test_pimpinan_list_defaults_to_diajukan_with_columns(): void
    {
        $diajukan = $this->ajukan();
        app(PutuskanPerubahanPenugasan::class)->handle($this->pimpinan, $diajukan, false, 'Jadwal OPD tidak berubah.');
        $baru = $this->ajukan($this->a, ['alasan' => 'Tim sedang ditugaskan BPK.']);

        $this->pimpinanPage()->assertSet('status', 'diajukan')
            ->assertSeeInOrder(['Irban', 'Jenis Penugasan', 'Jadwal PKPT', 'Jadwal Baru', 'Alasan', 'Diajukan', 'Status'])
            ->assertSee('Irban I')->assertSee('Reviu LKjIP')->assertSee('2–20 Maret 2026')->assertSee('6–24 April 2026')
            ->assertSee('Tim sedang ditugaskan BPK.')->assertDontSee('Jadwal OPD tidak berubah.')
            ->assertSee('wire:click="bukaSetujui('.$baru->id.')"', false)->assertSee('wire:click="bukaTolak('.$baru->id.')"', false)
            ->set('status', 'ditolak')->assertSee('Jadwal OPD tidak berubah.')->assertDontSee('Tim sedang ditugaskan BPK.')
            ->assertDontSee('wire:click="bukaSetujui(', false)
            ->set('status', 'bogus')->assertSet('status', 'diajukan');
    }

    public function test_setujui_confirms_then_marks_item(): void
    {
        $p = $this->ajukan();

        $this->pimpinanPage()->call('bukaSetujui', $p->id)->assertSet('setujuiOpen', true)->assertSet('pilihId', $p->id)
            ->assertSee('Setujui Perubahan Penugasan?')
            ->call('setujui')->assertSet('setujuiOpen', false)
            ->assertDispatched('toast', type: 'success', message: 'Perubahan Penugasan disetujui.')
            ->assertDispatched('fokus-ke', id: 'judul-perubahan-penugasan');

        $this->assertSame(StatusPerubahanPenugasan::Disetujui, $p->fresh()->status);
        $this->assertSame($p->id, $this->a->fresh()->perubahan_penugasan_id);
    }

    public function test_tolak_requires_catatan(): void
    {
        $p = $this->ajukan();

        $h = $this->pimpinanPage()->call('bukaTolak', $p->id)->assertSet('tolakOpen', true)->assertSee('Catatan penolakan')
            ->call('tolak')->assertHasErrors('catatan')->assertSet('tolakOpen', true)->assertDispatched('fokus-ke', id: 'catatan-tolak');
        $this->assertSame(StatusPerubahanPenugasan::Diajukan, $p->fresh()->status);

        $h->set('catatan', 'Jadwal OPD tetap.')->call('tolak')->assertHasNoErrors()->assertSet('tolakOpen', false)->assertSet('catatan', '')
            ->assertDispatched('toast', type: 'success', message: 'Perubahan Penugasan ditolak.');
        $this->assertSame(['ditolak', 'Jadwal OPD tetap.'], [$p->fresh()->status->value, $p->fresh()->catatan_putusan]);
    }

    public function test_stale_decision_is_a_toast_and_focuses_h1(): void
    {
        $p = $this->ajukan();
        $h = $this->pimpinanPage()->call('bukaSetujui', $p->id);
        app(PutuskanPerubahanPenugasan::class)->handle($this->pimpinan, $p, true, null);

        $h->call('setujui')->assertStatus(200)->assertSet('setujuiOpen', false)
            ->assertDispatched('toast', type: 'error', message: PutuskanPerubahanPenugasan::SUDAH_DIPUTUSKAN)
            ->assertDispatched('fokus-ke', id: 'judul-perubahan-penugasan');

        // Opening an already decided or vanished request is a toast too, not a modal.
        $this->pimpinanPage()->call('bukaTolak', $p->id)->assertSet('tolakOpen', false)
            ->assertDispatched('toast', type: 'error', message: PutuskanPerubahanPenugasan::SUDAH_DIPUTUSKAN);
        $this->pimpinanPage()->call('bukaTolak', 999999)->assertStatus(200)->assertSet('tolakOpen', false)
            ->assertDispatched('toast', type: 'error', message: PutuskanPerubahanPenugasan::ITEM_BASI);

        // The item removed after the modal opened: the approval refuses with a toast.
        $q = $this->ajukan($this->a, ['tanggal_mulai' => '2026-05-04', 'tanggal_selesai' => '2026-05-08']);
        $h = $this->pimpinanPage()->call('bukaSetujui', $q->id);
        $this->a->update(['status' => StatusItemPkpt::Dihapus]);
        $h->call('setujui')->assertDispatched('toast', type: 'error', message: PutuskanPerubahanPenugasan::ITEM_BASI)
            ->assertDispatched('fokus-ke', id: 'judul-perubahan-penugasan');
    }

    public function test_irban_page_trigger_modal_and_submit(): void
    {
        foreach (['kalender', 'tabel'] as $tampilan) {
            $this->actingAs($this->irban)->get('/irban/pkpt?tampilan='.$tampilan)->assertOk()
                ->assertSee('Ajukan Perubahan Penugasan')->assertSee('wire:click="bukaPerubahan('.$this->a->id.')"', false)
                ->assertDontSee('Usulkan')->assertDontSee('Usulan')->assertDontSee('Perubahan PKPT');
        }

        Livewire::actingAs($this->irban)->test(HalamanIrban::class, ['tampilan' => 'tabel'])
            ->call('bukaPerubahan', $this->a->id)->assertSet('perubahanOpen', true)->assertSet('perubahanItemId', $this->a->id)
            ->assertSet('tanggal_mulai', '2026-03-02')->assertSet('tanggal_selesai', '2026-03-20')
            ->assertSee('Tanggal mulai baru')->assertSee('Tanggal selesai baru')->assertSee('Alasan')->assertSee('0/1000 karakter')
            ->assertSee('Diinput oleh')->assertDontSee('Usulan')->assertDontSee('Perubahan PKPT')
            ->set('tanggal_mulai', '2026-04-06')->set('tanggal_selesai', '2026-04-24')->set('alasan', '')->call('kirimPerubahan')
            ->assertHasErrors('alasan')->assertSet('perubahanOpen', true)
            ->set('alasan', 'OPD sedang diaudit BPK.')->set('diinput_oleh', $this->penginput->id)->call('kirimPerubahan')
            ->assertHasNoErrors()->assertSet('perubahanOpen', false)->assertDispatched('perubahan-fokus', id: $this->a->id)
            ->assertDispatched('toast', type: 'success', message: 'Perubahan Penugasan terkirim ke Pimpinan.');

        $p = PerubahanPenugasan::sole();
        $this->assertSame(['2026-04-06', '2026-04-24', $this->penginput->id], [$p->tanggal_mulai->toDateString(), $p->tanggal_selesai->toDateString(), $p->diinput_oleh]);
        // K12: the calendar keeps the original PKPT dates.
        $this->assertSame('2026-03-02', $this->a->fresh()->tanggal_mulai->toDateString());
    }

    public function test_irban_scope_foreign_is_404_stale_is_toast_and_non_irban_403(): void
    {
        Livewire::actingAs($this->irban)->test(HalamanIrban::class)->call('bukaPerubahan', $this->milikLain->id)->assertNotFound();
        Livewire::actingAs($this->irban)->test(HalamanIrban::class)->call('bukaPerubahan', 999999)->assertNotFound();

        $h = Livewire::actingAs($this->irban)->test(HalamanIrban::class, ['tampilan' => 'tabel'])->call('bukaPerubahan', $this->a->id)
            ->set('tanggal_mulai', '2026-04-06')->set('tanggal_selesai', '2026-04-24')->set('alasan', 'Alasan.')->set('diinput_oleh', $this->penginput->id);
        $this->a->update(['status' => StatusItemPkpt::Dihapus]);
        $h->call('kirimPerubahan')->assertStatus(200)->assertSet('perubahanOpen', false)
            ->assertDispatched('toast', type: 'error', message: PutuskanPerubahanPenugasan::ITEM_BASI)->assertDispatched('perubahan-fokus', id: null);
        Livewire::actingAs($this->irban)->test(HalamanIrban::class)->call('bukaPerubahan', $this->a->id)->assertStatus(200)
            ->assertSet('perubahanOpen', false)->assertDispatched('toast', type: 'error', message: PutuskanPerubahanPenugasan::ITEM_BASI);
        $this->assertSame(0, PerubahanPenugasan::count());

        Livewire::actingAs($this->pimpinan)->test(HalamanIrban::class)->assertForbidden();
    }

    public function test_second_submission_while_pending_is_a_toast(): void
    {
        $this->ajukan();
        Livewire::actingAs($this->irban)->test(HalamanIrban::class)->call('bukaPerubahan', $this->a->id)
            ->set('tanggal_mulai', '2026-05-04')->set('tanggal_selesai', '2026-05-08')->set('alasan', 'Lagi.')->set('diinput_oleh', $this->penginput->id)
            ->call('kirimPerubahan')->assertSet('perubahanOpen', false)
            ->assertDispatched('toast', type: 'error', message: AjukanPerubahanPenugasan::MASIH_DIAJUKAN);
    }

    public function test_perubahan_penugasan_saya_lists_own_with_status_and_catatan(): void
    {
        $p = $this->ajukan();
        app(PutuskanPerubahanPenugasan::class)->handle($this->pimpinan, $p, false, 'Jadwal OPD tidak berubah.');
        $this->ajukan($this->a, ['alasan' => 'Tim sedang ditugaskan BPK.']);
        PerubahanPenugasan::factory()->create(['pkpt_item_id' => $this->milikLain->id, 'irban_id' => $this->irban2->id, 'alasan' => 'Alasan rahasia irban lain']);

        $this->actingAs($this->irban)->get('/irban/pkpt?tampilan=perubahan')->assertOk()
            ->assertSee('Perubahan Penugasan Saya')->assertSee('Tim sedang ditugaskan BPK.')->assertSee('OPD sedang diaudit BPK.')
            ->assertSee('Diajukan')->assertSee('Ditolak')->assertSee('Catatan penolakan')->assertSee('Jadwal OPD tidak berubah.')
            ->assertSee('6–24 April 2026')->assertDontSee('Alasan rahasia irban lain')
            ->assertDontSee('Usulan')->assertDontSee('Perubahan PKPT');
    }

    /** 1.2 Task 8: old UsulanPerubahanDiputuskan notifications link to `tampilan=usulan`; it lands on the Perubahan Penugasan list. */
    public function test_old_usulan_link_opens_perubahan_penugasan_view(): void
    {
        $this->ajukan();

        $this->actingAs($this->irban)->get('/irban/pkpt?tahun=2026&tampilan=usulan')->assertOk()
            ->assertSee('Perubahan Penugasan Saya')->assertSee('OPD sedang diaudit BPK.');
        Livewire::withQueryParams(['tampilan' => 'usulan'])->actingAs($this->irban)->test(HalamanIrban::class)->assertSet('tampilan', 'perubahan');
    }

    public function test_spt_form_hint_and_prefill_for_marked_item(): void
    {
        $p = $this->ajukan();
        app(PutuskanPerubahanPenugasan::class)->handle($this->pimpinan, $p, true, null);
        $pesan = 'Perubahan Penugasan disetujui: 6–24 April 2026. SPT ini akan ditandai Tidak Sesuai PKPT dan wajib diisi Kendala.';

        Livewire::actingAs($this->irban)->test(SptForm::class)->set('pkpt_item_id', $this->a->id)
            ->assertSee($pesan)->assertSet('tanggal_mulai', '2026-04-06')->assertSet('tanggal_selesai', '2026-04-24');

        // Dates already filled are kept.
        Livewire::actingAs($this->irban)->test(SptForm::class)->set('tanggal_mulai', '2026-03-03')->set('tanggal_selesai', '2026-03-05')
            ->set('pkpt_item_id', $this->a->id)->assertSee($pesan)->assertSet('tanggal_mulai', '2026-03-03')->assertSet('tanggal_selesai', '2026-03-05');
    }

    public function test_spt_detail_explains_tidak_sesuai_for_marked_item(): void
    {
        $p = $this->ajukan();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 3, 27));
        app(PutuskanPerubahanPenugasan::class)->handle($this->pimpinan, $p, true, null);
        $spt = Spt::factory()->diterima()->create(['irban_id' => $this->irban1->id, 'pkpt_item_id' => $this->a->id,
            'jenis_penugasan_id' => $this->a->jenis_penugasan_id, 'tanggal_mulai' => '2026-03-03', 'tanggal_selesai' => '2026-03-05']);

        $this->actingAs($this->pimpinan)->get('/spt/'.$spt->id)->assertOk()
            ->assertSee('Jadwal item ini diubah lewat Perubahan Penugasan (disetujui 27 Maret 2026).');

        $this->a->forceFill(['perubahan_penugasan_id' => null])->save();
        $this->actingAs($this->pimpinan)->get('/spt/'.$spt->id)->assertOk()->assertDontSee('diubah lewat Perubahan Penugasan');
    }
}
