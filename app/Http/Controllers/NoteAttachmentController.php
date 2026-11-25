<?php

namespace App\Http\Controllers;

use App\Models\NoteAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh / pratinjau lampiran catatan. Hanya pemilik yang bisa mengakses; file disimpan di disk privat.
 */
class NoteAttachmentController extends Controller
{
    public function __invoke(Request $request, NoteAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->user_id === $request->user()->id, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);

        $inline = $request->boolean('inline') && $attachment->canPreview();
        $headers = [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            // Jangan disimpan di cache browser: di perangkat bersama, akun lain bisa membuka salinan cache-nya.
            'Cache-Control' => 'private, no-store, max-age=0',
        ];

        return $inline
            ? $disk->response($attachment->path, $attachment->original_name, $headers, 'inline')
            : $disk->download($attachment->path, $attachment->original_name, $headers);
    }
}
