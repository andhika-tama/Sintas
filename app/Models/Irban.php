<?php

namespace App\Models;

use App\Models\Concerns\DicatatAktivitas;
use App\Models\Concerns\MenyegarkanCachePublik;
use Database\Factories\IrbanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['nama', 'kode_nomor', 'email_notifikasi', 'email_tembusan'])]
class Irban extends Model
{
    /** @use HasFactory<IrbanFactory> */
    use DicatatAktivitas, HasFactory, MenyegarkanCachePublik;

    protected $table = 'irban';

    protected function casts(): array
    {
        return ['email_tembusan' => 'array'];
    }

    public function tahunan()
    {
        return $this->hasMany(IrbanTahunan::class);
    }

    /** True when users, irban_tahunan, tim, wilayah, PKPT items or SPT point at this irban, so it cannot be deleted. */
    public function isReferenced(): bool
    {
        return User::where('irban_id', $this->id)->exists() || $this->tahunan()->exists() || Tim::where('irban_id', $this->id)->exists()
            || IrbanWilayah::where('irban_id', $this->id)->exists() || PkptItem::where('irban_id', $this->id)->exists()
            || Spt::where('irban_id', $this->id)->exists() || PerubahanPenugasan::where('irban_id', $this->id)->exists();
    }
}
