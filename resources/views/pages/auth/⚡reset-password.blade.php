<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Atur ulang kata sandi')] class extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [], ['password' => 'kata sandi baru']);

        $status = Password::reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', 'Tautan tidak valid atau sudah kedaluwarsa. Minta tautan baru.');

            return;
        }

        session()->flash('status', 'Kata sandi diperbarui. Silakan masuk.');
        $this->redirectRoute('login', navigate: true);
    }
};
?>

<div>
    <div class="mb-8 text-center lg:text-left">
        <img src="/icons/icon-192.png" alt="" class="mx-auto mb-5 size-16 rounded-2xl shadow-xl shadow-brand/30 lg:hidden">
        <h1 class="text-3xl font-extrabold tracking-tight">Kata sandi baru</h1>
        <p class="mt-2 text-sm text-muted">Buat kata sandi baru minimal 8 karakter.</p>
    </div>

    <form wire:submit="resetPassword" class="card space-y-4 p-6">
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" type="email" wire:model="email" class="input" autocomplete="email" required>
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="password">Kata sandi baru</label>
            <input id="password" type="password" wire:model="password" class="input" autocomplete="new-password" required>
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="password_confirmation">Ulangi kata sandi</label>
            <input id="password_confirmation" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password" required>
        </div>
        <button class="btn btn-primary w-full" wire:loading.attr="disabled">Simpan kata sandi</button>
    </form>
</div>
