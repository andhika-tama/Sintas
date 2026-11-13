<?php

namespace App\Services\Ekspor;

use App\Exports\RegisterSheet;
use App\Models\Spt;
use App\Models\User;
use App\Services\RegisterNomor;
use App\Services\SaringSpt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Concerns\Export;

class PembangunRegister implements Pembangun
{
    public function otorisasi(User $user): void
    {
        Gate::forUser($user)->authorize('kelolaNomor', Spt::class);
    }

    public function filter(User $user, array $filter): array
    {
        $tahun = (int) ($filter['tahun'] ?? 0);

        return [
            'tahun' => $tahun >= 2000 ? $tahun : CarbonImmutable::now('Asia/Jakarta')->year,
            'cari' => (string) ($filter['cari'] ?? ''), 'status' => (string) ($filter['status'] ?? ''),
            'irban' => (string) ($filter['irban'] ?? ''), 'bulan' => (string) ($filter['bulan'] ?? ''),
        ] + app(SaringSpt::class)->dari($filter);
    }

    public function jumlah(User $user, array $filter): int
    {
        return app(RegisterNomor::class)->query($filter)->count();
    }

    public function namaFile(User $user, array $filter): string
    {
        return "register-nomor-spt-{$filter['tahun']}";
    }

    /** Text columns: Nomor SPT (A) and Nomor SPPD (C) are free text; RegisterSheet binds with AmanFormula so a leading = + - @ stays text. Anggota is newline-joined. */
    public function export(User $user, array $filter): Export
    {
        $r = app(RegisterNomor::class);

        return new RegisterSheet($filter['tahun'], $r->ringkasanFilter($filter), $r->semua($filter, "\n"), now('Asia/Jakarta')->translatedFormat('d F Y H:i').' WIB');
    }

    public function html(User $user, array $filter): string
    {
        return app(RegisterNomor::class)->html($filter);
    }

    public function halaman(User $user): string
    {
        return route('bagian-umum.register', absolute: false);
    }
}
