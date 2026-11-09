<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 1.2 Task 2: master kode arsip; each SPT carries its own kode (K4) instead of the single setting `penomoran.kode_arsip`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kode_arsip', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();
            $table->string('nama', 100);
            $table->timestamps();
        });
        Schema::table('spt', function (Blueprint $table) {
            $table->foreignId('kode_arsip_id')->nullable()->after('klasifikasi_id')->constrained('kode_arsip')->restrictOnDelete();
        });
        $this->isi();
    }

    /**
     * Data only, idempotent (also run by PenomoranTest): the three reference rows, a stored setting outside them as its own row,
     * every SPT without a kode (imported ones included, R4) gets the setting's row or 700, then the setting row goes.
     */
    public function isi(): void
    {
        $now = now();
        $rows = [['700', 'Pengawasan Umum'], ['800', 'Kepegawaian'], ['900', 'Anggaran']];
        DB::table('kode_arsip')->insertOrIgnore(array_map(fn ($r) => ['kode' => $r[0], 'nama' => $r[1], 'created_at' => $now, 'updated_at' => $now], $rows));

        $setelan = DB::table('settings')->where('key', 'penomoran.kode_arsip')->value('value');
        $kode = trim((string) ($setelan === null ? '' : json_decode($setelan, true))) ?: '700';
        DB::table('kode_arsip')->insertOrIgnore(['kode' => $kode, 'nama' => $kode, 'created_at' => $now, 'updated_at' => $now]);

        DB::table('spt')->whereNull('kode_arsip_id')->update(['kode_arsip_id' => DB::table('kode_arsip')->where('kode', $kode)->value('id')]);
        DB::table('settings')->where('key', 'penomoran.kode_arsip')->delete();
    }

    public function down(): void
    {
        Schema::table('spt', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kode_arsip_id');
        });
        Schema::dropIfExists('kode_arsip');
    }
};
