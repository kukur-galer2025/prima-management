<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun kini dikelola admin (tanpa pendaftaran mandiri): peran admin, status nonaktif,
     * waktu terakhir aktif, dan sambungan bot Telegram untuk pengingat.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->timestamp('disabled_at')->nullable()->after('is_admin');
            $table->timestamp('last_active_at')->nullable()->after('disabled_at');
            $table->string('telegram_chat_id', 32)->nullable()->after('calendar_token');
            $table->string('telegram_link_code', 32)->nullable()->unique()->after('telegram_chat_id');
        });

        // Pengguna pertama (pemilik aplikasi) otomatis menjadi admin.
        $firstUserId = DB::table('users')->min('id');

        if ($firstUserId) {
            DB::table('users')->where('id', $firstUserId)->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_link_code']);
            $table->dropColumn(['is_admin', 'disabled_at', 'last_active_at', 'telegram_chat_id', 'telegram_link_code']);
        });
    }
};
