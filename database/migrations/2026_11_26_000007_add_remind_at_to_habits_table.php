<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jam pengingat per kebiasaan (mis. Olahraga 06:00); kosong = tanpa pengingat.
     */
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->string('remind_at', 5)->nullable()->after('days');
        });
    }

    public function down(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->dropColumn('remind_at');
        });
    }
};
