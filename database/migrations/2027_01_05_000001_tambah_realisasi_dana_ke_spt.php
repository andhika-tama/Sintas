<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 1.3 Task 3 (K4): realisasi dana per SPT in whole Rupiah; null = not filled yet (0 is a real value). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spt', function (Blueprint $table) {
            $table->unsignedBigInteger('realisasi_dana')->nullable()->after('nomor_sppd');
        });
    }

    public function down(): void
    {
        Schema::table('spt', function (Blueprint $table) {
            $table->dropColumn('realisasi_dana');
        });
    }
};
