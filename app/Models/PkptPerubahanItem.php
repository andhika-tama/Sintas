<?php

namespace App\Models;

use App\Enums\AksiPerubahan;
use App\Models\Concerns\DicatatAktivitas;
use Database\Factories\PkptPerubahanItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One operation of a Perubahan PKPT. data_baru/data_lama: {irban_id, jenis_penugasan_id, tanggal_mulai, tanggal_selesai, keterangan, target_spt}. */
#[Fillable(['perubahan_id', 'aksi', 'pkpt_item_id', 'data_baru', 'data_lama', 'urutan'])]
class PkptPerubahanItem extends Model
{
    /** @use HasFactory<PkptPerubahanItemFactory> */
    use DicatatAktivitas, HasFactory;

    protected $table = 'pkpt_perubahan_item';

    protected function casts(): array
    {
        return ['aksi' => AksiPerubahan::class, 'data_baru' => 'array', 'data_lama' => 'array'];
    }

    public function perubahan()
    {
        return $this->belongsTo(PkptPerubahan::class, 'perubahan_id');
    }

    public function item()
    {
        return $this->belongsTo(PkptItem::class, 'pkpt_item_id');
    }
}
