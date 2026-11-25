<?php

namespace App\Services;

use App\Models\ScheduleSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonTimeZone;

/**
 * Feed iCalendar (RFC 5545) berisi deadline target, tugas, dan jadwal fokus mingguan,
 * untuk dilanggan dari Google Calendar / Apple Calendar.
 */
class CalendarFeed
{
    private const DAY_CODES = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

    public function build(User $user): string
    {
        $timezone = config('app.timezone');
        $stamp = CarbonImmutable::now('UTC')->format('Ymd\THis\Z');
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'prima-management';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Prima Management//ID',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape(config('app.name')),
            'X-WR-TIMEZONE:'.$timezone,
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
            ...$this->timezoneBlock($timezone),
        ];

        foreach ($user->goals()->where('status', '!=', 'done')->whereNotNull('deadline')->get() as $goal) {
            $lines = [...$lines, ...$this->event(
                "goal-{$goal->id}@{$host}",
                $stamp,
                ['DTSTART;VALUE=DATE:'.$goal->deadline->format('Ymd'), 'DTEND;VALUE=DATE:'.$goal->deadline->copy()->addDay()->format('Ymd')],
                '🎯 Deadline: '.$goal->name,
                $goal->description,
            )];
        }

        foreach ($user->tasks()->with('goal')->where('is_done', false)->whereNotNull('due_date')->get() as $task) {
            $start = $task->due_time
                ? CarbonImmutable::parse($task->due_date->toDateString().' '.$task->due_time, $timezone)
                : null;

            $lines = [...$lines, ...$this->event(
                "task-{$task->id}@{$host}",
                $stamp,
                $start
                    ? ["DTSTART;TZID={$timezone}:".$start->format('Ymd\THis'), "DTEND;TZID={$timezone}:".$start->addMinutes(30)->format('Ymd\THis')]
                    : ['DTSTART;VALUE=DATE:'.$task->due_date->format('Ymd'), 'DTEND;VALUE=DATE:'.$task->due_date->copy()->addDay()->format('Ymd')],
                '✅ '.$task->title,
                trim(($task->goal ? 'Target: '.$task->goal->name."\n" : '').($task->notes ?? '')) ?: null,
            )];
        }

        $weekStart = CarbonImmutable::today($timezone)->startOfWeek(1);

        foreach ($user->scheduleSlots()->with('goal')->get() as $slot) {
            /** @var ScheduleSlot $slot */
            $date = $weekStart->addDays($slot->day_of_week - 1)->toDateString();
            $start = CarbonImmutable::parse("{$date} {$slot->start_time}", $timezone);
            $end = CarbonImmutable::parse("{$date} {$slot->end_time}", $timezone);

            $lines = [...$lines, ...$this->event(
                "slot-{$slot->id}@{$host}",
                $stamp,
                [
                    "DTSTART;TZID={$timezone}:".$start->format('Ymd\THis'),
                    "DTEND;TZID={$timezone}:".$end->format('Ymd\THis'),
                    'RRULE:FREQ=WEEKLY;BYDAY='.self::DAY_CODES[$slot->day_of_week],
                ],
                '⏱ Fokus: '.$slot->goal->name,
                $slot->notes,
            )];
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /**
     * @param  array<int, string>  $timing
     * @return array<int, string>
     */
    private function event(string $uid, string $stamp, array $timing, string $summary, ?string $description): array
    {
        return array_values(array_filter([
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$stamp,
            ...$timing,
            'SUMMARY:'.$this->escape($summary),
            $description ? 'DESCRIPTION:'.$this->escape($description) : null,
            'END:VEVENT',
        ]));
    }

    /**
     * Definisi zona waktu dengan offset saat ini. Cukup akurat untuk zona tanpa DST seperti WIB/WITA/WIT.
     *
     * @return array<int, string>
     */
    private function timezoneBlock(string $timezone): array
    {
        $offset = (new CarbonTimeZone($timezone))->toOffsetName();
        $offset = str_replace(':', '', $offset);

        return [
            'BEGIN:VTIMEZONE',
            'TZID:'.$timezone,
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:'.$offset,
            'TZOFFSETTO:'.$offset,
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** Baris iCal maksimal 75 byte; lanjutan diawali spasi. Karakter multibyte tidak dipotong. */
    private function fold(string $line): string
    {
        $folded = '';
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $folded .= $current."\r\n ";
                $current = '';
                $limit = 74;
            }

            $current .= $char;
        }

        return $folded.$current;
    }
}
