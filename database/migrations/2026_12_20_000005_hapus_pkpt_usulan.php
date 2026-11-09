<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.2 Task 8 (K7, K11): Irbans no longer propose Perubahan PKPT. Open `geser` usulan become Diajukan Perubahan Penugasan; every other
 * usulan is dropped with the table. Irreversible: down() restores the empty schema only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->pindahkan();

        Schema::table('pkpt_perubahan_item', function (Blueprint $table) {
            $table->dropForeign(['usulan_id']);
            $table->dropColumn('usulan_id');
        });
        Schema::dropIfExists('pkpt_usulan');
    }

    /**
     * Open `geser` usulan of aktif items, the newest per item, and only where the item has no Diajukan request yet (R2: one per item).
     * Same item, irban, dates, alasan, diajukan_oleh, diinput_oleh and timestamps.
     */
    private function pindahkan(): void
    {
        DB::table('pkpt_usulan')->where('aksi_usulan', 'geser')->where('status', 'terbuka')
            ->whereNotNull('tanggal_mulai')->whereNotNull('tanggal_selesai')
            ->whereIn('pkpt_item_id', DB::table('pkpt_item')->where('status', 'aktif')->select('id'))
            ->whereNotIn('pkpt_item_id', DB::table('perubahan_penugasan')->where('status', 'diajukan')->select('pkpt_item_id'))
            ->orderByDesc('id')->get()->unique('pkpt_item_id')->sortBy('id')
            ->each(fn ($u) => DB::table('perubahan_penugasan')->insert([
                'pkpt_item_id' => $u->pkpt_item_id, 'irban_id' => $u->irban_id, 'tanggal_mulai' => $u->tanggal_mulai,
                'tanggal_selesai' => $u->tanggal_selesai, 'alasan' => $u->alasan, 'status' => 'diajukan', 'diajukan_oleh' => $u->diajukan_oleh,
                'diinput_oleh' => $u->diinput_oleh, 'created_at' => $u->created_at, 'updated_at' => $u->updated_at,
            ]));
    }

    public function down(): void
    {
        // Schema only (as 2026_11_20_000019 created it): the dropped usulan are not restored.
        Schema::create('pkpt_usulan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pkpt_id')->constrained('pkpt')->cascadeOnDelete();
            $table->foreignId('irban_id')->constrained('irban')->restrictOnDelete();
            $table->foreignId('pkpt_item_id')->nullable()->constrained('pkpt_item')->restrictOnDelete();
            $table->string('aksi_usulan');
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->foreignId('jenis_penugasan_id')->nullable()->constrained('jenis_penugasan')->restrictOnDelete();
            $table->text('alasan');
            $table->foreignId('diajukan_oleh')->constrained('users')->restrictOnDelete();
            $table->foreignId('diinput_oleh')->nullable()->constrained('pegawai')->restrictOnDelete();
            $table->string('status')->default('terbuka');
            $table->foreignId('perubahan_id')->nullable()->constrained('pkpt_perubahan')->nullOnDelete();
            $table->foreignId('ditutup_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->text('catatan_penutupan')->nullable();
            $table->timestamps();
            $table->index(['pkpt_id', 'status']);
            $table->index(['irban_id', 'status']);
        });
        Schema::table('pkpt_perubahan_item', function (Blueprint $table) {
            $table->foreignId('usulan_id')->nullable()->after('data_lama')->constrained('pkpt_usulan')->nullOnDelete();
        });
    }
};
