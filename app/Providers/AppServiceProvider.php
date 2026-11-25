<?php

namespace App\Providers;

use App\Services\WebPush;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\DevCommands;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

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
    }
}
