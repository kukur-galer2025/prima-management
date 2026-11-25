<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target besar: TOEFL, TPA, SKD, Proposal S2, dst.
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('icon', 16)->nullable();
            $table->string('color', 7)->default('#7c3aed');
            $table->unsignedInteger('target_hours')->nullable();
            $table->date('deadline')->nullable();
            $table->unsignedTinyInteger('priority')->default(2); // 1 tinggi, 2 sedang, 3 rendah
            $table->string('status', 10)->default('active');      // active | paused | done
            $table->timestamps();
        });

        // Sub-materi per target (checklist)
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // To-do konkret, bisa terkait target atau berdiri sendiri
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });

        // Log belajar
        Schema::create('study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->date('studied_on');
            $table->unsignedInteger('minutes');
            $table->decimal('score', 7, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'studied_on']);
        });

        // Pola belajar mingguan yang berulang
        Schema::create('schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 1 = Senin ... 7 = Minggu (ISO)
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_slots');
        Schema::dropIfExists('study_sessions');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('goals');
    }
};
