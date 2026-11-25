<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index untuk query yang paling sering dipakai saat banyak pengguna.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['user_id', 'is_done', 'due_date']);
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at']);
        });

        Schema::table('reminders', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex(['user_id', 'is_done', 'due_date']));
        Schema::table('goals', fn (Blueprint $table) => $table->dropIndex(['user_id', 'status']));
        Schema::table('notes', fn (Blueprint $table) => $table->dropIndex(['user_id', 'updated_at']));
        Schema::table('reminders', fn (Blueprint $table) => $table->dropIndex(['user_id', 'created_at']));
    }
};
