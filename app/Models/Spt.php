<?php

namespace App\Models;

use App\Enums\StatusPengajuan;
use App\Enums\StatusSpt;
use App\Enums\SumberSpt;
use App\Models\Concerns\DicatatAktivitas;
use App\Models\Concerns\MenyegarkanCachePublik;
use App\Support\Tanggal;
use Database\Factories\SptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Status, number and authorship columns are set by the actions with forceFill, never mass-assigned from input.
#[Fillable(['irban_id', 'klasifikasi_id', 'kode_arsip_id', 'pkpt_item_id', 'jenis_penugasan_id', 'jenis_penugasan_lainnya', 'objek_lainnya', 'ppj_pegawai_id',
    'periode_diperiksa', 'uraian_untuk', 'tanggal_mulai', 'tanggal_selesai', 'catatan_bagian_umum', 'diinput_oleh', 'penandatangan_pegawai_id', 'status_penandatangan'])]
class Spt extends Model
{
    /** @use HasFactory<SptFactory> */
    use DicatatAktivitas, HasFactory, MenyegarkanCachePublik;

    /** Group label for free-text jenis/objek of a non-PKPT SPT (1.2 K3). */
    public const LABEL_LAINNYA = 'Lainnya';

    protected $table = 'spt';

    /** Master jenis name, else the non-PKPT free text; null while neither is set (drafts). */
    public function namaJenis(): ?string
    {
        return $this->jenisPenugasan?->nama ?? $this->jenis_penugasan_lainnya;
    }

    /**
     * @param  bool  $denganInduk  "Induk › Nama" for a child objek (needs objek.parent loaded)
     * @return list<string> master objek names sorted by name, then objek_lainnya when set.
     */
    public function namaObjek(bool $denganInduk = false): array
    {
        $nama = $this->objek->map(fn ($o) => $denganInduk && $o->parent ? $o->parent->nama.' › '.$o->nama : $o->nama)
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        if ($this->objek_lainnya !== null && $this->objek_lainnya !== '') {
            $nama[] = $this->objek_lainnya;
        }

        return $nama;
    }

    protected function casts(): array
    {
        return [
            'status_pengajuan' => StatusPengajuan::class,
            'sumber' => SumberSpt::class,
            'tanggal_mulai' => 'immutable_date',
            'tanggal_selesai' => 'immutable_date',
            'tanggal_spt' => 'immutable_date',
            'tanggal_lhp' => 'immutable_date',
            'diajukan_pada' => 'immutable_datetime',
            'ditandai_selesai_pada' => 'immutable_datetime',
            'terlambat_diberitahu_pada' => 'immutable_datetime',
            'pengingat_final_pada' => 'immutable_datetime',
        ];
    }

    /**
     * Computed from status_pengajuan and today in Asia/Jakarta (PRD §9.2); no cron.
     *
     * @param  ?string  $hariIni  today in Jakarta as Y-m-d, passed by batch callers (PenandaSpt, MonitoringSpt); default now.
     */
    public function status(?string $hariIni = null): StatusSpt
    {
        return match ($this->status_pengajuan ?? StatusPengajuan::Draf) {
            StatusPengajuan::Draf => StatusSpt::Draf,
            StatusPengajuan::MenungguNomor => StatusSpt::MenungguNomor,
            StatusPengajuan::PerluRevisi => StatusSpt::PerluRevisi,
            StatusPengajuan::Ditolak => StatusSpt::Ditolak,
            StatusPengajuan::Dibatalkan => StatusSpt::Dibatalkan,
            StatusPengajuan::Diterima => $this->statusBerjalan($hariIni ?? now('Asia/Jakarta')->toDateString()),
        };
    }

    private function statusBerjalan(string $hariIni): StatusSpt
    {
        if ($this->ditandai_selesai_pada !== null) {
            return StatusSpt::Selesai;
        }
        if ($this->tanggal_mulai === null || $hariIni < $this->tanggal_mulai->toDateString()) {
            return StatusSpt::SiapDilaksanakan;
        }

        return $this->tanggal_selesai === null || $hariIni <= $this->tanggal_selesai->toDateString() ? StatusSpt::Pelaksanaan : StatusSpt::PenyusunanLaporan;
    }

