<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductUpdate;
use Illuminate\Http\Request;

/**
 * "What's New in SureSign" — see App\Models\ProductUpdate's docblock.
 * Customer-facing endpoints (pending/history/dismiss) are available to any
 * authenticated user; management endpoints (index/store/update/destroy) are
 * Super Admin/Admin only — mirrors PlatformAnnouncementController's split
 * between its public `active()` and role-gated CRUD.
 */
class ProductUpdateController extends Controller
{
    // GET /product-updates/pending — any authenticated user. The set the
    // automatic modal shows: published, audience-matched, never dismissed
    // by this user, newest few only.
    public function pending(Request $request)
    {
        $updates = ProductUpdate::pendingFor($request->user());

        return response()->json(['data' => $updates->map(fn ($u) => $this->presentCustomerFacing($u))->values()]);
    }

    // GET /product-updates/history — any authenticated user. Manual
    // "View all updates" archive — published updates regardless of this
    // user's own dismissal state (dismissal controls automatic popup
    // behaviour only, never access to history — see spec).
    public function history(Request $request)
    {
        $isOperator = $request->user()->hasRole('Super Admin') || $request->user()->hasRole('Admin');

        $updates = ProductUpdate::published()
            ->forAudience($isOperator)
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $updates->map(fn ($u) => $this->presentCustomerFacing($u))->values()]);
    }

    // POST /product-updates/{productUpdate}/dismiss — "Don't show this
    // update again". Idempotent: dismissing an already-dismissed update is
    // a harmless no-op, not an error (a double-click or a retried request
    // must never fail).
    public function dismiss(Request $request, ProductUpdate $productUpdate)
    {
        $productUpdate->dismissals()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['dismissed_at' => now()],
        );

        return response()->json(['success' => true]);
    }

    // ── Super Admin / Admin management (role:Super Admin|Admin route group) ──

    public function index()
    {
        $updates = ProductUpdate::with(['creator', 'updater'])->latest('created_at')->limit(200)->get();

        return response()->json(['data' => $updates->map(fn ($u) => $this->presentInternal($u))]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        // Explicit, not left to the migration's DB-level default — otherwise
        // the in-memory model returned from create() (never re-fetched) would
        // report a null status until the next read.
        $validated['status'] = $validated['status'] ?? ProductUpdate::STATUS_DRAFT;
        $validated['audience'] = $validated['audience'] ?? ProductUpdate::AUDIENCE_CLIENT;

        if ($validated['status'] === ProductUpdate::STATUS_PUBLISHED) {
            $validated['published_at'] = now();
        }

        $update = ProductUpdate::create([
            ...$validated,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->presentInternal($update)], 201);
    }

    public function update(Request $request, ProductUpdate $productUpdate)
    {
        $validated = $this->validated($request, $productUpdate);

        // published_at is set the FIRST time an update goes live, and never
        // touched again — an ordinary edit (typo fix) or a later re-publish
        // after archiving must not reset it, which would both misorder
        // "newest first" and (per spec) must never re-trigger dismissed
        // users seeing it again. Identity/dismissals are unaffected either
        // way, since this is always the same row.
        if (($validated['status'] ?? $productUpdate->status) === ProductUpdate::STATUS_PUBLISHED
            && $productUpdate->published_at === null) {
            $validated['published_at'] = now();
        }

        $productUpdate->update([
            ...$validated,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->presentInternal($productUpdate->fresh())]);
    }

    public function destroy(ProductUpdate $productUpdate)
    {
        $productUpdate->delete();

        return response()->json(['success' => true]);
    }

    private function validated(Request $request, ?ProductUpdate $existing = null): array
    {
        return $request->validate([
            'title'      => 'required|string|max:150',
            'summary'    => 'required|string|max:500',
            'content'    => 'required|string',
            'category'   => 'required|string|in:'.implode(',', ProductUpdate::CATEGORIES),
            'cta_label'  => 'nullable|string|max:60',
            // Same safe-link shape as PlatformAnnouncementController's
            // link_url: an internal path (never a protocol-relative
            // "//host") or an https:// URL — never javascript:/data:.
            'cta_url'    => ['nullable', 'string', 'max:255', 'regex:/^(\/(?!\/)[a-zA-Z0-9\-\/_?=&%.]*|https:\/\/[a-zA-Z0-9\-\.]+(\/[a-zA-Z0-9\-\/_?=&%.]*)?)$/'],
            'audience'   => 'nullable|string|in:'.implode(',', ProductUpdate::AUDIENCES),
            'status'     => 'nullable|string|in:'.implode(',', ProductUpdate::STATUSES),
        ]);
    }

    /** Draft/execution-adjacent fields excluded categorically — see AiAnalysisPresenter's identical discipline. */
    private function presentCustomerFacing(ProductUpdate $update): array
    {
        return [
            'id'         => $update->id,
            'title'      => $update->title,
            'summary'    => $update->summary,
            'content'    => $update->content,
            'category'   => $update->category,
            'cta_label'  => $update->cta_label,
            'cta_url'    => $update->cta_url,
            'published_at' => $update->published_at,
        ];
    }

    private function presentInternal(ProductUpdate $update): array
    {
        return [
            'id'           => $update->id,
            'title'        => $update->title,
            'summary'      => $update->summary,
            'content'      => $update->content,
            'category'     => $update->category,
            'cta_label'    => $update->cta_label,
            'cta_url'      => $update->cta_url,
            'audience'     => $update->audience,
            'status'       => $update->status,
            'published_at' => $update->published_at,
            'created_by'   => $update->creator?->name,
            'updated_by'   => $update->updater?->name,
            'created_at'   => $update->created_at,
            'updated_at'   => $update->updated_at,
        ];
    }
}
