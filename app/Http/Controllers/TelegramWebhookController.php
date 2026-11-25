<?php

namespace App\Http\Controllers;

use App\Services\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    /**
     * Terima update dari Telegram. Keaslian dicek lewat header secret token yang didaftarkan saat setWebhook.
     */
    public function __invoke(Request $request, Telegram $telegram): JsonResponse
    {
        abort_unless($telegram->isValidWebhookSecret($request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $telegram->handleUpdate($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
