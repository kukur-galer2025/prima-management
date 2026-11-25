<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Telegram;
use App\Services\WebPush;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WebPush::class, fn () => new WebPush(
            config('services.webpush.public_key'),
            config('services.webpush.private_key'),
            config('services.webpush.subject'),
        ));

        $this->app->singleton(Telegram::class, fn () => new Telegram(
            config('services.telegram.bot_token'),
            config('services.telegram.bot_username'),
            config('services.telegram.webhook_secret'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pengingat & backup berjalan lewat scheduler, jadi ikut dinyalakan oleh `php artisan dev`.
        DevCommands::artisan('schedule:work', 'scheduler');

        ResetPassword::toMailUsing(fn (object $notifiable, string $token) => (new MailMessage)
            ->subject('Atur ulang kata sandi Prima Management')
            ->greeting('Halo, '.$notifiable->name.'!')
            ->line('Kami menerima permintaan untuk mengatur ulang kata sandi akunmu.')
            ->action('Atur ulang kata sandi', route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]))
            ->line('Tautan ini berlaku selama '.config('auth.passwords.users.expire').' menit.')
            ->line('Kalau kamu tidak meminta ini, abaikan saja email ini.')
            ->salutation('Salam, Prima Management'));

        Gate::define('admin', fn (User $user) => $user->is_admin);

        // /up gagal (500) bila database atau folder storage bermasalah; dipakai skrip deploy & pemantau uptime.
        Event::listen(function (DiagnosingHealth $event) {
            DB::table('users')->exists();

            if (! is_writable(storage_path('framework/views')) || ! is_writable(storage_path('logs'))) {
                throw new RuntimeException('Folder storage tidak bisa ditulis.');
            }
        });
    }
}
