<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jurnal harian: satu entri per hari berisi mood (1–5) dan catatan singkat.
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_on');
            $table->unsignedTinyInteger('mood');
            $table->text('content')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'entry_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
