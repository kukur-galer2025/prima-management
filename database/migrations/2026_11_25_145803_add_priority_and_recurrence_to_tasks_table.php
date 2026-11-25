<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(2)->after('notes'); // 1 tinggi, 2 sedang, 3 rendah
            $table->string('recurrence', 10)->nullable()->after('due_date');     // daily | weekly | monthly
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['priority', 'recurrence']);
        });
    }
};
