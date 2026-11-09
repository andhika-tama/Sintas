<?php

use App\Actions\Pkpt\Perubahan\BuangPerubahan;
use App\Http\Controllers\Auth\ForcedPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\EksporController;
use App\Http\Controllers\PerubahanSkController;
use App\Http\Controllers\Publik\BerandaController;
use App\Http\Controllers\SptDokumenController;
use App\Http\Controllers\VerifikasiEmailController;
use App\Livewire\Admin\Dasbor as AdminDasbor;
use App\Livewire\Admin\Impor;
use App\Livewire\Admin\Irban;
use App\Livewire\Admin\IrbanTahunan;
use App\Livewire\Admin\JenisPenugasan;
use App\Livewire\Admin\Klasifikasi;
use App\Livewire\Admin\KodeArsip;
use App\Livewire\Admin\Kontak;
use App\Livewire\Admin\LatarMasuk;
use App\Livewire\Admin\Objek;
use App\Livewire\Admin\Pegawai;
use App\Livewire\Admin\PegawaiImpor;
use App\Livewire\Admin\Pengaturan;
use App\Livewire\Admin\Pengguna;
use App\Livewire\Admin\PerubahanPkpt as AdminPerubahanPkpt;
use App\Livewire\Admin\Pkpt;
use App\Livewire\Admin\PkptDetail;
use App\Livewire\Admin\PkptImpor;
use App\Livewire\Admin\Slide;
use App\Livewire\Admin\Tim;
use App\Livewire\Admin\WilayahIrban;
use App\Livewire\BagianUmum\Dasbor as BagianUmumDasbor;
use App\Livewire\BagianUmum\Pengajuan as BagianUmumPengajuan;
use App\Livewire\BagianUmum\Register as BagianUmumRegister;
use App\Livewire\Irban\Dasbor as IrbanDasbor;
use App\Livewire\Irban\Pkpt as IrbanPkpt;
use App\Livewire\Irban\SptForm;
use App\Livewire\Irban\SptSaya;
use App\Livewire\LogAktivitas;
use App\Livewire\Notifikasi\Daftar as NotifikasiDaftar;
use App\Livewire\Pimpinan\Dasbor as PimpinanDasbor;
use App\Livewire\Pimpinan\Monitoring;
use App\Livewire\Pimpinan\PerubahanPenugasan as PimpinanPerubahanPenugasan;
use App\Livewire\Pimpinan\PerubahanPkpt as PimpinanPerubahanPkpt;
use App\Livewire\Pimpinan\PerubahanPkptDetail as PimpinanPerubahanPkptDetail;
use App\Livewire\Profil;
use App\Livewire\Publik\Jadwal as PublikJadwal;
use App\Livewire\Publik\Realisasi as PublikRealisasi;
use App\Livewire\Rekap;
use App\Livewire\Spt\Detail as SptDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:publik')->group(function () {
    Route::get('/', BerandaController::class)->name('beranda');
    Route::get('/jadwal', PublikJadwal::class)->name('publik.jadwal');
    Route::get('/realisasi', PublikRealisasi::class)->name('publik.realisasi');
});

