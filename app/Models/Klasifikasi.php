<?php

namespace App\Models;

use App\Enums\SyaratSelesai;
use App\Models\Concerns\DicatatAktivitas;
use App\Models\Concerns\MenyegarkanCachePublik;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Admin adds, edits (kode stays), deactivates and deletes unused rows (1.2.1 Task 6, K7). Inactive rows stay on old SPT only.
#[Fillable(['kode', 'nama', 'wajib_item_pkpt', 'syarat_selesai', 'tampil_publik', 'warna', 'aktif', 'urutan'])]
class Klasifikasi extends Model
{
    use DicatatAktivitas, MenyegarkanCachePublik;

    protected $table = 'klasifikasi';

    protected function casts(): array
    {
        return ['wajib_item_pkpt' => 'boolean', 'tampil_publik' => 'boolean', 'syarat_selesai' => SyaratSelesai::class, 'aktif' => 'boolean', 'urutan' => 'integer'];
    }

    public function spt(): HasMany
    {
        return $this->hasMany(Spt::class);
    }

    /** Every klasifikasi list orders by urutan, not id. */
    public function scopeUrut(Builder $q): void
    {
        $q->orderBy('urutan')->orderBy('id');
    }

    public function scopeAktif(Builder $q): void
    {
        $q->where('aktif', true);
    }

    /** Name for lists that also hold inactive rows. */
    public function label(): string
    {
        return $this->aktif ? $this->nama : "{$this->nama} (nonaktif)";
    }
}
