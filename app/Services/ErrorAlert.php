<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Pemantauan error sederhana tanpa layanan pihak ketiga: email ke pengelola
 * saat terjadi exception, maksimal sekali per 30 menit untuk error yang sama.
 */
class ErrorAlert
{
    public const THROTTLE_MINUTES = 30;

    public static function report(Throwable $exception): void
    {
        $recipient = config('app.admin_email');

        if (! config('app.error_alerts') || ! $recipient) {
            return;
        }

        $signature = 'error-alert:'.md5($exception::class.'|'.$exception->getFile().'|'.$exception->getLine());

        try {
            if (! Cache::add($signature, true, now()->addMinutes(self::THROTTLE_MINUTES))) {
                return;
            }

            $request = app()->runningInConsole() ? null : request();
            $body = implode("\n", array_filter([
                'Terjadi error di '.config('app.name').' ('.config('app.url').')',
                '',
                'Jenis  : '.$exception::class,
                'Pesan  : '.$exception->getMessage(),
                'Lokasi : '.$exception->getFile().':'.$exception->getLine(),
                'Waktu  : '.now()->toDateTimeString(),
                $request ? 'URL    : '.$request->method().' '.$request->fullUrl() : 'Konteks: perintah konsol / antrean',
                $request?->user() ? 'User   : #'.$request->user()->id : null,
                '',
                'Jejak (ringkas):',
                implode("\n", array_slice(explode("\n", $exception->getTraceAsString()), 0, 15)),
                '',
                'Error yang sama tidak akan dikirim lagi selama '.self::THROTTLE_MINUTES.' menit.',
            ], fn ($line) => ! is_null($line)));

            Mail::raw($body, fn ($message) => $message->to($recipient)->subject('[Error] '.config('app.name').': '.str($exception->getMessage())->limit(80)));
        } catch (Throwable) {
            // Pelaporan tidak boleh memicu error baru.
        }
    }
}
