<?php

use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\Reminders;
use App\Services\WebPush;
use Database\Seeders\StarterGoalsSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:create-user {--with-goals : Isi target awal}', function () {
    $name = text('Nama', required: true);
    $email = text('Email', required: true, validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Email tidak valid.');
    $secret = password('Kata sandi (min. 8 karakter)', required: true, validate: fn (string $value) => strlen($value) >= 8 ? null : 'Minimal 8 karakter.');

    $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => $secret]);
    $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

    if ($this->option('with-goals')) {
        (new StarterGoalsSeeder)->run($user);
    }

    $this->info("Akun {$email} siap dipakai.");
})->purpose('Buat / perbarui akun untuk login ke Prima Management');

Artisan::command('app:backup-database {--keep=14 : Jumlah backup terbaru yang disimpan}', function () {
    $connection = DB::connection();

    if ($connection->getDriverName() !== 'sqlite') {
        $this->error('Backup otomatis hanya mendukung SQLite. Untuk MySQL/PostgreSQL gunakan backup dari penyedia database.');

        return 1;
    }

    // VACUUM INTO menghasilkan salinan yang konsisten walau aplikasi sedang menulis.
    $temporary = storage_path('app/private/backup-'.Str::random(8).'.sqlite');
    $connection->statement('VACUUM INTO ?', [$temporary]);

    try {
        $name = 'prima-management-'.now()->format('Ymd-His').'.sqlite.gz';
        $disk = Storage::disk('backups');
        $disk->put($name, gzencode((string) file_get_contents($temporary), 9));
    } finally {
        @unlink($temporary);
    }

    collect($disk->files())
        ->filter(fn (string $file) => Str::startsWith($file, 'prima-management-') && Str::endsWith($file, ['.sqlite', '.sqlite.gz']))
        ->sortDesc()
        ->slice(max(1, (int) $this->option('keep')))
        ->each(fn (string $file) => $disk->delete($file));

    $this->info('Backup tersimpan: '.$disk->path($name));

    return 0;
})->purpose('Cadangkan database SQLite (terkompresi) ke disk "backups" (atur lewat BACKUP_PATH)');

Artisan::command('app:send-reminders', function (Reminders $reminders) {
    $count = $reminders->dispatch(now());

    $this->info("{$count} pengingat baru dikirim.");
})->purpose('Kirim notifikasi push untuk jadwal yang akan dimulai dan ringkasan harian');

Artisan::command('app:vapid-keys', function () {
    $keys = WebPush::generateKeys();

    $this->info('Salin baris berikut ke file .env, lalu jalankan: php artisan config:clear');
    $this->line('VAPID_PUBLIC_KEY='.$keys['public_key']);
    $this->line('VAPID_PRIVATE_KEY='.$keys['private_key']);
})->purpose('Buat pasangan kunci VAPID untuk notifikasi push');

Artisan::command('app:post-recurring-transactions', function () {
    $posted = User::query()
        ->whereHas('recurringTransactions', fn ($query) => $query->whereDate('next_run_on', '<=', today()))
        ->get()
        ->sum(fn (User $user) => RecurringTransaction::postDueFor($user));

    $this->info("{$posted} transaksi rutin dicatat.");
})->purpose('Catat transaksi rutin bulanan yang sudah jatuh tempo');

Schedule::command('app:send-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('app:post-recurring-transactions')->dailyAt('00:05');
Schedule::command('app:backup-database')->dailyAt('02:00');
