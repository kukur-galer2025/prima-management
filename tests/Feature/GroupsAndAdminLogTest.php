<?php

namespace Tests\Feature;

use App\Models\AdminActivity;
use App\Models\Goal;
use App\Models\Group;
use App\Models\StudySession;
use App\Models\User;
use App\Services\Reminders;
use App\Services\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GroupsAndAdminLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creates_group_and_friend_joins_with_code(): void
    {
        $owner = User::factory()->create(['name' => 'Ayu']);
        $friend = User::factory()->create(['name' => 'Bima']);

        Livewire::actingAs($owner)->test('pages::groups')
            ->set('newGroupName', 'Pejuang TOEFL')
            ->call('createGroup')
            ->assertHasNoErrors();

        $group = Group::query()->sole();
        $this->assertTrue($group->isOwnedBy($owner));
        $this->assertSame(8, strlen($group->invite_code));

        Livewire::actingAs($friend)->test('pages::groups')
            ->set('joinCode', 'SALAH123')
            ->call('joinGroup')
            ->assertHasErrors('joinCode')
            ->set('joinCode', strtolower($group->invite_code))
            ->call('joinGroup')
            ->assertHasNoErrors()
            ->assertSee('Pejuang TOEFL')
            ->assertSee('Ayu');

        $this->assertSame(2, $group->memberships()->count());
    }

    public function test_leaderboard_ranks_weekly_focus_and_hides_private_details(): void
    {
        $this->travelTo('2026-11-25 10:00');
        $ayu = User::factory()->create(['name' => 'Ayu']);
        $bima = User::factory()->create(['name' => 'Bima']);
        $group = Group::createFor($ayu, 'Kelompok');
        $group->memberships()->create(['user_id' => $bima->id]);

        $goal = Goal::factory()->for($bima)->create(['name' => 'Rahasia Bima']);
        StudySession::factory()->for($goal)->create(['user_id' => $bima->id, 'studied_on' => '2026-11-24', 'minutes' => 120, 'notes' => 'catatan pribadi']);
        StudySession::factory()->for($goal)->create(['user_id' => $bima->id, 'studied_on' => '2026-11-25', 'minutes' => 30]);
        StudySession::factory()->for($goal)->create(['user_id' => $bima->id, 'studied_on' => '2026-11-20', 'minutes' => 500]);

        $board = $group->leaderboard();
        $this->assertSame('Bima', $board[0]['user']->name);
        $this->assertSame(150, $board[0]['minutes'], 'Hanya menit sejak Senin minggu ini');
        $this->assertSame(2, $board[0]['activeDays']);
        $this->assertSame(2, $board[0]['streak']);

        $this->actingAs($ayu)->get(route('groups'))
            ->assertOk()
            ->assertSee('2j 30m')
            ->assertDontSee('Rahasia Bima')
            ->assertDontSee('catatan pribadi');

        // Setelah Bima mengizinkan, nama targetnya terlihat.
        Livewire::actingAs($bima)->test('pages::groups')->call('toggleSharing');
        $this->actingAs($ayu)->get(route('groups'))->assertSee('Rahasia Bima');
    }

    public function test_cheer_is_delivered_once_per_day(): void
    {
        config(['services.telegram.bot_token' => '1:A', 'services.telegram.bot_username' => 'Bot']);
        $this->app->forgetInstance(Telegram::class);
        $this->app->forgetInstance(Reminders::class);
        Http::fake(fn () => Http::response(['ok' => true]));

        $ayu = User::factory()->create(['name' => 'Ayu']);
        $bima = User::factory()->create(['telegram_chat_id' => '42']);
        $group = Group::createFor($ayu, 'Kelompok');
        $group->memberships()->create(['user_id' => $bima->id]);

        Livewire::actingAs($ayu)->test('pages::groups')
            ->call('cheer', $bima->id)
            ->assertDispatched('toast', message: 'Semangat terkirim ke '.$bima->name.' 👏')
            ->call('cheer', $bima->id);

        $this->assertSame(1, $bima->reminders()->count());
        $this->assertStringContainsString('Ayu menyemangatimu', $bima->reminders()->sole()->title);
        Http::assertSentCount(1);
    }

    public function test_non_members_cannot_see_or_act_on_a_group(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Group::createFor($owner, 'Tertutup');

        Livewire::actingAs($outsider)->test('pages::groups', ['group' => $group->id])
            ->assertDontSee('Tertutup')
            ->call('selectGroup', $group->id)
            ->assertNotFound();

        Livewire::actingAs($outsider)->test('pages::groups')->call('cheer', $owner->id)->assertNotFound();
    }

    public function test_only_owner_manages_and_ownership_moves_when_owner_leaves(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::createFor($owner, 'Kelompok');
        $group->memberships()->create(['user_id' => $member->id]);

        Livewire::actingAs($member)->test('pages::groups')->call('regenerateCode')->assertForbidden();

        Livewire::actingAs($owner)->test('pages::groups')->call('leaveGroup');

        $this->assertTrue($group->fresh()->isOwnedBy($member));

        Livewire::actingAs($member)->test('pages::groups')->call('leaveGroup');
        $this->assertModelMissing($group);
    }

    public function test_deleting_an_owner_account_hands_the_group_over(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::createFor($owner, 'Kelompok');
        $group->memberships()->create(['user_id' => $member->id]);

        $owner->deleteAccount();

        $this->assertTrue($group->fresh()->isOwnedBy($member));
    }

    public function test_admin_actions_are_logged(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Bos']);
        $user = User::factory()->create(['name' => 'Sinta', 'email' => 'sinta@example.com']);

        Livewire::actingAs($admin)->test('pages::admin')
            ->call('openEdit', $user->id)
            ->set('name', 'Sinta R')
            ->set('password', 'sandibaru123')
            ->set('is_admin', true)
            ->call('save')
            ->call('toggleDisabled', $user->id)
            ->call('deleteUser', $user->id)
            ->assertSee('Aktivitas admin')
            ->assertSee('menghapus akun');

        $actions = AdminActivity::query()->orderBy('id')->pluck('action')->all();
        $this->assertSame(['updated', 'promoted', 'password_changed', 'disabled', 'deleted'], $actions);
        $this->assertSame(['fields' => ['name']], AdminActivity::query()->where('action', 'updated')->sole()->details);

        // Jejak tetap terbaca walau akunnya sudah dihapus.
        $deleted = AdminActivity::query()->where('action', 'deleted')->sole();
        $this->assertNull($deleted->subject_id);
        $this->assertSame('Sinta R <sinta@example.com>', $deleted->subject_label);
        $this->assertSame('Bos', $deleted->actor_label);
    }
}
