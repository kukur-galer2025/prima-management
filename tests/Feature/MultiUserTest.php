<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MultiUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registration_and_email_verification_routes_are_gone(): void
    {
        $this->get('/daftar')->assertNotFound();
        $this->get('/verifikasi-email')->assertNotFound();
        $this->get(route('login'))->assertOk()->assertDontSee('Daftar gratis')->assertSee('Minta admin');
    }

    public function test_unverified_users_can_still_use_the_app(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get(route('dashboard'))->assertOk();
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

        $this->actingAs($owner)->get(route('attachments.show', $pdf))->assertOk()->assertDownload('soal.pdf')
            ->assertHeaderMissing('ETag')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
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
