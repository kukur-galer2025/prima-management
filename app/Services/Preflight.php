<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Pemeriksaan kesiapan produksi (php artisan app:preflight). "error" menghentikan deploy; "warning" hanya mengingatkan.
 */
class Preflight
{
    /** Kata sandi contoh dari README/seeder yang tidak boleh dipakai di produksi. */
    private const DEMO_PASSWORDS = ['password', 'rahasia123', '12345678'];

    /**
     * @return array<int, array{level: 'ok'|'warning'|'error', message: string}>
     */
    public function run(): array
    {
        $results = [];
        $check = function (bool $passed, string $ok, string $problem, string $level = 'error') use (&$results): bool {
            $results[] = ['level' => $passed ? 'ok' : $level, 'message' => $passed ? $ok : $problem];

            return $passed;
        };

        $check(filled(config('app.key')), 'APP_KEY terisi', 'APP_KEY kosong. Jalankan: php artisan key:generate');
        $check(app()->isProduction(), 'APP_ENV=production', 'APP_ENV belum "production"');
        $check(! config('app.debug'), 'APP_DEBUG=false', 'APP_DEBUG masih true: detail error (termasuk rahasia) akan terlihat pengunjung');
        $check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL memakai https', 'APP_URL harus https:// (login, PWA, push, dan Telegram membutuhkannya)');
        $check(is_file(public_path('build/manifest.json')), 'Aset frontend sudah di-build', 'public/build/manifest.json tidak ada. Jalankan: npm ci && npm run build');
        $check(is_file(public_path('vendor/livewire/manifest.json')), 'JavaScript Livewire tersedia sebagai file statis', 'Jalankan: php artisan vendor:publish --tag=livewire:assets --force (tanpanya tombol & form bisa tidak berfungsi di Nginx)');
        $check(! is_file(public_path('hot')), 'Tidak ada sisa Vite dev server', 'File public/hot masih ada: tampilan akan rusak karena mencari Vite dev server');

        foreach (['framework/views', 'framework/cache', 'logs', 'app/private'] as $folder) {
            $check(is_writable(storage_path($folder)), "storage/{$folder} bisa ditulis", "storage/{$folder} tidak bisa ditulis oleh PHP");
        }

        $check(! config('view.check_cache_timestamps'), 'View tidak dikompilasi ulang saat situs berjalan', 'Isi VIEW_CHECK_CACHE_TIMESTAMPS=false (view sudah dikompilasi saat deploy)', 'warning');
        $check((bool) config('session.secure'), 'Cookie sesi hanya lewat https', 'SESSION_SECURE_COOKIE=true belum diatur', 'warning');
        $check(! in_array(config('mail.default'), ['log', 'array'], true), 'SMTP diatur', 'MAIL_MAILER masih "'.config('mail.default').'": undangan & lupa sandi tidak terkirim', 'warning');
        $check(filled(config('services.webpush.public_key')), 'Kunci VAPID terisi', 'Kunci VAPID kosong: notifikasi push tidak aktif', 'warning');
        $check(app(Telegram::class)->isConfigured(), 'Bot Telegram diatur', 'Bot Telegram belum diatur', 'warning');
        $check(filled(config('app.admin_email')) && config('app.error_alerts'), 'Email peringatan error aktif', 'ADMIN_EMAIL / ERROR_ALERTS belum diatur: kamu tidak diberi tahu bila ada error', 'warning');

        $heartbeat = rescue(fn () => Cache::get('scheduler.heartbeat'), report: false);
        $check($heartbeat && now()->timestamp - $heartbeat < 300, 'Scheduler (cron) berjalan', 'Scheduler belum terdeteksi: pasang cron "php artisan schedule:run" tiap menit (pengingat & backup)', 'warning');

        return [...$results, ...$this->database()];
    }

    /**
     * @return array<int, array{level: 'ok'|'warning'|'error', message: string}>
     */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            Artisan::call('migrate:status');

            if (str_contains(Artisan::output(), 'Pending')) {
                return [['level' => 'error', 'message' => 'Ada migrasi yang belum dijalankan. Jalankan: php artisan migrate --force']];
            }

            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && ! app(DatabaseBackup::class)->dumpBinary()) {
                return [['level' => 'error', 'message' => 'mysqldump tidak ditemukan: backup tidak bisa jalan. Pasang mysql-client atau isi DB_DUMP_BINARY']];
            }

            $results = [['level' => 'ok', 'message' => 'Database tersambung & skema terbaru ('.DB::connection()->getDriverName().')']];
            $admins = User::query()->where('is_admin', true)->whereNull('disabled_at')->get();

            $results[] = $admins->isNotEmpty()
                ? ['level' => 'ok', 'message' => $admins->count().' admin aktif']
                : ['level' => 'error', 'message' => 'Belum ada admin. Jalankan: php artisan app:create-user --admin'];

            foreach ($admins as $admin) {
                if (collect(self::DEMO_PASSWORDS)->contains(fn (string $demo) => Hash::check($demo, $admin->password))) {
                    $results[] = ['level' => 'error', 'message' => "Admin {$admin->email} masih memakai kata sandi contoh. Ganti dulu di halaman Akun."];
                }
            }

            return $results;
        } catch (Throwable $exception) {
            return [['level' => 'error', 'message' => 'Database bermasalah: '.$exception->getMessage()]];
        }
    }
}
