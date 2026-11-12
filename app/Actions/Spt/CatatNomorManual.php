<?php

namespace App\Actions\Spt;

use App\Enums\KejadianSpt;
use App\Enums\StatusPengajuan;
use App\Enums\SumberSpt;
use App\Models\Irban;
use App\Models\Klasifikasi;
use App\Models\KodeArsip;
use App\Models\Spt;
use App\Models\User;
use App\Services\Penomoran;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bagian Umum records an urgent SPT outside the inbox flow. The number comes from the same locked sequence as accepted submissions
 * (PRD §7.7), never typed in. $data: irban_id, klasifikasi_id, kode_arsip_id, jenis_penugasan_id?, uraian_untuk, tanggal_mulai, tanggal_selesai, alasan,
 * nomor_sppd? (UbahNomorSppd, same transaction).
 */
class CatatNomorManual
{
    public function __construct(private Penomoran $penomoran, private UbahNomorSppd $sppd) {}

    public function handle(User $user, array $data): Spt
    {
        Gate::forUser($user)->authorize('kelolaNomor', Spt::class);
        // Validated together with the other fields so every error shows at once; UbahNomorSppd::normal stays the single rule.
        try {
            $sppd = UbahNomorSppd::normal($data['nomor_sppd'] ?? null);
            $galatSppd = [];
        } catch (ValidationException $e) {
            [$sppd, $galatSppd] = [null, $e->errors()];
        }
        $v = Validator::make($data, [
            'irban_id' => ['required', 'exists:irban,id'],
            'klasifikasi_id' => ['required', 'exists:klasifikasi,id'],
            'kode_arsip_id' => ['required', 'exists:kode_arsip,id'],
            'jenis_penugasan_id' => ['nullable', 'exists:jenis_penugasan,id'],
            'uraian_untuk' => ['required', 'string', 'max:1000'],
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'alasan' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'irban_id.*' => 'Pilih Irban.', 'klasifikasi_id.*' => 'Pilih klasifikasi.', 'kode_arsip_id.*' => 'Pilih kode arsip.', 'jenis_penugasan_id.*' => 'Jenis penugasan tidak valid.',
            'uraian_untuk.required' => 'Perihal wajib diisi.', 'uraian_untuk.max' => 'Perihal maksimal 1000 karakter.',
            'tanggal_mulai.*' => 'Tanggal mulai wajib diisi.', 'tanggal_selesai.required' => 'Tanggal selesai wajib diisi.', 'tanggal_selesai.date_format' => 'Tanggal selesai wajib diisi.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'alasan.required' => 'Alasan wajib diisi.', 'alasan.min' => 'Alasan minimal 10 karakter.', 'alasan.max' => 'Alasan maksimal 1000 karakter.',
        ]);
        // A manual number is a new SPT: an inactive klasifikasi is refused (1.2.1 Task 6).
        $v->after(function ($v) use ($data) {
            $k = $v->errors()->has('klasifikasi_id') ? null : Klasifikasi::find($data['klasifikasi_id']);
            if ($k && ! $k->aktif) {
                $v->errors()->add('klasifikasi_id', "Klasifikasi {$k->nama} sudah nonaktif.");
            }
        });
        if ($v->fails() || $galatSppd) {
            throw ValidationException::withMessages($v->errors()->messages() + $galatSppd);
        }
        $d = $v->validated();

        return DB::transaction(function () use ($user, $d, $sppd) {
            $irban = Irban::findOrFail($d['irban_id']);
            $tanggal = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
            $n = $this->penomoran->ambil($irban, $tanggal, Klasifikasi::findOrFail($d['klasifikasi_id']), KodeArsip::findOrFail($d['kode_arsip_id']));
            $spt = new Spt(['irban_id' => $irban->id, 'klasifikasi_id' => $d['klasifikasi_id'], 'kode_arsip_id' => $d['kode_arsip_id'], 'jenis_penugasan_id' => ($d['jenis_penugasan_id'] ?? null) ?: null,
                'uraian_untuk' => trim($d['uraian_untuk']), 'tanggal_mulai' => $d['tanggal_mulai'], 'tanggal_selesai' => $d['tanggal_selesai']]);
            $spt->forceFill([
                'status_pengajuan' => StatusPengajuan::Diterima, 'sumber' => SumberSpt::Manual, 'dibuat_oleh' => $user->id, 'diajukan_pada' => now(),
                'nomor_urut' => $n['nomor_urut'], 'nomor_lengkap' => $n['nomor_lengkap'], 'nomor_counter_id' => $n['counter']->id,
                'tahun' => $tanggal->year, 'tanggal_spt' => $tanggal->toDateString(),
            ])->save();
            $spt->riwayat()->create([
                'kejadian' => KejadianSpt::DICATAT_MANUAL, 'nilai_baru' => ['status_pengajuan' => StatusPengajuan::Diterima->value, 'nomor_lengkap' => $n['nomor_lengkap']],
                'alasan' => trim($d['alasan']), 'oleh' => $user->id,
            ]);
            $this->sppd->terapkan($user, $spt, $sppd);

            return $spt;
        }, attempts: 3);
    }
}
