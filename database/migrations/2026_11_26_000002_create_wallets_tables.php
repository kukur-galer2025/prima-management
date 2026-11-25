<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dompet (tunai, rekening bank, e-wallet) dan transfer antar-dompet.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('type', 10)->default('cash'); // cash | bank | ewallet | other
            $table->bigInteger('opening_balance')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->foreignId('to_wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('transferred_on');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'transferred_on']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('wallet_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->foreignId('wallet_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        // Pengguna yang sudah punya transaksi mendapat satu dompet awal berisi semua transaksinya.
        $userIds = DB::table('transactions')->distinct()->pluck('user_id')
            ->merge(DB::table('recurring_transactions')->distinct()->pluck('user_id'))
            ->unique();

        foreach ($userIds as $userId) {
            $walletId = DB::table('wallets')->insertGetId([
                'user_id' => $userId,
                'name' => 'Dompet utama',
                'type' => 'cash',
                'opening_balance' => 0,
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('transactions')->where('user_id', $userId)->update(['wallet_id' => $walletId]);
            DB::table('recurring_transactions')->where('user_id', $userId)->update(['wallet_id' => $walletId]);
        }
    }

    public function down(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_id');
        });

        Schema::dropIfExists('wallet_transfers');
        Schema::dropIfExists('wallets');
    }
};
