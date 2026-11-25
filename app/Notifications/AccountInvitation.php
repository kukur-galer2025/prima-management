<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email undangan untuk akun yang dibuat admin: berisi tautan untuk membuat kata sandi sendiri.
 */
class AccountInvitation extends Notification
{
    public function __construct(public string $token, public string $invitedBy) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Akun Prima Management kamu sudah siap')
            ->greeting('Halo, '.$notifiable->name.'!')
            ->line($this->invitedBy.' membuatkan akun Prima Management untukmu dengan email '.$notifiable->email.'.')
            ->line('Klik tombol di bawah untuk membuat kata sandi, lalu masuk.')
            ->action('Buat kata sandi', route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line('Tautan ini berlaku selama '.config('auth.passwords.users.expire').' menit. Kalau sudah kedaluwarsa, pakai "Lupa kata sandi?" di halaman masuk.')
            ->salutation('Salam, Prima Management');
    }
}
