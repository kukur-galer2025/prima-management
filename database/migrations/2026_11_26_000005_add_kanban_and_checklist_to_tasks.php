<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status "sedang dikerjakan" + urutan manual untuk tampilan kanban, dan subtugas (checklist).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('is_done');
            $table->unsignedInteger('position')->default(0)->after('started_at');
        });

        Schema::create('task_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_items');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'position']);
        });
    }
};
