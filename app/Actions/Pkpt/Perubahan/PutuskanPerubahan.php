<?php

namespace App\Actions\Pkpt\Perubahan;

use App\Actions\Pkpt\Perubahan\Concerns\MengelolaPerubahan;
use App\Actions\Pkpt\SavePkptItem;
use App\Enums\AksiPerubahan;
use App\Enums\StatusItemPkpt;
use App\Enums\StatusPerubahan;
use App\Enums\StatusUsulan;
use App\Models\Pkpt;
use App\Models\PkptItem;
use App\Models\PkptPerubahan;
use App\Models\PkptUsulan;
use App\Models\User;
use App\Notifications\PerubahanPkptDiputuskan;
use App\Notifications\UsulanPerubahanDiputuskan;
use App\Services\PenerimaNotifikasi;
use App\Services\PerubahanPkpt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Pimpinan approves or rejects a submitted package. Approval re-validates every operation under the PKPT row lock and applies
 * them all or none; on failure the package stays `diajukan` and the reasons come back under `perubahan`.
 */
class PutuskanPerubahan
{
    use MengelolaPerubahan;

    public const SUDAH_DIPUTUSKAN = 'Perubahan ini sudah diputuskan.';

    public function __construct(private PerubahanPkpt $perubahan, private PenerimaNotifikasi $penerima) {}

    public function handle(User $user, PkptPerubahan $p, bool $setujui, ?string $komentar): PkptPerubahan
    {
        Gate::forUser($user)->authorize('putuskan', $p);
        $diterapkan = [];
        $p = DB::transaction(function () use ($user, $p, $setujui, $komentar, &$diterapkan) {
            // PKPT row first: two approvals of one PKPT queue here, so nomor_urut and the re-validation never race.
            Pkpt::whereKey($p->pkpt_id)->lockForUpdate()->first();
            $p = PkptPerubahan::whereKey($p->id)->lockForUpdate()->first();
            if ($p?->status !== StatusPerubahan::Diajukan) {
                throw ValidationException::withMessages(['perubahan' => self::SUDAH_DIPUTUSKAN]);
            }
            // After the status check, so a stale tab learns the package was already decided before any komentar error.
            $komentar = $this->komentar($komentar, $setujui);
            $putusan = ['diputuskan_oleh' => $user->id, 'diputuskan_pada' => now(), 'komentar_putusan' => $komentar];

            if (! $setujui) {
                $p->forceFill(['status' => StatusPerubahan::Ditolak] + $putusan)->save();
                $this->catat($p, 'ditolak', $user, $komentar);

                return $p;
            }

            if ($alasan = $this->perubahan->cek($p)) {
                throw ValidationException::withMessages(['perubahan' => $alasan]);
            }
            $this->terapkan($p);
            $nomor = (int) PkptPerubahan::where('pkpt_id', $p->pkpt_id)->max('nomor_urut') + 1;
            $p->forceFill(['status' => StatusPerubahan::Disetujui, 'nomor_urut' => $nomor] + $putusan)->save();
            $tampung = PkptUsulan::where('perubahan_id', $p->id)->where('status', StatusUsulan::Ditampung->value);
            $diterapkan = $tampung->pluck('id')->all();
            $tampung->update(['status' => StatusUsulan::Diterapkan->value]);
            $this->catat($p, 'disetujui', $user, $komentar);

            return $p;
        }, attempts: 3);

        $penerima = $setujui ? $this->penerima->perubahanDisetujui($p, $user) : $this->penerima->pembuatPerubahan($p);
        Notification::send($penerima, new PerubahanPkptDiputuskan($p, $setujui));
        // One notification per applied usulan (irban accounts + "Diinput oleh" pegawai email).
        PkptUsulan::with(['pkpt', 'perubahan', 'item.jenisPenugasan', 'jenisPenugasan', 'diinputOleh'])->whereKey($diterapkan)->get()
            ->each(fn (PkptUsulan $u) => UsulanPerubahanDiputuskan::kirim($u));

        return $p;
    }

    private function komentar(?string $komentar, bool $setujui): ?string
    {
        $komentar = trim((string) $komentar);
        if (! $setujui && $komentar === '') {
            throw ValidationException::withMessages(['komentar' => 'Alasan penolakan wajib diisi.']);
        }
        if (mb_strlen($komentar) > 1000) {
            throw ValidationException::withMessages(['komentar' => 'Komentar maksimal 1000 karakter.']);
        }

        return $komentar === '' ? null : $komentar;
    }

    /** Applies the operations in order; data_lama is recorded now, not when the draft was written. */
    private function terapkan(PkptPerubahan $p): void
    {
        $pkpt = $p->pkpt()->first();
        foreach ($p->operasi()->get() as $op) {
            if ($op->aksi === AksiPerubahan::Tambah) {
                $attrs = SavePkptItem::validated($pkpt, $op->data_baru);
                // Snapshot = own dates: "jadwal semula" of an added item is the date it was added with.
                $item = PkptItem::create(['pkpt_id' => $pkpt->id, 'ditambahkan_perubahan_id' => $p->id,
                    'tanggal_mulai_disahkan' => $attrs['tanggal_mulai'], 'tanggal_selesai_disahkan' => $attrs['tanggal_selesai']] + $attrs);
                $op->forceFill(['pkpt_item_id' => $item->id])->save();

                continue;
            }
            $item = PkptItem::findOrFail($op->pkpt_item_id);
            $op->forceFill(['data_lama' => PerubahanPkpt::data($item)])->save();
            $item->fill($op->aksi === AksiPerubahan::Ubah
                ? SavePkptItem::validated($pkpt, $op->data_baru)
                : ['status' => StatusItemPkpt::Dihapus, 'dihapus_perubahan_id' => $p->id]);
            if ($item->isDirty(['tanggal_mulai', 'tanggal_selesai'])) {
                // 1.2 K10: an official date change replaces the Perubahan Penugasan, so its SPT can be sesuai again.
                $item->perubahan_penugasan_id = null;
            }
            $item->save();
        }
    }
}
