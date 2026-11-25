<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Verifikasi email baru diwajibkan untuk pendaftar baru; akun yang sudah ada
     * (dibuat lewat app:create-user / seeder) dianggap sudah terverifikasi agar tidak terkunci.
     */
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Tidak dibalik: status verifikasi asli tidak diketahui.
    }
};