    /** Klasifikasi / Kode Arsip / Jenis filter (App\Services\SaringSpt); `jenis` = master id or 'lainnya' (free-text jenis, no master). */
    public function scopeSaring(Builder $q, array $saring): void
    {
        $jenis = $saring['jenis'] ?? null;
        $q->when(($saring['klasifikasi'] ?? null) !== null, fn ($q) => $q->where('klasifikasi_id', $saring['klasifikasi']))
            ->when(($saring['kode_arsip'] ?? null) !== null, fn ($q) => $q->where('kode_arsip_id', $saring['kode_arsip']))
            ->when($jenis === 'lainnya', fn ($q) => $q->whereNull('jenis_penugasan_id')->whereNotNull('jenis_penugasan_lainnya'))
            ->when($jenis !== null && $jenis !== 'lainnya', fn ($q) => $q->where('jenis_penugasan_id', $jenis));
    }

    /** Irban year (SPT Saya, Irban dashboard): the year of tanggal_mulai; drafts without dates count in the year they were created. */
    public function scopeTahunPenugasan(Builder $q, int $tahun): void
    {
        $q->where(fn ($q) => $q->whereYear('tanggal_mulai', $tahun)->orWhere(fn ($q) => $q->whereNull('tanggal_mulai')->whereYear('created_at', $tahun)));
    }

    /** SQL twin of status() for list filters; keep both in step. */
    public function scopeBerstatus(Builder $q, StatusSpt $status): void
    {
        $hariIni = now('Asia/Jakarta')->toDateString();
        $diterima = fn () => $q->where('status_pengajuan', StatusPengajuan::Diterima)->whereNull('ditandai_selesai_pada');
        match ($status) {
            StatusSpt::SiapDilaksanakan => $diterima()->where(fn ($w) => $w->whereNull('tanggal_mulai')->orWhereDate('tanggal_mulai', '>', $hariIni)),
            StatusSpt::Pelaksanaan => $diterima()->whereDate('tanggal_mulai', '<=', $hariIni)->where(fn ($w) => $w->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $hariIni)),
            StatusSpt::PenyusunanLaporan => $diterima()->whereDate('tanggal_mulai', '<=', $hariIni)->whereDate('tanggal_selesai', '<', $hariIni),
            StatusSpt::Selesai => $q->where('status_pengajuan', StatusPengajuan::Diterima)->whereNotNull('ditandai_selesai_pada'),
            default => $q->where('status_pengajuan', StatusPengajuan::from($status->value)),
        };
    }

    /** Inclusive days, kalender or kerja per `penugasan.hitungan_hari`; null while dates are incomplete. */
    public function durasi(): ?int
    {
        if ($this->tanggal_mulai === null || $this->tanggal_selesai === null) {
            return null;
        }

        return Tanggal::durasiPenugasan($this->tanggal_mulai, $this->tanggal_selesai);
    }

    public function irban()
    {
        return $this->belongsTo(Irban::class);
    }

    public function klasifikasi()
    {
        return $this->belongsTo(Klasifikasi::class);
    }

    public function kodeArsip(): BelongsTo
    {
        return $this->belongsTo(KodeArsip::class);
    }

    public function pkptItem()
    {
        return $this->belongsTo(PkptItem::class);
    }

    public function jenisPenugasan()
    {
        return $this->belongsTo(JenisPenugasan::class);
    }

    public function ppj()
    {
        return $this->belongsTo(Pegawai::class, 'ppj_pegawai_id');
    }

    public function penandatangan()
    {
        return $this->belongsTo(Pegawai::class, 'penandatangan_pegawai_id');
    }

    public function nomorCounter()
    {
        return $this->belongsTo(NomorCounter::class);
    }

    public function diinputOleh()
    {
        return $this->belongsTo(Pegawai::class, 'diinput_oleh');
    }

    public function dibuatOleh()
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function objek()
    {
        return $this->belongsToMany(Objek::class, 'spt_objek')->withTimestamps();
    }

    public function personel()
    {
        return $this->hasMany(SptPersonel::class)->orderBy('urutan');
    }

    public function dokumen()
    {
        return $this->hasMany(SptDokumen::class);
    }

    public function riwayat()
    {
        return $this->hasMany(SptRiwayat::class);
    }

    public function kendala()
    {
        return $this->hasOne(SptKendala::class);
    }

    public function komentar()
    {
        return $this->hasMany(Komentar::class);
    }

    public function ditandaiSelesaiOleh()
    {
        return $this->belongsTo(User::class, 'ditandai_selesai_oleh');
    }
}
