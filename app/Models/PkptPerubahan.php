<?php

namespace App\Models;

use App\Enums\JenisPerubahan;
use App\Enums\StatusPerubahan;
use App\Models\Concerns\DicatatAktivitas;
use App\Models\Concerns\MenyegarkanCachePublik;
use Database\Factories\PkptPerubahanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A Perubahan PKPT package: many operations on a Disahkan PKPT, applied only after Pimpinan approval. */
#[Fillable(['pkpt_id', 'jenis', 'alasan', 'nomor_sk', 'tanggal_sk'])]
class PkptPerubahan extends Model
{
    /** @use HasFactory<PkptPerubahanFactory> */
    use DicatatAktivitas, HasFactory, MenyegarkanCachePublik;

    protected $table = 'pkpt_perubahan';

    protected $attributes = ['status' => 'draf', 'sumber' => 'admin'];

    protected function casts(): array
    {
        return [
            'jenis' => JenisPerubahan::class,
            'status' => StatusPerubahan::class,
            'diajukan_pada' => 'datetime',
            'diputuskan_pada' => 'datetime',
            'tanggal_sk' => 'immutable_date',
            'sk_lengkap_pada' => 'datetime',
            'pengingat_sk_dimatikan_pada' => 'datetime',
            'pengingat_terakhir_pada' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The stored twin of skLengkap() (needed by the perluSk scope), written here only: it can never disagree with the fields.
        static::saving(fn (self $p) => $p->sk_lengkap_pada = $p->skLengkap() ? ($p->sk_lengkap_pada ?? now()) : null);
    }

    public function pkpt()
    {
        return $this->belongsTo(Pkpt::class);
    }

    public function operasi()
    {
        return $this->hasMany(PkptPerubahanItem::class, 'perubahan_id')->orderBy('urutan')->orderBy('id');
    }

    public function riwayat()
    {
        return $this->hasMany(PkptPerubahanRiwayat::class, 'perubahan_id');
    }

    public function pembuat()
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function pemutus()
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }

    /** Approved by Pimpinan from an Admin paket and the SK is not complete yet: "SK belum dilengkapi" (list marker, dasbor card, daily reminder). */
    public function perluSk(): bool
    {
        return $this->status === StatusPerubahan::Disetujui && $this->sumber === 'admin' && ! $this->skLengkap();
    }

    /** Query twin of perluSk(): sk_lengkap_pada is kept equal to skLengkap() on every save (booted). Call it on a builder (`PkptPerubahan::query()->perluSk()`). */
    public function scopePerluSk(Builder $query): void
    {
        $query->where('status', StatusPerubahan::Disetujui)->where('sumber', 'admin')->whereNull('sk_lengkap_pada');
    }

    /** Operations, jenis and alasan can still be edited (and the package submitted): draf or ditolak. */
    public function bisaDiubah(): bool
    {
        return in_array($this->status, [StatusPerubahan::Draf, StatusPerubahan::Ditolak], true);
    }

    /** Editable and never approved (no number yet): the package can be discarded. */
    public function bisaDibuang(): bool
    {
        return $this->bisaDiubah() && $this->nomor_urut === null;
    }

    /** SK counts as complete when number, date and PDF are all filled. */
    public function skLengkap(): bool
    {
        return filled($this->nomor_sk) && $this->tanggal_sk !== null && filled($this->berkas_sk);
    }
}
