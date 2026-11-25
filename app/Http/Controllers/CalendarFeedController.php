<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CalendarFeed;
use Illuminate\Http\Response;

/**
 * Feed iCal publik yang dilindungi token rahasia, untuk dilanggan dari aplikasi kalender.
 */
class CalendarFeedController extends Controller
{
    public function __invoke(string $token, CalendarFeed $feed): Response
    {
        $user = User::query()->where('calendar_token', $token)->firstOrFail();

        return response($feed->build($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="prima-management.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}
