<?php

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Lupa kata sandi')] class extends Component
{
    public string $email = '';

    public bool $sent = false;

    public function sendLink(): void
    {
        $this->validate(['email' => ['required', 'email']]);

        // Batasi per IP agar form ini tidak dipakai untuk membanjiri kotak masuk orang lain.
        $throttleKey = 'password-reset|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->addError('email', 'Terlalu banyak permintaan. Coba lagi dalam '.ceil(RateLimiter::availableIn($throttleKey) / 60).' menit.');

            return;
        }

        RateLimiter::hit($throttleKey, 900);

        try {
            $status = Password::sendResetLink(['email' => $this->email]);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('email', 'Email belum bisa dikirim. Coba lagi nanti atau hubungi admin.');

            return;
        }

        // Pesan sama untuk email terdaftar maupun tidak, agar tidak membocorkan daftar akun.
        if ($status === Password::RESET_THROTTLED) {
            $this->addError('email', 'Tunggu sebentar sebelum meminta tautan lagi.');

            return;
        }

        $this->sent = true;
    }
};
?>

<div>
    <div class="mb-8 text-center lg:text-left">
        <img src="/icons/icon-192.png" alt="" class="mx-auto mb-5 size-16 rounded-2xl shadow-xl shadow-brand/30 lg:hidden">
        <h1 class="text-3xl font-extrabold tracking-tight">Lupa kata sandi?</h1>
        <p class="mt-2 text-sm text-muted">Masukkan email akunmu. Kami kirim tautan untuk membuat kata sandi baru.</p>
    </div>

    @if ($sent)
        <div class="card space-y-3 p-6 text-center">
            <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-brand-soft text-3xl">📬</span>
            <p class="font-bold">Cek kotak masukmu</p>
            <p class="text-sm text-muted">Kalau <span class="font-semibold text-ink">{{ $email }}</span> terdaftar, tautan atur ulang sudah dikirim. Periksa juga folder spam.</p>
        </div>
    @else
        <form wire:submit="sendLink" class="card space-y-4 p-6">
            <div>
                <label class="label" for="email">Email</label>
                <input id="email" type="email" wire:model="email" class="input" autocomplete="email" autofocus required>
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <button class="btn btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendLink">Kirim tautan</span>
                <span wire:loading wire:target="sendLink">Mengirim…</span>
            </button>
        </form>
    @endif

    <p class="mt-6 text-center text-sm text-muted">
        Ingat kata sandimu? <a href="{{ route('login') }}" wire:navigate class="link">Masuk</a>
    </p>
</div>
