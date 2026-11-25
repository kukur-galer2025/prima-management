<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Daftar')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        abort_unless(config('app.registration_enabled'), 404);
    }

    public function register(): void
    {
        abort_unless(config('app.registration_enabled'), 404);

        $throttleKey = 'register|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak pendaftaran dari jaringan ini. Coba lagi dalam '.ceil(RateLimiter::availableIn($throttleKey) / 60).' menit.',
            ]);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], ['email.unique' => 'Email ini sudah terdaftar. Silakan masuk.'], ['name' => 'nama', 'password' => 'kata sandi']);

        RateLimiter::hit($throttleKey, 3600);

        $user = User::query()->create($validated);

        // Memicu email verifikasi (User mengimplementasikan MustVerifyEmail).
        event(new Registered($user));

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('verification.notice', navigate: true);
    }
};
?>

<div>
    <div class="mb-8 text-center lg:text-left">
        <img src="/icons/icon-192.png" alt="" class="mx-auto mb-5 size-16 rounded-2xl shadow-xl shadow-brand/30 lg:hidden">
        <p class="text-sm font-bold text-brand">Gratis, tanpa iklan ✨</p>
        <h1 class="mt-1 text-3xl font-extrabold tracking-tight">Buat akun Prima Management</h1>
        <p class="mt-2 text-sm text-muted">Mulai atur target, waktu, kebiasaan, dan keuanganmu.</p>
    </div>

    <form wire:submit="register" class="card space-y-4 p-6">
        <div>
            <label class="label" for="name">Nama</label>
            <input id="name" wire:model="name" class="input" autocomplete="name" autofocus required>
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" type="email" wire:model="email" class="input" autocomplete="email" required>
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label" for="password">Kata sandi</label>
                <input id="password" type="password" wire:model="password" class="input" autocomplete="new-password" required>
            </div>
            <div>
                <label class="label" for="password_confirmation">Ulangi</label>
                <input id="password_confirmation" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password" required>
            </div>
        </div>
        @error('password') <p class="error">{{ $message }}</p> @enderror
        <p class="text-xs text-muted">Minimal 8 karakter. Kami akan mengirim email untuk memverifikasi alamatmu.</p>
        <button class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="register">Daftar</span>
            <span wire:loading wire:target="register">Membuat akun…</span>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        Sudah punya akun? <a href="{{ route('login') }}" wire:navigate class="link">Masuk</a>
    </p>
</div>
