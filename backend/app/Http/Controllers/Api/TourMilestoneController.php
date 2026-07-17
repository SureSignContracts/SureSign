<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuresignNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class TourMilestoneController extends Controller
{
    private const MILESTONES = [
        'first_tour'               => [
            'type'    => NotificationService::TOUR_MILESTONE_FIRST,
            'title'   => 'First guided tour completed',
            'message' => 'Nice start — you completed your first guided tour in SureSign.',
        ],
        'getting_started_complete' => [
            'type'    => NotificationService::TOUR_MILESTONE_GETTING_STARTED,
            'title'   => 'Getting Started tours complete',
            'message' => 'You have completed every tour in Getting Started.',
        ],
        'all_tours_complete'       => [
            'type'    => NotificationService::TOUR_MILESTONE_ALL_COMPLETE,
            'title'   => 'All guided tours completed',
            'message' => 'You have completed every guided tour in SureSign.',
        ],
    ];

    // Tour completion itself is tracked client-side (no backend record of
    // individual tour progress exists), so this endpoint doesn't verify the
    // claimed milestone was actually reached — it only ever sends a personal,
    // one-off notification for one of three fixed, harmless milestone types.
    // Idempotency is enforced here (not just client-side) by checking for an
    // existing notification of that type for this user before creating one,
    // so a milestone can never notify twice even from a second device/browser.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'milestone' => 'required|string|in:' . implode(',', array_keys(self::MILESTONES)),
        ]);

        $user = $request->user();
        $config = self::MILESTONES[$validated['milestone']];

        $alreadySent = SuresignNotification::where('user_id', $user->id)
            ->where('type', $config['type'])
            ->exists();

        if (!$alreadySent) {
            NotificationService::send(
                $user,
                $config['type'],
                $config['title'],
                $config['message'],
                [],
                ['action_url' => '/app/help/tours']
            );
        }

        return response()->json(['status' => 'ok']);
    }
}
