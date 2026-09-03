<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FridayPackDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Automated Friday Pack, V1F — the public, no-account, signed-link PDF
 * download. Sits behind Laravel's `signed` middleware (routes/api.php),
 * keyed on FridayPackDelivery's own opaque public_token — never the
 * numeric id, and never the raw /documents/{id}/download route (that
 * route requires auth:sanctum and is never reachable by an external
 * recipient).
 *
 * Read-only, single action. A token that resolves to nothing usable
 * (unknown token, or a delivery whose linked Document has since been
 * soft-deleted/has no file on disk) all return the same generic 404 —
 * never a distinct response that would confirm a token is real but
 * "broken" versus genuinely unknown.
 */
class PublicFridayPackDeliveryController extends Controller
{
    public function download(Request $request, string $token)
    {
        $delivery = FridayPackDelivery::where('public_token', $token)->first();

        if (!$delivery) {
            abort(404, 'This link is invalid or has expired.');
        }

        $document = $delivery->document;
        if (!$document || $document->trashed() || !$document->file_path || !Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'This link is invalid or has expired.');
        }

        $fileName = $document->file_name ?? ($document->title . '.pdf');

        return Storage::disk('local')->download($document->file_path, $fileName, [
            'Content-Type' => $document->mime_type ?? 'application/pdf',
        ]);
    }
}
