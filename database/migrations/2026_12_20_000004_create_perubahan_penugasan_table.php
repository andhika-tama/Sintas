<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1.2 K1/K5: an Irban asks to change the schedule of its own PKPT item; Pimpinan decides, the item's dates never change.
        Schema::create('perubahan_penugasan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pkpt_item_id')->constrained('pkpt_item')->cascadeOnDelete();
            $table->foreignId('irban_id')->constrained('irban')->restrictOnDelete();
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->text('alasan');
            $table->string('status')->default('diajukan');
            $table->text('catatan_putusan')->nullable();
            $table->foreignId('diajukan_oleh')->constrained('users')->restrictOnDelete();
            $table->foreignId('diinput_oleh')->nullable()->constrained('pegawai')->restrictOnDelete();
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('diputuskan_pada')->nullable();
            $table->timestamps();
            $table->index(['pkpt_item_id', 'status']);
            $table->index(['irban_id', 'status']);
        });

        Schema::table('pkpt_item', function (Blueprint $table) {
            // K6: the latest approved Perubahan Penugasan; set = every SPT on the item is Tidak Sesuai PKPT. Cleared by K10.
            $table->foreignId('perubahan_penugasan_id')->nullable()->constrained('perubahan_penugasan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pkpt_item', function (Blueprint $table) {
            $table->dropForeign(['perubahan_penugasan_id']);
            $table->dropColumn('perubahan_penugasan_id');
        });
        Schema::dropIfExists('perubahan_penugasan');
    }
};
