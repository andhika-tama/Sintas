<?php

namespace App\Models;

use App\Models\Concerns\DicatatAktivitas;
use Database\Factories\PegawaiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Fillable(['nama_bergelar', 'nip', 'pangkat', 'golongan', 'jabatan', 'email', 'aktif'])]
class Pegawai extends Model
{
    /** @use HasFactory<PegawaiFactory> */
    use DicatatAktivitas, HasFactory;

    protected $table = 'pegawai';

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    /** True when other records point at this pegawai, so it may only be deactivated. */
    public function isReferenced(): bool
    {
        return DB::table('tim_anggota')->where('pegawai_id', $this->id)->exists()
            || DB::table('irban_anggota')->where('pegawai_id', $this->id)->exists()
            || DB::table('irban_tahunan')->where('ppj_pegawai_id', $this->id)->exists()
            || DB::table('perubahan_penugasan')->where('diinput_oleh', $this->id)->exists()
            || DB::table('spt')->where(fn ($q) => $q->where('ppj_pegawai_id', $this->id)->orWhere('diinput_oleh', $this->id)->orWhere('penandatangan_pegawai_id', $this->id))->exists()
            || DB::table('spt_personel')->where('pegawai_id', $this->id)->exists()
            || DB::table('spt_dokumen')->where('diinput_oleh', $this->id)->exists()
            || DB::table('spt_riwayat')->where('diinput_oleh', $this->id)->exists();
    }
}
