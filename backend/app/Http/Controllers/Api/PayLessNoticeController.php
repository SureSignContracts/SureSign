<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayLessNotice;
use App\Models\Project;
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

        return response()->json($notice, 201);
    }

    public function show(Request $request, PayLessNotice $payLessNotice)
    {
        $this->authorize($request, $payLessNotice);

        return response()->json($payLessNotice->load('creator:id,name'));
    }

    public function update(Request $request, PayLessNotice $payLessNotice)
    {
        $this->authorize($request, $payLessNotice);

        $validated = $request->validate([
            'notice_date' => 'sometimes|date',
            'amount'      => 'sometimes|numeric|min:0',
            'reason'      => 'nullable|string',
            'reference'   => 'nullable|string|max:100',
            'status'      => 'nullable|in:draft,issued',
        ]);

        $payLessNotice->update($validated);

        return response()->json($payLessNotice);
    }

    public function destroy(Request $request, PayLessNotice $payLessNotice)
    {
        $this->authorize($request, $payLessNotice);

        $payLessNotice->delete();
        return response()->json(null, 204);
    }
}
