<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jam deadline target (mis. ujian pukul 08:00); kosong = sepanjang hari.
     */
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->string('deadline_time', 5)->nullable()->after('deadline');
        });
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn('deadline_time');
        });
    }
};
