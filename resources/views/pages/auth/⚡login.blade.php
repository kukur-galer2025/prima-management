<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Masuk')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Dua lapis: per email+IP (tebak kata sandi satu akun) dan per IP (coba banyak akun sekaligus).
        $throttleKey = Str::lower($this->email).'|'.request()->ip();
        $ipThrottleKey = 'login-ip|'.request()->ip();

        foreach ([[$throttleKey, 5], [$ipThrottleKey, 20]] as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    'email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
                ]);
            }
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($throttleKey);
            RateLimiter::hit($ipThrottleKey, 600);

            throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']);
        }

        if (Auth::user()->isDisabled()) {
            Auth::logout();

            throw ValidationException::withMessages(['email' => 'Akun ini dinonaktifkan. Hubungi admin untuk mengaktifkannya lagi.']);
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        $this->redirectIntended(route('dashboard'), navigate: true);
    }
};
?>

<div>
    <div class="mb-8 text-center lg:text-left">
        <img src="/icons/icon-192.png" alt="" class="mx-auto mb-5 size-16 rounded-2xl shadow-xl shadow-brand/30 lg:hidden">
        <p class="text-sm font-bold text-brand">Selamat datang kembali 👋</p>
        <h1 class="mt-1 text-3xl font-extrabold tracking-tight">Masuk ke Prima Management</h1>
        <p class="mt-2 text-sm text-muted">Kelola target, waktu, kebiasaan, dan keuanganmu di satu tempat.</p>
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-2xl bg-success/10 p-3 text-sm font-semibold text-success">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p class="mb-4 rounded-2xl bg-danger/10 p-3 text-sm font-semibold text-danger">{{ session('error') }}</p>
    @endif

    <form wire:submit="login" class="card space-y-4 p-6">
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" type="email" wire:model="email" class="input" autocomplete="email" autofocus required>
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <div class="flex items-center justify-between">
                <label class="label" for="password">Kata sandi</label>
                <a href="{{ route('password.request') }}" wire:navigate class="mb-1.5 text-xs font-bold text-brand hover:underline">Lupa kata sandi?</a>
            </div>
            <input id="password" type="password" wire:model="password" class="input" autocomplete="current-password" required>
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>
        <label class="flex items-center gap-2 text-sm text-muted">
            <input type="checkbox" wire:model="remember" class="size-4 accent-brand"> Ingat saya
        </label>
        <button class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Masuk</span>
            <span wire:loading wire:target="login">Memproses…</span>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">Belum punya akun? Minta admin untuk membuatkannya.</p>
</div>
