<?php

namespace App\Support;

use App\Enums\Role;
use Illuminate\Support\Facades\Route;

/** Sidebar navigation per role (design/dashboard/admin.md "Navigation groups"); one source for the sidebar and the admin quick links. */
class Navigation
{
    /** @return list<array{label: ?string, items: list<array{label: string, route: string, icon: string, aktif: list<string>}>}> `aktif` = other route names that also mark the item current. */
    public static function groups(Role $role): array
    {
        $item = fn (string $label, string $route, string $icon, array $aktif = []) => compact('label', 'route', 'icon', 'aktif');
        $dasbor = [['label' => null, 'items' => [$item('Dasbor', $role->dashboardRoute(), 'home')]]];

        $groups = match ($role) {
            Role::Irban => [...$dasbor, ['label' => null, 'items' => [
                $item('SPT Saya', 'irban.spt', 'document-text', ['spt.show', 'irban.spt.ubah']),
                $item('Ajukan Nomor SPT', 'irban.spt.ajukan', 'plus-circle'),
                $item('Jadwal PKPT', 'irban.pkpt', 'calendar-days'),
                $item('Rekap Saya', 'irban.rekap', 'chart-bar'),
            ]]],
            Role::BagianUmum => [...$dasbor, ['label' => null, 'items' => [
                $item('Kotak Masuk Pengajuan', 'bagian-umum.pengajuan', 'inbox'),
                $item('Register Nomor SPT', 'bagian-umum.register', 'book-open', ['spt.show']),
            ]]],
            Role::Pimpinan => [...$dasbor, ['label' => null, 'items' => [
                $item('Monitoring SPT', 'pimpinan.monitoring', 'table-cells'),
                $item('Rekap Triwulanan', 'pimpinan.rekap', 'chart-bar'),
                $item('Perubahan PKPT', 'pimpinan.perubahan-pkpt', 'arrow-path', ['pimpinan.perubahan-pkpt.show']),
                $item('Log Aktivitas', 'pimpinan.log', 'clipboard-document-list'),
            ]]],
            Role::Admin => [...$dasbor,
                ['label' => null, 'items' => [$item('Pengguna', 'admin.pengguna', 'users')]],
                ['label' => 'Master Data', 'items' => [
                    $item('Pegawai', 'admin.pegawai', 'identification'),
                    $item('Irban', 'admin.irban', 'building-office-2'),
                    $item('Komposisi Irban Tahunan', 'admin.irban-tahunan', 'user-group'),
                    $item('Tim', 'admin.tim', 'users'),
                    $item('Objek Pemeriksaan', 'admin.objek', 'building-library'),
                    $item('Wilayah Irban', 'admin.wilayah-irban', 'map'),
                    $item('Jenis Penugasan', 'admin.jenis-penugasan', 'swatch'),
                    $item('Kode Arsip', 'admin.kode-arsip', 'archive-box'),
                    $item('Klasifikasi & Aturan', 'admin.klasifikasi', 'tag'),
                ]],
                ['label' => null, 'items' => [$item('PKPT', 'admin.pkpt', 'calendar-days')]],
                ['label' => 'Konten Beranda', 'items' => [
                    $item('Slide Beranda', 'admin.slide', 'photo'),
                    $item('Kontak & Footer', 'admin.kontak', 'phone'),
                    $item('Latar Halaman Masuk', 'admin.latar-masuk', 'computer-desktop'),
                ]],
                ['label' => null, 'items' => [
                    $item('Pengaturan Sistem', 'admin.pengaturan', 'cog-6-tooth'),
                    $item('Impor Excel', 'admin.impor', 'arrow-up-tray'),
                    $item('Log Aktivitas', 'admin.log', 'clipboard-document-list'),
                ]],
            ],
            default => $dasbor,
        };

        $visible = [];
        foreach ($groups as $group) {
            $group['items'] = array_values(array_filter($group['items'], fn ($i) => Route::has($i['route'])));
            if ($group['items']) {
                $visible[] = $group;
            }
        }

        return $visible;
    }

    /**
     * JS-compatible path regexes for an item with `aktif` routes: the persisted sidebar re-checks them after wire:navigate, since wire:current only matches URL prefixes.
     *
     * @return list<string>
     */
    public static function pola(array $item, bool $exact): array
    {
        $path = fn (string $name) => str_replace('__P__', '[^/]+', preg_quote(
            rtrim(parse_url(url(preg_replace('/\{[^}]+\}/', '__P__', Route::getRoutes()->getByName($name)->uri())), PHP_URL_PATH) ?? '', '/'), '#'));

        return [$exact ? '^'.$path($item['route']).'$' : '^'.$path($item['route']).'(/|$)', ...array_map(fn ($r) => '^'.$path($r).'$', $item['aktif'])];
    }

    /** Flat list of every visible item except the role's own dashboard. */
    public static function links(Role $role): array
    {
        return array_values(array_filter(
            array_merge(...array_column(self::groups($role), 'items')),
            fn ($i) => $i['route'] !== $role->dashboardRoute(),
        ));
    }
}
