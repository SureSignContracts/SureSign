<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayLessNotice;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Services\EmailNotificationService;
use App\Services\NotificationService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Http\Request;

class PayLessNoticeController extends Controller
{
    /**
     * Tenant isolation — mirrors FinalAccountController::authorizeProject.
     * Super Admin / Admin can cross organisations; everyone else must match.
     */
    private function authorize(Request $request, Project|PayLessNotice $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $notices = PayLessNotice::where('project_id', $project->id)
            ->with([
                'creator:id,name',
                'paymentApplication:id,application_number,amount_due,certified_amount,status,contract_id,trade_package_id',
                'paymentApplication.contract:id,title,reference_number',
                'paymentApplication.tradePackage:id,name,package_reference',
                'paymentNotice:id,reference,notice_date,notified_sum',
                'documents:id,documentable_type,documentable_id,file_name,file_size,created_at',
            ])
            ->latest('notice_date')
            ->paginate(25);

        return response()->json($notices);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'notice_date'    => 'required|date',
            'amount'         => 'required|numeric|min:0',
            'reason'         => 'nullable|string',
            'reference'      => 'nullable|string|max:100',
            'status'         => 'nullable|in:draft,issued',
        ]);

        $notice = PayLessNotice::create(array_merge($validated, [
            'project_id'     => $project->id,
            'created_by'     => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'status'         => $validated['status'] ?? 'draft',
        ]));

        if ($notice->status === 'issued') {
            $this->notifyIssued($request, $project, $notice);
        }

        return response()->json($notice, 201);
    }

    // Not shallow (api/projects/{project}/pay-less-notices/{pay_less_notice})
    // — both segments are typed model bindings, so Project $project must be
    // declared even though unused here, matching the same fix already
    // applied to MeetingMinutesController/SiteDiaryController/etc. Without
    // it, Laravel passed the {project} segment positionally into the
    // $payLessNotice argument slot, causing a TypeError (500) on every call.
    public function show(Request $request, Project $project, PayLessNotice $payLessNotice)
    {
        $this->authorize($request, $payLessNotice);

        return response()->json($payLessNotice->load('creator:id,name'));
    }

    public function update(Request $request, Project $project, PayLessNotice $payLessNotice)
    {
        $this->authorize($request, $payLessNotice);

        // Once issued, a Pay Less Notice is a formal statutory notice — its
        // amount/reason must not be editable after the fact.
        if ($payLessNotice->status === 'issued') {
            return response()->json(['message' => 'An issued Pay Less Notice cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'notice_date' => 'sometimes|date',
            'amount'      => 'sometimes|numeric|min:0',
            'reason'      => 'nullable|string',
            'reference'   => 'nullable|string|max:100',
            'status'      => 'nullable|in:draft,issued',
        ]);

        $wasIssued = $payLessNotice->status === 'issued';

        $payLessNotice->update($validated);

        if (!$wasIssued && $payLessNotice->status === 'issued') {
            $this->notifyIssued($request, $project, $payLessNotice);
        }

        return response()->json($payLessNotice);
    }

    private function notifyIssued(Request $request, Project $project, PayLessNotice $notice): void
    {
        NotificationService::sendToOrganization(
            $project->organization,
            'pay_less_notice_issued',
            'Pay Less Notice Issued',
            "A Pay Less Notice has been issued for project: {$project->name}.",
            [],
            [
                'project_id' => $project->id, 'organization_id' => $project->organization_id,
                'category' => SuresignNotification::CATEGORY_NOTICE, 'priority' => SuresignNotification::PRIORITY_WARNING,
                'source_type' => 'pay_less_notice', 'source_id' => $notice->id, 'source_field' => 'issued',
                'action_url' => WorkspaceNavigationResolver::actionUrl($project->id, 'pay_less_notice', $notice->id),
            ],
            $request->user(),
        );

        EmailNotificationService::send(
            'pay_less_notice.issued',
            'Pay Less Notice Issued',
            "A Pay Less Notice has been issued for project: {$project->name}.",
            [],
            $project->organization
        );
    }

    public function destroy(Request $request, Project $project, PayLessNotice $payLessNotice)
    {
        $this->authorize($request, $payLessNotice);

        if ($payLessNotice->status === 'issued') {
            return response()->json(['message' => 'An issued Pay Less Notice cannot be deleted.'], 422);
        }

        $payLessNotice->delete();
        return response()->json(null, 204);
    }
}
