<?php

namespace App\Http\Controllers\Api;

use App\Models\SuresignNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class NotificationController extends Controller
{
    /**
     * GET /notifications
     *
     * Supported query parameters:
     *   filter    — active (default) | unread | read | dismissed | resolved | expired
     *               | critical | warning | reminder | info
     *               | commercial | contract | payment | variation | risk | deliverable
     *               | programme | compliance | notice | retention | general
     *   type      — comma-separated legacy type strings (backward compat)
     *   per_page  — 1-100 (default 25)
     *   page      — page number
     *
     * Default: active (excludes resolved and expired).
     */
    public function index(Request $request): JsonResponse
    {
        $userId  = $request->user()->id;
        $filter  = $request->query('filter', 'active');
        $type    = $request->query('type');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $query = SuresignNotification::where('user_id', $userId)
            ->orderByRaw("FIELD(priority, 'critical', 'warning', 'reminder', 'info') ASC, created_at DESC");

        // ── Status / priority / category filters ─────────────────────────────
        $priorityValues  = [SuresignNotification::PRIORITY_CRITICAL, SuresignNotification::PRIORITY_WARNING,
                             SuresignNotification::PRIORITY_REMINDER, SuresignNotification::PRIORITY_INFO];
        $categoryValues  = [SuresignNotification::CATEGORY_COMMERCIAL, SuresignNotification::CATEGORY_CONTRACT,
                             SuresignNotification::CATEGORY_PROGRAMME, SuresignNotification::CATEGORY_COMPLIANCE,
                             SuresignNotification::CATEGORY_PAYMENT, SuresignNotification::CATEGORY_VARIATION,
                             SuresignNotification::CATEGORY_RETENTION, SuresignNotification::CATEGORY_DELIVERABLE,
                             SuresignNotification::CATEGORY_NOTICE, SuresignNotification::CATEGORY_RISK,
                             SuresignNotification::CATEGORY_GENERAL];

        if (in_array($filter, $priorityValues)) {
            $query->where('priority', $filter)
                  ->whereNotIn('status', [SuresignNotification::STATUS_RESOLVED, SuresignNotification::STATUS_EXPIRED]);
        } elseif (in_array($filter, $categoryValues)) {
            $query->where('category', $filter)
                  ->whereNotIn('status', [SuresignNotification::STATUS_RESOLVED, SuresignNotification::STATUS_EXPIRED]);
        } else {
            match ($filter) {
                'unread'    => $query->where('status', SuresignNotification::STATUS_UNREAD),
                'read'      => $query->where('status', SuresignNotification::STATUS_READ),
                'dismissed' => $query->where('status', SuresignNotification::STATUS_DISMISSED),
                'resolved'  => $query->where('status', SuresignNotification::STATUS_RESOLVED),
                'expired'   => $query->where('status', SuresignNotification::STATUS_EXPIRED),
                // 'all' keeps no status restriction; 'active' is the default
                'all'       => null,
                default     => $query->whereNotIn('status', [   // 'active' default
                    SuresignNotification::STATUS_RESOLVED,
                    SuresignNotification::STATUS_EXPIRED,
                ]),
            };
        }

        // Legacy type filter (backward compat with old frontend code)
        if ($type) {
            $types = explode(',', $type);
            $query->whereIn('type', $types);
        }

        $unreadCount = SuresignNotification::where('user_id', $userId)
            ->where('status', SuresignNotification::STATUS_UNREAD)
            ->count();

        $paginated = $query->paginate($perPage);

        return response()->json([
            'data'         => $paginated->items(),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'unread_count' => $unreadCount,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'from'         => $paginated->firstItem(),
            'to'           => $paginated->lastItem(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = SuresignNotification::where('user_id', $request->user()->id)
            ->where('status', SuresignNotification::STATUS_UNREAD)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, SuresignNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $notification->markRead();

        return response()->json(['success' => true]);
    }

    public function dismiss(Request $request, SuresignNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $notification->dismiss();

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = SuresignNotification::where('user_id', $request->user()->id)
            ->where('status', SuresignNotification::STATUS_UNREAD)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'status'  => SuresignNotification::STATUS_READ,
            ]);

        return response()->json(['success' => true, 'count' => $count]);
    }

    public function clearRead(Request $request): JsonResponse
    {
        $count = SuresignNotification::where('user_id', $request->user()->id)
            ->where('status', SuresignNotification::STATUS_READ)
            ->delete();

        return response()->json(['success' => true, 'count' => $count]);
    }

    public function clearOne(Request $request, SuresignNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $notification->delete();

        return response()->json(['success' => true]);
    }

    public function clearSelected(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return response()->json(['message' => 'No IDs provided'], 422);
        }

        $count = SuresignNotification::where('user_id', $request->user()->id)
            ->whereIn('id', $ids)
            ->delete();

        return response()->json(['success' => true, 'count' => $count]);
    }
}
