<?php

namespace App\Traits;

use App\Models\SupportTicket;
use App\Models\User;

/**
 * Shared by every controller that touches a single support ticket
 * (SupportTicketController, SupportTicketMessageController) so the access
 * rule is defined exactly once: the ticket's own submitter, or a platform
 * operator (Super Admin/Admin) — nobody else, not even a same-organization
 * Client. Never duplicate this conditional in a controller directly.
 */
trait AuthorizesSupportTickets
{
    protected function isSupportOperator(User $user): bool
    {
        return $user->hasRole('Super Admin') || $user->hasRole('Admin');
    }

    protected function authorizeTicketAccess(User $user, SupportTicket $ticket): void
    {
        if ($user->id !== $ticket->user_id && !$this->isSupportOperator($user)) {
            abort(403, 'Access denied.');
        }
    }
}
