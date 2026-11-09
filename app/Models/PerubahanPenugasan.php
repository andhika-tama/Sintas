<?php

namespace App\Models;

use App\Enums\StatusPerubahanPenugasan;
use App\Models\Concerns\DicatatAktivitas;
use Database\Factories\PerubahanPenugasanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** 1.2: an Irban's request to change the schedule of its own PKPT item; Pimpinan approves (marks the item, K6) or rejects. */
#[Fillable(['pkpt_item_id', 'irban_id', 'tanggal_mulai', 'tanggal_selesai', 'alasan', 'diajukan_oleh', 'diinput_oleh'])]
class PerubahanPenugasan extends Model
{
    /** @use HasFactory<PerubahanPenugasanFactory> */
    use DicatatAktivitas, HasFactory;

    protected $table = 'perubahan_penugasan';

    protected $attributes = ['status' => 'diajukan'];

    protected function casts(): array
    {
        return [
            'status' => StatusPerubahanPenugasan::class,
            'tanggal_mulai' => 'immutable_date',
            'tanggal_selesai' => 'immutable_date',
            'diputuskan_pada' => 'immutable_datetime',
        ];
    }

    public function item()
    {
        return $this->belongsTo(PkptItem::class, 'pkpt_item_id');
    }

    public function irban()
    {
        return $this->belongsTo(Irban::class);
    }

    public function diajukanOleh()
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function diinputOleh()
    {
        return $this->belongsTo(Pegawai::class, 'diinput_oleh');
    }

    public function diputuskanOleh()
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }
}
