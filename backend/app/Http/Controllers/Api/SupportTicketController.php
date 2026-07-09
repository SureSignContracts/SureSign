<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    // Any authenticated user can submit a ticket for their own organization.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        $user = $request->user();

        $ticket = SupportTicket::create([
            'organization_id' => $user->organization_id,
            'user_id'         => $user->id,
            'subject'         => $validated['subject'],
            'message'         => $validated['message'],
            'status'          => 'open',
        ]);

        return response()->json(['data' => $ticket], 201);
    }

    // Super Admin / Admin only — both are platform-wide roles that manage
    // every organization's tickets; organization_id is just an optional filter.
    public function index(Request $request)
    {
        $query = SupportTicket::with(['organization:id,name', 'user:id,name,email'])->latest();

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        $tickets = $query->get()->map(fn ($t) => [
            'id'         => $t->id,
            'subject'    => $t->subject,
            'message'    => $t->message,
            'status'     => $t->status,
            'company'    => $t->organization ? ['id' => $t->organization->id, 'name' => $t->organization->name] : null,
            'submitted_by' => $t->user?->name,
            'created_at' => $t->created_at,
        ]);

        return response()->json([
            'data'   => $tickets,
            'counts' => [
                'open'        => $tickets->where('status', 'open')->count(),
                'in_progress' => $tickets->where('status', 'in_progress')->count(),
                'resolved'    => $tickets->where('status', 'resolved')->count(),
                'total'       => $tickets->count(),
            ],
        ]);
    }

    // Super Admin / Admin only — both are platform-wide roles, so any ticket
    // from any organization may be updated.
    public function updateStatus(Request $request, string $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        $ticket->update([
            'status'      => $validated['status'],
            'resolved_at' => $validated['status'] === 'resolved' ? now() : $ticket->resolved_at,
        ]);

        return response()->json(['data' => $ticket->fresh()]);
    }
}
