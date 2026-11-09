<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spt', function (Blueprint $table) {
            // 1.2 K3: free-text "Lainnya" for a non-PKPT SPT; null whenever the SPT has a PKPT item (SimpanSpt).
            $table->string('jenis_penugasan_lainnya', 150)->nullable()->after('jenis_penugasan_id');
            $table->string('objek_lainnya', 255)->nullable()->after('jenis_penugasan_lainnya');
        });
    }

    public function down(): void
    {
        Schema::table('spt', function (Blueprint $table) {
            $table->dropColumn(['jenis_penugasan_lainnya', 'objek_lainnya']);
        });
    }
};