Route::middleware('guest')->group(function () {
    Route::get('/masuk', [LoginController::class, 'create'])->name('login');
    Route::post('/masuk', [LoginController::class, 'store']);
    Route::get('/lupa-kata-sandi', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/lupa-kata-sandi', [PasswordResetLinkController::class, 'store'])->name('password.email')->middleware('throttle:6,1');
    Route::get('/atur-ulang-kata-sandi/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/atur-ulang-kata-sandi', [NewPasswordController::class, 'store'])->name('password.update')->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/keluar', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/perbarui-kata-sandi', [ForcedPasswordController::class, 'edit'])->name('password.force.edit');
    Route::put('/perbarui-kata-sandi', [ForcedPasswordController::class, 'update'])->name('password.force.update');

    Route::get('/profil', Profil::class)->name('profil');
    Route::get('/profil/email/verifikasi/{user}/{hash}', VerifikasiEmailController::class)->name('profil.email.verifikasi')->middleware('signed');
    Route::get('/notifikasi', NotifikasiDaftar::class)->name('notifikasi.index');
    Route::get('/ekspor/{ekspor}', EksporController::class)->name('ekspor.unduh');
    Route::get('/spt/{spt}', SptDetail::class)->name('spt.show');
    Route::get('/spt/{spt}/dokumen/{dokumen}', SptDokumenController::class)->name('spt.dokumen');
    Route::get('/perubahan-pkpt/{perubahan}/sk', PerubahanSkController::class)->name('perubahan-pkpt.sk');
    Route::get('/dashboard', fn () => redirect()->route(auth()->user()->role->dashboardRoute()))->name('dashboard');

    Route::get('/admin', AdminDasbor::class)->name('admin.dashboard')->middleware('role:admin');
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/pengaturan', Pengaturan::class)->name('admin.pengaturan');
        Route::get('/klasifikasi', Klasifikasi::class)->name('admin.klasifikasi');
        Route::get('/pegawai', Pegawai::class)->name('admin.pegawai');
        Route::get('/pengguna', Pengguna::class)->name('admin.pengguna');
        Route::get('/pegawai/impor', PegawaiImpor::class)->name('admin.pegawai.impor');
        Route::get('/irban', Irban::class)->name('admin.irban');
        Route::get('/irban-tahunan', IrbanTahunan::class)->name('admin.irban-tahunan');
        Route::get('/tim', Tim::class)->name('admin.tim');
        Route::get('/objek', Objek::class)->name('admin.objek');
        Route::get('/wilayah-irban', WilayahIrban::class)->name('admin.wilayah-irban');
        Route::get('/jenis-penugasan', JenisPenugasan::class)->name('admin.jenis-penugasan');
        Route::get('/kode-arsip', KodeArsip::class)->name('admin.kode-arsip');
        Route::get('/pkpt', Pkpt::class)->name('admin.pkpt');
        Route::get('/pkpt/{pkpt}', PkptDetail::class)->name('admin.pkpt.show');
        Route::get('/pkpt/{pkpt}/impor', PkptImpor::class)->name('admin.pkpt.impor');
        Route::get('/pkpt/{pkpt}/perubahan/{perubahan}', AdminPerubahanPkpt::class)->name('admin.pkpt.perubahan')
            ->missing(fn (Request $r) => to_route('admin.pkpt.show', ['pkpt' => $r->route('pkpt'), 'tab' => 'perubahan'])->with('toast', ['type' => 'error', 'message' => BuangPerubahan::DIBUANG]));
        Route::get('/slide', Slide::class)->name('admin.slide');
        Route::get('/kontak', Kontak::class)->name('admin.kontak');
        Route::get('/latar-masuk', LatarMasuk::class)->name('admin.latar-masuk');
        Route::get('/impor', Impor::class)->name('admin.impor');
        Route::get('/log', LogAktivitas::class)->name('admin.log');
    });
    Route::get('/pimpinan', PimpinanDasbor::class)->name('pimpinan.dashboard')->middleware('role:pimpinan');
    Route::get('/pimpinan/monitoring', Monitoring::class)->name('pimpinan.monitoring')->middleware('role:pimpinan');
    Route::get('/pimpinan/rekap', Rekap::class)->name('pimpinan.rekap')->middleware('role:pimpinan');
    Route::get('/pimpinan/log', LogAktivitas::class)->name('pimpinan.log')->middleware('role:pimpinan');
    Route::get('/pimpinan/perubahan-pkpt', PimpinanPerubahanPkpt::class)->name('pimpinan.perubahan-pkpt')->middleware('role:pimpinan');
    Route::get('/pimpinan/perubahan-pkpt/{perubahan}', PimpinanPerubahanPkptDetail::class)->name('pimpinan.perubahan-pkpt.show')->middleware('role:pimpinan')
        ->missing(fn () => to_route('pimpinan.perubahan-pkpt')->with('toast', ['type' => 'error', 'message' => BuangPerubahan::DIBUANG]));
    Route::get('/pimpinan/perubahan-penugasan', PimpinanPerubahanPenugasan::class)->name('pimpinan.perubahan-penugasan')->middleware('role:pimpinan');
    // Legacy bell links of the removed schedule revision page.
    Route::redirect('/pimpinan/revisi-jadwal', '/pimpinan/perubahan-pkpt')->name('pimpinan.revisi-jadwal')->middleware('role:pimpinan');
    Route::get('/irban', IrbanDasbor::class)->name('irban.dashboard')->middleware('role:irban');
    Route::middleware('role:irban')->prefix('irban')->group(function () {
        Route::get('/pkpt', IrbanPkpt::class)->name('irban.pkpt');
        Route::get('/rekap', Rekap::class)->name('irban.rekap');
        Route::get('/spt', SptSaya::class)->name('irban.spt');
        Route::get('/spt/ajukan', SptForm::class)->name('irban.spt.ajukan');
        Route::get('/spt/{spt}/ubah', SptForm::class)->name('irban.spt.ubah');
    });
    Route::get('/bagian-umum', BagianUmumDasbor::class)->name('bagian-umum.dashboard')->middleware('role:bagian_umum');
    Route::get('/bagian-umum/pengajuan', BagianUmumPengajuan::class)->name('bagian-umum.pengajuan')->middleware('role:bagian_umum');
    Route::get('/bagian-umum/register', BagianUmumRegister::class)->name('bagian-umum.register')->middleware('role:bagian_umum');
});

// Unmatched URLs run inside the web group (session), so the 404 page can send a logged-in user to the dashboard. GET/HEAD only (Laravel's fallback).
Route::fallback(fn () => abort(404));
