<?php

namespace App\Support;

/** Icon tile per notification `jenis` (heroicons outline + tone), shared by the bell and the list page. */
class JenisNotifikasi
{
    private const IKON = [
        'pengajuan_baru' => ['ikon' => 'inbox-arrow-down', 'nada' => 'info'],
        'pengajuan_diterima' => ['ikon' => 'check-circle', 'nada' => 'success'],
        'pengajuan_perlu_revisi' => ['ikon' => 'pencil-square', 'nada' => 'warning'],
        'pengajuan_ditolak' => ['ikon' => 'x-circle', 'nada' => 'danger'],
        'nomor_dibatalkan' => ['ikon' => 'no-symbol', 'nada' => 'danger'],
        'tanggal_realisasi_diubah' => ['ikon' => 'calendar-days', 'nada' => 'info'],
        // Legacy: the old Irban schedule revision (removed in Fase 9.2); kept so existing bell rows keep their icon.
        'revisi_jadwal_diajukan' => ['ikon' => 'calendar-days', 'nada' => 'info'],
        'revisi_jadwal_disetujui' => ['ikon' => 'calendar-days', 'nada' => 'success'],
        'revisi_jadwal_ditolak' => ['ikon' => 'calendar-days', 'nada' => 'danger'],
        'perubahan_pkpt_diajukan' => ['ikon' => 'calendar-days', 'nada' => 'info'],
        'perubahan_pkpt_disetujui' => ['ikon' => 'calendar-days', 'nada' => 'success'],
        'perubahan_pkpt_ditolak' => ['ikon' => 'calendar-days', 'nada' => 'danger'],
        'pengingat_perubahan_pkpt' => ['ikon' => 'clock', 'nada' => 'warning'],
        'pengingat_sk_perubahan' => ['ikon' => 'document-arrow-up', 'nada' => 'warning'],
        // Usulan flow removed in 1.2 Task 8: kept so old notification rows keep their icon.
        'usulan_perubahan_baru' => ['ikon' => 'inbox-arrow-down', 'nada' => 'info'],
        'usulan_perubahan_diterapkan' => ['ikon' => 'check-circle', 'nada' => 'success'],
        'usulan_perubahan_ditutup' => ['ikon' => 'x-circle', 'nada' => 'neutral'],
        'perubahan_penugasan_diajukan' => ['ikon' => 'calendar-days', 'nada' => 'info'],
        'perubahan_penugasan_disetujui' => ['ikon' => 'calendar-days', 'nada' => 'success'],
        'perubahan_penugasan_ditolak' => ['ikon' => 'calendar-days', 'nada' => 'danger'],
        'spt_terlambat' => ['ikon' => 'clock', 'nada' => 'danger'],
        'pengingat_spt_final' => ['ikon' => 'document-arrow-up', 'nada' => 'warning'],
        'komentar_baru' => ['ikon' => 'chat-bubble-left-ellipsis', 'nada' => 'info'],
        'minta_penjelasan' => ['ikon' => 'question-mark-circle', 'nada' => 'warning'],
        'balasan_penjelasan' => ['ikon' => 'chat-bubble-left-right', 'nada' => 'success'],
        'ringkasan_mingguan' => ['ikon' => 'chart-bar', 'nada' => 'neutral'],
        'rangkuman_nomor_terbit' => ['ikon' => 'document-check', 'nada' => 'info'],
        'ekspor_siap' => ['ikon' => 'arrow-down-tray', 'nada' => 'success'],
        'ekspor_gagal' => ['ikon' => 'exclamation-triangle', 'nada' => 'danger'],
        // Mail-only (Profil); listed for completeness.
        'kata_sandi_diubah' => ['ikon' => 'lock-closed', 'nada' => 'warning'],
        'email_diubah' => ['ikon' => 'envelope', 'nada' => 'info'],
        'verifikasi_email' => ['ikon' => 'envelope', 'nada' => 'info'],
    ];

    /** @return array{ikon: string, nada: string} */
    public static function ikon(string $jenis): array
    {
        return self::IKON[$jenis] ?? ['ikon' => 'bell', 'nada' => 'neutral'];
    }
}
