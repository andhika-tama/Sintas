<?php

namespace App\Models;

use App\Enums\StatusItemPkpt;
use App\Models\Concerns\DicatatAktivitas;
use App\Models\Concerns\MenyegarkanCachePublik;
use Carbon\CarbonImmutable;
use Database\Factories\PkptItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['pkpt_id', 'irban_id', 'jenis_penugasan_id', 'tanggal_mulai', 'tanggal_selesai', 'keterangan', 'target_spt',
    'status', 'tanggal_mulai_disahkan', 'tanggal_selesai_disahkan', 'ditambahkan_perubahan_id', 'dihapus_perubahan_id'])]
class PkptItem extends Model
{
    /** @use HasFactory<PkptItemFactory> */
    use DicatatAktivitas, HasFactory, MenyegarkanCachePublik;

    protected $table = 'pkpt_item';

    protected $attributes = ['status' => 'aktif'];

    protected function casts(): array
    {
        return [
            'status' => StatusItemPkpt::class,
            'tanggal_mulai' => 'immutable_date',
            'tanggal_selesai' => 'immutable_date',
            'tanggal_mulai_disahkan' => 'immutable_date',
            'tanggal_selesai_disahkan' => 'immutable_date',
        ];
    }

    /** Excludes items removed by a Perubahan PKPT. Column qualified so joins keep working. */
    public function scopeAktif(Builder $query): void
    {
        $query->where('pkpt_item.status', StatusItemPkpt::Aktif->value);
    }

    /** Items without target_spt (K9, Task 6 1.1.4): the rekap counts them as one planned SPT. Shared by PKPT detail, Sahkan and Rekap. */
    public function scopeTanpaTarget(Builder $query): void
    {
        $query->whereNull('pkpt_item.target_spt');
    }

    /** True when the dates differ from the snapshot taken at pengesahan. */
    public function direvisi(): bool
    {
        return $this->tanggal_mulai_disahkan !== null && $this->tanggal_selesai_disahkan !== null
            && (! $this->tanggal_mulai_disahkan->equalTo($this->tanggal_mulai) || ! $this->tanggal_selesai_disahkan->equalTo($this->tanggal_selesai));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null "Jadwal semula" from the snapshot, only when the item was moved. */
    public function semula(): ?array
    {
        return $this->direvisi() ? [$this->tanggal_mulai_disahkan->startOfDay(), $this->tanggal_selesai_disahkan->startOfDay()] : null;
    }

    public function pkpt()
    {
        return $this->belongsTo(Pkpt::class);
    }

    public function irban()
    {
        return $this->belongsTo(Irban::class);
    }

    public function jenisPenugasan()
    {
        return $this->belongsTo(JenisPenugasan::class);
    }

    public function spt()
    {
        return $this->hasMany(Spt::class);
    }

    /** The Perubahan PKPT that added this item (null for items of the original PKPT). */
    public function ditambahkanOleh()
    {
        return $this->belongsTo(PkptPerubahan::class, 'ditambahkan_perubahan_id');
    }

    public function operasiPerubahan()
    {
        return $this->hasMany(PkptPerubahanItem::class);
    }

    /** The latest approved Perubahan Penugasan (1.2 K6): set = every SPT on this item is Tidak Sesuai PKPT. */
    public function perubahanPenugasan()
    {
        return $this->belongsTo(PerubahanPenugasan::class, 'perubahan_penugasan_id');
    }
}
