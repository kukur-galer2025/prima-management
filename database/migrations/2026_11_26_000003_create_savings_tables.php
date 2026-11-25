<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabungan untuk tujuan tertentu (mis. "Laptop baru Rp8 juta") beserta riwayat setor/tarik.
     */
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('emoji', 16)->nullable();
            $table->unsignedBigInteger('target_amount');
            $table->date('deadline')->nullable();
            $table->timestamps();
        });

        Schema::create('savings_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            // Dompet asal setoran (atau tujuan penarikan); kosong = tidak memengaruhi saldo dompet.
            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount'); // positif = setor, negatif = tarik
            $table->date('deposited_on');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_deposits');
        Schema::dropIfExists('savings_goals');
    }
};
