<?php

namespace App\Http\Controllers\Api;

use App\Models\SuresignNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId  = $request->user()->id;
        $filter  = $request->query('filter', 'all');
        $type    = $request->query('type');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $query = SuresignNotification::where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        if ($filter === 'unread') {
            $query->where('is_read', false);
        }

        if ($type) {
            $types = explode(',', $type);
            $query->whereIn('type', $types);
        }

        $unreadCount = SuresignNotification::where('user_id', $userId)
            ->where('is_read', false)
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
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, SuresignNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = SuresignNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['success' => true, 'count' => $count]);
    }

    public function clearRead(Request $request): JsonResponse
    {
        $count = SuresignNotification::where('user_id', $request->user()->id)
            ->where('is_read', true)
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
