<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Note;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_can_register_and_must_verify_email(): void
    {
        Notification::fake();

        $this->get(route('login'))->assertSee('Daftar gratis');
        $this->get(route('register'))->assertOk()->assertSee('Buat akun Prima Management');

        Livewire::test('pages::auth.register')
            ->set('name', 'Sinta')
            ->set('email', 'sinta@example.com')
            ->set('password', 'rahasia123')
            ->set('password_confirmation', 'rahasia123')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'sinta@example.com')->sole();
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmail::class, fn (VerifyEmail $notification) => $notification->toMail($user)->subject === 'Verifikasi email akun Prima Management');

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('verification.notice'))->assertOk()->assertSee('sinta@example.com');
    }

    public function test_verification_link_unlocks_the_app(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_tampered_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('lain@example.com')]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_registration_validates_and_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        Livewire::test('pages::auth.register')
            ->set('name', '')
            ->set('email', 'ada@example.com')
            ->set('password', 'pendek')
            ->set('password_confirmation', 'beda')
            ->call('register')
            ->assertHasErrors(['name', 'email', 'password']);

        $this->assertSame(1, User::query()->count());
    }

    public function test_registration_can_be_closed(): void
    {
        config(['app.registration_enabled' => false]);

        $this->get(route('register'))->assertNotFound();
        $this->get(route('login'))->assertDontSee('Daftar gratis');
    }

    public function test_users_only_see_their_own_data(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        Goal::factory()->for($alice)->create(['name' => 'Rahasia Alice']);
        Note::factory()->for($alice)->create(['title' => 'Catatan Alice']);

        $this->actingAs($bob);
        $this->get(route('goals.index'))->assertDontSee('Rahasia Alice');
        $this->get(route('notes'))->assertDontSee('Catatan Alice');
        $this->get(route('dashboard'))->assertDontSee('Rahasia Alice');
    }

    public function test_account_deletion_requires_password_and_removes_everything(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['password' => 'rahasia123']);
        $goal = Goal::factory()->for($user)->create();
        $note = Note::factory()->for($user)->create();
        Storage::disk('local')->put($user->attachmentDirectory().'/a.pdf', 'isi');
        $other = Goal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('pages::account')
            ->set('delete_password', 'salah')
            ->call('deleteAccount')
            ->assertHasErrors('delete_password');

        $this->assertModelExists($user);

        $component->set('delete_password', 'rahasia123')->call('deleteAccount')->assertRedirect(route('login'));

        $this->assertModelMissing($user);
        $this->assertModelMissing($goal);
        $this->assertModelMissing($note);
        $this->assertModelExists($other);
        $this->assertFalse(Storage::disk('local')->exists($user->attachmentDirectory().'/a.pdf'));
        $this->assertGuest();
    }

    public function test_attachments_are_private_and_validated(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();

        Livewire::actingAs($owner)
            ->test('pages::notes')
            ->call('openCreate')
            ->set('title', 'Materi TOEFL')
            ->set('uploads', [UploadedFile::fake()->create('soal.pdf', 200, 'application/pdf'), UploadedFile::fake()->image('papan.png')])
            ->call('save')
            ->assertHasNoErrors();

        $note = $owner->notes()->sole();
        $this->assertCount(2, $note->attachments);
        $pdf = $note->attachments->firstWhere('original_name', 'soal.pdf');
        Storage::disk('local')->assertExists($pdf->path);
        $this->assertStringStartsWith($owner->attachmentDirectory().'/', $pdf->path);

        $this->actingAs($owner)->get(route('attachments.show', $pdf))->assertOk()->assertDownload('soal.pdf');
        $this->actingAs(User::factory()->create())->get(route('attachments.show', $pdf))->assertNotFound();

        $note->delete();
        Storage::disk('local')->assertMissing($pdf->path);
        $this->assertDatabaseCount('note_attachments', 0);
    }

    public function test_dangerous_files_and_quota_overflow_are_rejected(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::notes')
            ->call('openCreate')
            ->set('title', 'X')
            ->set('uploads', [UploadedFile::fake()->createWithContent('jahat.html', '<script>alert(1)</script>')])
            ->call('save')
            ->assertHasErrors('uploads.0');

        config(['app.attachments.quota_mb' => 1]);

        Livewire::actingAs($user)
            ->test('pages::notes')
            ->call('openCreate')
            ->set('title', 'Besar')
            ->set('uploads', [UploadedFile::fake()->create('besar.pdf', 1500, 'application/pdf')])
            ->call('save')
            ->assertHasErrors('uploads');

        $this->assertDatabaseCount('notes', 0);
    }
}
