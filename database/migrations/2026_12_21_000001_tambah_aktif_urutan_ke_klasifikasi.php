<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 1.2.1 Task 6 (K7): Admin adds klasifikasi; an inactive one leaves new-SPT forms, and every list orders by urutan instead of id. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('klasifikasi', function (Blueprint $table) {
            $table->boolean('aktif')->default(true)->after('warna');
            $table->unsignedInteger('urutan')->default(0)->after('aktif');
        });
        $this->isi();
    }

    /** Data only, idempotent (also run by KlasifikasiTest): rows without urutan get the next numbers in id order, so the five rows become 1..5. */
    public function isi(): void
    {
        $n = (int) DB::table('klasifikasi')->max('urutan');
        foreach (DB::table('klasifikasi')->where('urutan', 0)->orderBy('id')->pluck('id') as $id) {
            DB::table('klasifikasi')->where('id', $id)->update(['urutan' => ++$n]);
        }
    }

    public function down(): void
    {
        Schema::table('klasifikasi', function (Blueprint $table) {
            $table->dropColumn(['aktif', 'urutan']);
        });
    }
};
