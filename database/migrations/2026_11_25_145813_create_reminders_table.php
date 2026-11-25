<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Isi notifikasi yang sudah dikirim. Service worker mengambilnya dari sini saat push masuk,
     * dan kolom key mencegah pengingat yang sama terkirim dua kali.
     */
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('title');
            $table->string('body', 500);
            $table->string('url')->default('/');
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
