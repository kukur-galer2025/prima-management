<?php

use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\NoteAttachmentController;
use App\Http\Controllers\OfflineSessionSyncController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'pages::auth.login')->name('login');
    Route::livewire('/daftar', 'pages::auth.register')->name('register');
    Route::livewire('/lupa-sandi', 'pages::auth.forgot-password')->name('password.request');
    Route::livewire('/reset-sandi/{token}', 'pages::auth.reset-password')->name('password.reset');
});

// Tautan langganan kalender (iCal); dilindungi token rahasia, bukan sesi login.
Route::get('/kalender/feed/{token}.ics', CalendarFeedController::class)
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:60,1')
    ->name('calendar.feed');

// Bisa diakses sebelum email diverifikasi.
Route::middleware('auth')->group(function () {
    Route::livewire('/verifikasi-email', 'pages::auth.verify-email')->name('verification.notice');

    Route::get('/verifikasi-email/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('dashboard')->with('status', 'Email terverifikasi. Selamat datang!');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/', 'pages::dashboard')->name('dashboard');
    Route::livewire('/target', 'pages::goals.index')->name('goals.index');
    Route::livewire('/target/{goal}', 'pages::goals.show')->name('goals.show');
    Route::livewire('/fokus', 'pages::study')->name('study');
    Route::redirect('/belajar', '/fokus');
    Route::livewire('/jadwal', 'pages::schedule')->name('schedule');
    Route::livewire('/tugas', 'pages::tasks')->name('tasks');
    Route::livewire('/kebiasaan', 'pages::habits')->name('habits');
    Route::livewire('/keuangan', 'pages::finance')->name('finance');
    Route::livewire('/catatan', 'pages::notes')->name('notes');
    Route::livewire('/statistik', 'pages::stats')->name('stats');
    Route::livewire('/kalender', 'pages::calendar')->name('calendar');
    Route::livewire('/review', 'pages::review')->name('review');
    Route::livewire('/akun', 'pages::account')->name('account');
    Route::livewire('/lainnya', 'pages::menu')->name('menu');

    Route::get('/lampiran/{attachment}', NoteAttachmentController::class)->middleware('throttle:120,1')->name('attachments.show');

    Route::post('/fokus/sinkron', OfflineSessionSyncController::class)->middleware('throttle:30,1')->name('study.sync');

    // Dipanggil service worker saat push masuk untuk mengambil isi notifikasi.
    Route::get('/pengingat/terbaru', function (Request $request) {
        return $request->user()->reminders()
            ->where('created_at', '>=', now()->subMinutes(15))
            ->latest()
            ->limit(5)
            ->get(['key', 'title', 'body', 'url']);
    })->middleware('throttle:60,1')->name('reminders.latest');
});
