<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformAnnouncement;
use Illuminate\Http\Request;

class PlatformAnnouncementController extends Controller
{
    // GET /platform-announcements/active — any authenticated user (Client
    // included); returns the single currently-active banner, or null. Title/
    // message are returned as plain strings — the frontend renders them as
    // text, never raw HTML, so there's no injection surface either side.
    public function active()
    {
        $announcement = PlatformAnnouncement::currentlyActive()->latest('starts_at')->first();

        return response()->json(['data' => $announcement ? $this->present($announcement) : null]);
    }

    // Super Admin / Admin only — both are platform-wide operator roles in
    // this codebase (see project-admin-org-scoping-gap memory / CLAUDE.md),
    // matching every other platform-wide admin endpoint's authorization.
    public function index()
    {
        // Defensive bound, not real pagination — announcements are admin-
        // authored and rare in practice, but an unbounded query is still an
        // unbounded query.
        $announcements = PlatformAnnouncement::latest('starts_at')->limit(200)->get()->map(fn ($a) => $this->present($a));

        return response()->json(['data' => $announcements]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $announcement = PlatformAnnouncement::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($announcement)], 201);
    }

    public function update(Request $request, PlatformAnnouncement $platformAnnouncement)
    {
        $validated = $this->validated($request, $platformAnnouncement);

        $platformAnnouncement->update($validated);

        return response()->json(['data' => $this->present($platformAnnouncement->fresh())]);
    }

    public function destroy(PlatformAnnouncement $platformAnnouncement)
    {
        $platformAnnouncement->delete();

        return response()->json(['success' => true]);
    }

    private function validated(Request $request, ?PlatformAnnouncement $existing = null): array
    {
        return $request->validate([
            'title'      => 'required|string|max:150',
            'message'    => 'required|string|max:1000',
            'severity'   => 'required|string|in:'.implode(',', PlatformAnnouncement::SEVERITIES),
            'is_active'  => 'nullable|boolean',
            'starts_at'  => 'required|date',
            'ends_at'    => 'nullable|date|after:starts_at',
            // Only an internal path or an https:// link — never javascript:/data:
            // URIs, and never a protocol-relative "//host" URL either: the
            // leading-slash branch below requires a *single* slash
            // (negative lookahead on a second one) specifically because
            // "//evil.com" would otherwise match it, get treated as an
            // internal path by the frontend's `startsWith('/')` check, and
            // silently navigate off-platform without the external-link
            // rel="noopener noreferrer" treatment.
            'link_url'   => ['nullable', 'string', 'max:255', 'regex:/^(\/(?!\/)[a-zA-Z0-9\-\/_?=&%.]*|https:\/\/[a-zA-Z0-9\-\.]+(\/[a-zA-Z0-9\-\/_?=&%.]*)?)$/'],
        ]);
    }

    private function present(PlatformAnnouncement $announcement): array
    {
        return [
            'id'         => $announcement->id,
            'title'      => $announcement->title,
            'message'    => $announcement->message,
            'severity'   => $announcement->severity,
            'is_active'  => $announcement->is_active,
            'starts_at'  => $announcement->starts_at,
            'ends_at'    => $announcement->ends_at,
            'link_url'   => $announcement->link_url,
            'created_at' => $announcement->created_at,
        ];
    }
}
