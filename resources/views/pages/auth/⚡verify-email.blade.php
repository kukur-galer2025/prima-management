<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Verifikasi email')] class extends Component
{
    public bool $resent = false;

    public function mount(): void
    {
        if (auth()->user()->hasVerifiedEmail()) {
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    public function resend(): void
    {
        $user = auth()->user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        $throttleKey = 'verify-email|'.$user->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $this->addError('resend', 'Tunggu '.RateLimiter::availableIn($throttleKey).' detik sebelum mengirim ulang.');

            return;
        }

        RateLimiter::hit($throttleKey, 300);
        $user->sendEmailVerificationNotification();
        $this->resent = true;
    }

    public function logout(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirectRoute('login', navigate: true);
    }
};
?>

<div>
    <div class="card space-y-4 p-6 text-center">
        <span class="mx-auto grid size-16 place-items-center rounded-2xl bg-brand-soft text-4xl">📧</span>
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight">Cek email kamu</h1>
            <p class="mt-2 text-sm text-muted">
                Kami mengirim tautan verifikasi ke <span class="font-bold text-ink">{{ auth()->user()->email }}</span>.
                Klik tautan itu untuk mulai memakai Prima Management.
            </p>
        </div>

        @if ($resent)
            <p class="rounded-2xl bg-success/10 p-3 text-sm font-semibold text-success">Tautan baru sudah dikirim.</p>
        @endif
        @error('resend') <p class="error">{{ $message }}</p> @enderror

        <button class="btn btn-primary w-full" wire:click="resend" wire:loading.attr="disabled">Kirim ulang tautan</button>
        <p class="text-xs text-muted">Tidak ada di kotak masuk? Periksa folder spam atau promosi.</p>
    </div>

    <p class="mt-6 text-center text-sm text-muted">
        Salah email? <button type="button" wire:click="logout" class="link">Keluar dan daftar ulang</button>
    </p>
</div>
