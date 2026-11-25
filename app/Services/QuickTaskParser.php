<?php

namespace App\Services;

use App\Models\Goal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mengurai kalimat bahasa Indonesia menjadi tugas, tanpa AI dan tanpa library tambahan.
 *
 * Contoh: "Bayar UKT besok jam 10 !penting #toefl tiap minggu"
 * → judul "Bayar UKT", tenggat besok 10:00, prioritas tinggi, target TOEFL, berulang mingguan.
 *
 * Dikenali: hari ini/besok/lusa, N hari lagi, minggu/bulan depan, nama hari (+ "depan"),
 * "5 des", "tgl 5", "5/12", jam/pukul (+ pagi/siang/sore/malam), "19.30",
 * !penting / !rendah, #namatarget, tiap hari/minggu/bulan.
 */
class QuickTaskParser
{
    private const MONTHS = [
        'januari' => 1, 'jan' => 1, 'februari' => 2, 'feb' => 2, 'maret' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4,
        'mei' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7, 'agustus' => 8, 'agu' => 8, 'agt' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9, 'oktober' => 10, 'okt' => 10, 'november' => 11, 'nov' => 11,
        'desember' => 12, 'des' => 12,
    ];

    private const DAYS = ['senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, "jum'at" => 5, 'sabtu' => 6, 'minggu' => 7, 'ahad' => 7];

    private const RECURRENCES = ['hari' => 'daily', 'harian' => 'daily', 'minggu' => 'weekly', 'mingguan' => 'weekly', 'bulan' => 'monthly', 'bulanan' => 'monthly'];

    /** Akhir sebuah kata: spasi atau tanda baca ringan. */
    private const END = '(?=[\s,;.!?])';

    private string $rest = '';

    /**
     * @param  Collection<int, Goal>  $goals  target milik pengguna yang boleh dipilih lewat #nama
     * @return array{title: string, due_date: ?string, due_time: ?string, priority: ?int, recurrence: ?string, goal_id: ?int, goal_label: ?string, detected: bool}
     */
    public function parse(string $text, Collection $goals, CarbonInterface $now): array
    {
        $now = CarbonImmutable::parse($now);
        $today = $now->startOfDay();
        $this->rest = ' '.trim($text).' ';

        $recurrence = null;
        $priority = null;
        $goal = null;
        $time = null;
        $date = null;

        $dayPattern = implode('|', array_map(fn (string $day) => preg_quote($day, '/'), array_keys(self::DAYS)));

        // "tiap senin" = berulang mingguan, mulai Senin berikutnya. "tiap minggu" berarti mingguan,
        // jadi hari Minggu harus ditulis "tiap hari minggu".
        $weekdayPattern = implode('|', array_map(fn (string $day) => preg_quote($day, '/'), array_diff(array_keys(self::DAYS), ['minggu'])));

        $this->take('/(?<=\s)(?:setiap|tiap)\s+(?:hari\s+('.$dayPattern.')|('.$weekdayPattern.'))'.self::END.'/iu', function (array $m) use (&$recurrence, &$date, $today) {
            $recurrence = 'weekly';
            $date = $this->nextWeekday($today, self::DAYS[mb_strtolower($m[1] ?: $m[2])], false);
        }) || $this->take('/(?<=\s)(?:setiap|tiap)\s+(hari|minggu|bulan)'.self::END.'/iu', function (array $m) use (&$recurrence) {
            $recurrence = self::RECURRENCES[mb_strtolower($m[1])];
        }) || $this->take('/(?<=\s)(harian|mingguan|bulanan)'.self::END.'/iu', function (array $m) use (&$recurrence) {
            $recurrence = self::RECURRENCES[mb_strtolower($m[1])];
        });

        $this->take('/(?<=\s)!(penting|tinggi|urgent|rendah)?'.self::END.'/iu', function (array $m) use (&$priority) {
            $priority = mb_strtolower($m[1] ?? '') === 'rendah' ? 3 : 1;
        });

        $this->take('/(?<=\s)#([\p{L}\p{N}_-]+)'.self::END.'/u', function (array $m) use ($goals, &$goal) {
            $goal = $this->findGoal($m[1], $goals);

            return $goal ? null : $m[1];
        });

        $this->take('/(?<=\s)(?:jam|pukul|pkl\.?)\s*(\d{1,2})(?:[.:](\d{2}))?(?:\s+(pagi|siang|sore|malam))?'.self::END.'/iu', function (array $m) use (&$time) {
            return ($time = $this->time((int) $m[1], (int) ($m[2] ?? 0), $m[3] ?? null)) ? null : false;
        }) || $this->take('/(?<=\s)(\d{1,2})[.:](\d{2})(?:\s+(pagi|siang|sore|malam))?'.self::END.'/iu', function (array $m) use (&$time) {
            return ($time = $this->time((int) $m[1], (int) $m[2], $m[3] ?? null)) ? null : false;
        });

        $monthPattern = implode('|', array_keys(self::MONTHS));

        $dateRules = $date ? [] : [
            '/(?<=\s)hari\s+ini'.self::END.'/iu' => fn () => $today,
            '/(?<=\s)besok'.self::END.'/iu' => fn () => $today->addDay(),
            '/(?<=\s)lusa'.self::END.'/iu' => fn () => $today->addDays(2),
            '/(?<=\s)(?:dalam\s+)?(\d{1,3})\s+hari\s+lagi'.self::END.'/iu' => fn (array $m) => $today->addDays((int) $m[1]),
            '/(?<=\s)dalam\s+(\d{1,3})\s+hari'.self::END.'/iu' => fn (array $m) => $today->addDays((int) $m[1]),
            '/(?<=\s)minggu\s+depan'.self::END.'/iu' => fn () => $today->addWeek(),
            '/(?<=\s)bulan\s+depan'.self::END.'/iu' => fn () => $today->addMonthNoOverflow(),
            '/(?<=\s)(?:(?:tgl\.?|tanggal)\s*)?(\d{1,2})\s+('.$monthPattern.')(?:\s+(\d{4}))?'.self::END.'/iu' => fn (array $m) => $this->calendarDate($today, (int) $m[1], self::MONTHS[mb_strtolower($m[2])], isset($m[3]) ? (int) $m[3] : null),
            '/(?<=\s)(?:tgl\.?|tanggal)\s*(\d{1,2})'.self::END.'/iu' => fn (array $m) => $this->dayOfMonth($today, (int) $m[1]),
            '/(?<=\s)(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?'.self::END.'/u' => fn (array $m) => $this->calendarDate($today, (int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null),
            '/(?<=\s)(?:hari\s+)?('.$dayPattern.')(?:\s+(depan))?(?!\s+(?:ini|lalu|kemarin))'.self::END.'/iu' => fn (array $m) => $this->nextWeekday($today, self::DAYS[mb_strtolower($m[1])], ! empty($m[2])),
        ];

        foreach ($dateRules as $pattern => $resolve) {
            if ($this->take($pattern, function (array $m) use ($resolve, &$date) {
                return ($date = $resolve($m)) ? null : false;
            })) {
                break;
            }
        }

        // Jam tanpa tanggal: hari ini kalau jamnya belum lewat, kalau sudah lewat besok.
        if ($time && ! $date) {
            $date = $time > $now->format('H:i') ? $today : $today->addDay();
        }

        $title = trim(preg_replace('/\s+/u', ' ', $this->rest), " \t\n,;-");

        return [
            'title' => $title !== '' ? $title : trim($text),
            'due_date' => $date?->toDateString(),
            'due_time' => $time,
            'priority' => $priority,
            'recurrence' => $recurrence,
            'goal_id' => $goal?->id,
            'goal_label' => $goal?->label(),
            'detected' => (bool) ($date || $time || $priority || $recurrence || $goal),
        ];
    }

    /**
     * Cari pola sekali; bila $handle mengembalikan string, teks itu yang disisakan di judul,
     * bila false kecocokan dibatalkan (teks tetap utuh).
     */
    private function take(string $pattern, callable $handle): bool
    {
        $matched = false;

        $this->rest = preg_replace_callback($pattern, function (array $m) use ($handle, &$matched) {
            $result = $handle($m);

            if ($result === false) {
                return $m[0];
            }

            $matched = true;

            return is_string($result) ? $result : ' ';
        }, $this->rest, 1);

        return $matched;
    }

    private function time(int $hour, int $minute, ?string $period): ?string
    {
        $period = $period ? mb_strtolower($period) : null;

        $hour = match (true) {
            $period === 'pagi' && $hour === 12 => 0,
            $period === 'siang' && $hour >= 1 && $hour <= 4 => $hour + 12,
            in_array($period, ['sore', 'malam'], true) && $hour >= 1 && $hour < 12 => $hour + 12,
            $period === 'malam' && $hour === 12 => 0,
            default => $hour,
        };

        return $hour <= 23 && $minute <= 59 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    /** Tanggal tanpa tahun yang sudah lewat dianggap tahun depan. */
    private function calendarDate(CarbonImmutable $today, int $day, int $month, ?int $year): ?CarbonImmutable
    {
        if ($year !== null && $year < 100) {
            $year += 2000;
        }

        $candidateYear = $year ?? $today->year;

        if (! checkdate($month, $day, $candidateYear)) {
            return null;
        }

        $date = CarbonImmutable::create($candidateYear, $month, $day)->startOfDay();

        return $year === null && $date->lt($today) && checkdate($month, $day, $candidateYear + 1)
            ? $date->addYear()
            : $date;
    }

    /** "tgl 5": bulan ini kalau belum lewat, kalau sudah lewat bulan depan. */
    private function dayOfMonth(CarbonImmutable $today, int $day): ?CarbonImmutable
    {
        foreach ([$today, $today->startOfMonth()->addMonth()] as $month) {
            if (checkdate($month->month, $day, $month->year)) {
                $date = CarbonImmutable::create($month->year, $month->month, $day)->startOfDay();
                if ($date->gte($today)) {
                    return $date;
                }
            }
        }

        return null;
    }

    /** Hari bernama berikutnya (bukan hari ini). "depan" = pada minggu depan. */
    private function nextWeekday(CarbonImmutable $today, int $weekday, bool $nextWeek): CarbonImmutable
    {
        $date = $today->addDays((($weekday - $today->dayOfWeekIso + 7) % 7) ?: 7);

        if ($nextWeek && $date->lt($today->startOfWeek(1)->addWeek())) {
            $date = $date->addWeek();
        }

        return $date;
    }

    /**
     * @param  Collection<int, Goal>  $goals
     */
    private function findGoal(string $token, Collection $goals): ?Goal
    {
        $normalize = fn (string $value) => preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value)));
        $needle = $normalize($token);

        if ($needle === '') {
            return null;
        }

        return $goals->first(fn (Goal $goal) => $normalize($goal->name) === $needle)
            ?? $goals->first(fn (Goal $goal) => str_starts_with($normalize($goal->name), $needle))
            ?? $goals->first(fn (Goal $goal) => str_contains($normalize($goal->name), $needle));
    }
}
