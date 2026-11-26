<?php

use App\Models\RecurringTransaction;
use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\Preflight;
use App\Services\Reminders;
use App\Services\Telegram;
use App\Services\WebPush;
use Database\Seeders\StarterGoalsSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:create-user {--with-goals : Isi target awal} {--admin : Jadikan admin (bisa mengelola pengguna di /admin)}', function () {
    $name = text('Nama', required: true);
    $email = text('Email', required: true, validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Email tidak valid.');
    $secret = password('Kata sandi (min. 8 karakter)', required: true, validate: fn (string $value) => strlen($value) >= 8 ? null : 'Minimal 8 karakter.');

    $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => $secret]);
    $user->forceFill([
        'email_verified_at' => $user->email_verified_at ?? now(),
        'is_admin' => $user->is_admin || $this->option('admin'),
        'disabled_at' => null,
    ])->save();

    if ($this->option('with-goals')) {
        (new StarterGoalsSeeder)->run($user);
    }

    $this->info("Akun {$email} siap dipakai.");
})->purpose('Buat / perbarui akun untuk login ke Prima Management');

Artisan::command('app:backup-database {--keep=14 : Jumlah backup terbaru yang disimpan}', function (DatabaseBackup $backup) {
    try {
        $name = $backup->create((int) $this->option('keep'));
    } catch (Throwable $exception) {
        report($exception);
        $this->error($exception->getMessage());

        return 1;
    }

    $this->info('Backup tersimpan: '.Storage::disk('backups')->path($name));

    return 0;
})->purpose('Cadangkan database (SQLite/MySQL, terkompresi) ke disk "backups" (atur lewat BACKUP_PATH)');

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

Artisan::command('app:telegram-webhook', function (Telegram $telegram) {
    if (! $telegram->isConfigured()) {
        $this->error('Isi TELEGRAM_BOT_TOKEN dan TELEGRAM_BOT_USERNAME di .env terlebih dulu.');

        return 1;
    }

    $url = route('telegram.webhook');

    if (! str_starts_with($url, 'https://')) {
        $this->error("Telegram hanya menerima webhook https. APP_URL sekarang: {$url}. Untuk lokal, pakai: php artisan app:telegram-poll");

        return 1;
    }

    if (blank(config('services.telegram.webhook_secret'))) {
        $this->error('Isi TELEGRAM_WEBHOOK_SECRET (string acak, mis. hasil: php -r "echo bin2hex(random_bytes(16));").');

        return 1;
    }

    if (! $telegram->setWebhook($url)) {
        $this->error('Telegram menolak webhook. Periksa token bot.');

        return 1;
    }

    $telegram->registerCommands();
    $this->info("Webhook terdaftar: {$url}. Menu perintah bot juga sudah diperbarui.");

    return 0;
})->purpose('Daftarkan webhook bot Telegram (butuh APP_URL https)');

Artisan::command('app:telegram-poll {--loop : Terus berjalan sampai dihentikan}', function (Telegram $telegram) {
    if (! $telegram->isConfigured()) {
        $this->error('Isi TELEGRAM_BOT_TOKEN dan TELEGRAM_BOT_USERNAME di .env terlebih dulu.');

        return 1;
    }

    $this->info('Mendengarkan pesan bot Telegram… (Ctrl+C untuk berhenti)');

    do {
        $offset = (int) Cache::get('telegram.update_offset', 0);

        foreach ($telegram->getUpdates($offset) as $update) {
            $telegram->handleUpdate($update);
            Cache::forever('telegram.update_offset', (int) $update['update_id'] + 1);
            $this->line('Pesan diproses dari chat '.data_get($update, 'message.chat.id'));
        }

        if ($this->option('loop')) {
            sleep(3);
        }
    } while ($this->option('loop'));

    return 0;
})->purpose('Ambil pesan bot Telegram lewat polling (untuk lokal tanpa https)');

Artisan::command('app:warm-up', function () {
    $pages = resource_path('views/pages');
    $names = collect(File::allFiles($pages))
        ->map(fn ($file) => str_replace(['\\', '/', '⚡', '.blade.php'], ['/', '.', '', ''], $file->getRelativePathname()))
        ->sort()->values();

    // Memuat kelas tiap halaman: komponen Livewire dikompilasi sekarang, bukan saat pengunjung pertama datang.
    $names->each(fn (string $name) => app('livewire.factory')->resolveComponentClass('pages::'.$name));

    $compiled = storage_path('framework/views');
    collect(File::glob($compiled.'/livewire/views/*.blade.php'))->each(fn (string $view) => app('blade.compiler')->compile($view));

    // Kesalahan sintaks di view baru terlihat saat halaman dibuka; diperiksa di sini supaya deploy berhenti lebih dulu.
    foreach (File::glob($compiled.'/*.php') as $file) {
        try {
            token_get_all((string) file_get_contents($file), TOKEN_PARSE);
        } catch (ParseError $error) {
            $this->error('View rusak: '.$error->getMessage().' ('.basename($file).')');

            return 1;
        }
    }

    $this->info($names->count().' halaman dikompilasi.');

    return 0;
})->purpose('Kompilasi semua halaman sebelum rilis diaktifkan (dipakai deploy.sh)');

Artisan::command('app:preflight', function (Preflight $preflight) {
    $results = collect($preflight->run());
    $labels = ['ok' => '<info>  OK  </info>', 'warning' => '<comment> INFO </comment>', 'error' => '<error>GAGAL </error>'];

    $results->each(fn (array $result) => $this->line($labels[$result['level']].' '.$result['message']));

    $errors = $results->where('level', 'error')->count();
    $this->newLine();
    $errors ? $this->error("{$errors} masalah harus diperbaiki sebelum deploy.") : $this->info('Siap untuk produksi.');

    return $errors ? 1 : 0;
})->purpose('Periksa kesiapan produksi sebelum deploy');

// Tanda scheduler hidup, dibaca app:preflight.
Schedule::call(fn () => Cache::forever('scheduler.heartbeat', now()->timestamp))->everyMinute()->name('scheduler-heartbeat');

Schedule::command('app:send-reminders')->everyMinute()->withoutOverlapping();

// Tanpa alamat https publik webhook tidak bisa dipakai, jadi pesan bot diambil lewat polling.
Schedule::command('app:telegram-poll')->everyMinute()->withoutOverlapping()
    ->when(fn () => app(Telegram::class)->isConfigured() && ! str_starts_with((string) config('app.url'), 'https://'));
Schedule::command('app:post-recurring-transactions')->dailyAt('00:05');
Schedule::command('app:backup-database')->dailyAt('02:00');
