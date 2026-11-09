<?php

namespace App\Actions\Spt;

use App\Enums\KejadianSpt;
use App\Enums\StatusPengajuan;
use App\Enums\StatusPkpt;
use App\Enums\SumberSpt;
use App\Models\Aktivitas;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\Spt;
use App\Models\User;
use App\Services\Import\SptImportParser;
use App\Support\CachePublik;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Writes legacy SPT analyzed by SptImportParser (rows valid or warning) in one transaction. Imported SPT keep their old number
 * (no counter), and the notification idempotence columns are filled so neither the import nor `notifikasi:harian` notifies.
 */
class ImporSpt
{
    public function handle(User $admin, array $rows, string $berkas): int
    {
        Gate::forUser($admin)->authorize('manage-master-data');
        $rows = array_values(array_filter($rows, fn ($r) => in_array($r['status'] ?? null, ['valid', 'warning'], true)));
        $nomor = null;

        try {
            $n = DB::transaction(function () use ($admin, $rows, $berkas, &$nomor) {
                $this->kunciItem($rows);
                foreach ($rows as $r) {
                    $d = $r['data'];
                    $nomor = $d['nomor_lengkap'];
                    $this->simpan($admin, $d, $berkas);
                }

                return count($rows);
            });
        } catch (UniqueConstraintViolationException) {
            // The number was taken between preview and import; the transaction rolled everything back.
            throw ValidationException::withMessages(['impor' => "Nomor SPT {$nomor} sudah dipakai. Muat ulang pratinjau."]);
        }
        if ($n > 0) {
            Aktivitas::catatImpor($admin, 'spt', $berkas, $n);
        }

        return $n;
    }

    /**
     * Locks the PKPT of every linked item and every PKPT disahkan in those years (PKPT first, by id, like PutuskanPerubahan), then
     * re-runs the parser's matching rule (SptImportParser::itemCocok): an approved Perubahan committed since the preview may have
     * deleted or moved the item, moved its period off the SPT dates, or added a second overlapping item.
     */
    private function kunciItem(array $rows): void
    {
        $data = array_values(array_filter(array_column($rows, 'data'), fn ($d) => $d['pkpt_item_id'] !== null));
        if (! $data) {
            return;
        }
        $ids = array_values(array_unique(array_column($data, 'pkpt_item_id')));
        $tahun = array_values(array_unique(array_map(fn ($d) => (int) substr($d['tanggal_mulai'], 0, 4), $data)));
        $pkptIds = Pkpt::where(fn ($q) => $q->whereIn('id', PkptItem::whereIn('id', $ids)->select('pkpt_id'))
            ->orWhere(fn ($q) => $q->where('status', StatusPkpt::Disahkan)->whereIn('tahun', $tahun)))
            ->orderBy('id')->lockForUpdate()->pluck('id');
        $parser = app(SptImportParser::class);
        $item = $parser->itemPkpt($tahun, $pkptIds);
        foreach ($data as $d) {
            $cocok = $parser->itemCocok($item, (int) $d['irban_id'], (int) $d['jenis_penugasan_id'], $d['tanggal_mulai'], $d['tanggal_selesai']);
            if ($cocok->count() !== 1 || (int) $cocok->first()->id !== (int) $d['pkpt_item_id']) {
                throw ValidationException::withMessages(['impor' => "Item PKPT untuk SPT {$d['nomor_lengkap']} sudah berubah. Muat ulang pratinjau."]);
            }
        }
    }

    private function simpan(User $admin, array $d, string $berkas): void
    {
        $spt = new Spt(array_intersect_key($d, array_flip(['irban_id', 'klasifikasi_id', 'kode_arsip_id', 'pkpt_item_id', 'jenis_penugasan_id', 'ppj_pegawai_id',
            'periode_diperiksa', 'uraian_untuk', 'tanggal_mulai', 'tanggal_selesai'])));
        $spt->forceFill([
            'status_pengajuan' => StatusPengajuan::Diterima, 'sumber' => SumberSpt::Impor, 'dibuat_oleh' => $admin->id, 'diinput_oleh' => null,
            'diajukan_pada' => $d['tanggal_spt'].' 00:00:00', 'tahun' => $d['tahun'], 'tanggal_spt' => $d['tanggal_spt'],
            'nomor_lengkap' => $d['nomor_lengkap'], 'nomor_urut' => $d['nomor_urut'], 'nomor_counter_id' => null,
            'nomor_lhp' => $d['nomor_lhp'], 'tanggal_lhp' => $d['tanggal_lhp'],
            'ditandai_selesai_pada' => $d['selesai'] ? ($d['tanggal_lhp'] ?? $d['tanggal_selesai']).' 00:00:00' : null,
            'ditandai_selesai_oleh' => $d['selesai'] ? $admin->id : null,
            'terlambat_diberitahu_pada' => now(), 'pengingat_final_pada' => now(),
        ])->save();

        // Final-review ruling: only the Spt `created` row is logged per SPT; child rows would multiply the log ~6x on a 2000-row file.
        activity()->withoutLogs(function () use ($spt, $admin, $d, $berkas) {
            $spt->objek()->attach($d['objek_ids']);
            DB::afterCommit(fn () => CachePublik::segarkan()); // pivot: no model event
            foreach ($d['personel'] as $i => $p) {
                $spt->personel()->create(['pegawai_id' => $p['pegawai_id'], 'peran' => $p['peran'], 'urutan' => $i + 1, 'sumber' => 'tambahan', 'tim_id' => null]);
            }
            if ($d['kendala_kategori'] !== null) {
                $spt->kendala()->create(['kategori' => $d['kendala_kategori'], 'uraian' => $d['kendala_uraian'], 'diinput_oleh' => null, 'dibuat_oleh' => $admin->id]);
            }
            $spt->riwayat()->create(['kejadian' => KejadianSpt::DIIMPOR, 'nilai_baru' => ['nomor_lengkap' => $d['nomor_lengkap'], 'berkas' => $berkas], 'oleh' => $admin->id]);
        });
    }
}
