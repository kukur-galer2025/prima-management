<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\StudySession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_alert_emails_the_admin_once_per_error(): void
    {
        config(['app.error_alerts' => true, 'app.admin_email' => 'admin@example.com']);

        $error = new RuntimeException('Database meledak');
        report($error);
        report($error);
        report(new RuntimeException('Error lain'));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Database meledak', $messages[0]->getOriginalMessage()->getSubject());
        $this->assertSame('admin@example.com', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_error_alerts_are_off_without_configuration(): void
    {
        config(['app.error_alerts' => false, 'app.admin_email' => 'admin@example.com']);

        report(new RuntimeException('Diam saja'));

        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_goal_list_computes_pace_without_extra_queries_per_goal(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00'));
        $goal = Goal::factory()->create(['target_hours' => 20, 'deadline' => '2026-12-05', 'status' => 'active']);
        Goal::factory()->count(4)->for($goal->user)->create(['target_hours' => 50, 'deadline' => '2027-01-01', 'status' => 'active']);
        StudySession::factory()->for($goal)->create(['studied_on' => '2026-11-20', 'minutes' => 600]);

        $goals = $goal->user->goals()->withStats()->get();

        DB::enableQueryLog();
        $paces = $goals->map->pace();
        $this->assertCount(0, DB::getQueryLog());

        $this->assertSame(43, $paces->first()['recentPerDay']);
    }
}
