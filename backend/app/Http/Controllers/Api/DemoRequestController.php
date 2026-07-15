<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuresignSetting;
use App\Services\EmailNotificationService;
use Illuminate\Http\Request;

class DemoRequestController extends Controller
{
    // Public marketing-site form (suresigncontracts.app/book-a-demo) — no
    // organization context yet, so this notifies the platform admin address
    // directly rather than going through EmailNotificationService::send()'s
    // organization/event-toggle model, which assumes an existing org.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'company'       => 'required|string|max:255',
            'email'         => 'required|email|max:255',
            'phone'         => 'nullable|string|max:50',
            'project_count' => 'nullable|integer|min:0',
            'message'       => 'nullable|string|max:2000',
        ]);

        $adminEmail = SuresignSetting::instance()->admin_email;

        if ($adminEmail) {
            $body = collect([
                "Name: {$validated['name']}",
                "Company: {$validated['company']}",
                "Email: {$validated['email']}",
                $validated['phone'] ? "Phone: {$validated['phone']}" : null,
                isset($validated['project_count']) ? "Active projects: {$validated['project_count']}" : null,
                $validated['message'] ? "Message:\n{$validated['message']}" : null,
            ])->filter()->implode("\n");

            EmailNotificationService::sendDirect($adminEmail, 'New demo request — '.$validated['company'], $body);
        }

        return response()->json(['message' => 'Demo request received.'], 201);
    }
}
