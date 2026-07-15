<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentNotice;
use App\Models\Project;
use Illuminate\Http\Request;

class PaymentNoticeController extends Controller
{
    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    // GET /projects/{project}/payment-notices
    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $notices = PaymentNotice::where('project_id', $project->id)
            ->with([
                'creator:id,name',
                'paymentApplication:id,application_number,amount_due,certified_amount,status,contract_id,trade_package_id',
                'paymentApplication.contract:id,title,reference_number',
                'paymentApplication.tradePackage:id,name,package_reference',
                'documents:id,documentable_type,documentable_id,title,file_name,file_size,created_at',
            ])
            ->latest('notice_date')
            ->paginate(25);

        return response()->json($notices);
    }

    // GET /payment-notices/{paymentNotice}
    public function show(Request $request, PaymentNotice $paymentNotice)
    {
        $project = $paymentNotice->project;
        $this->authorizeProject($request, $project);

        return response()->json(
            $paymentNotice->load([
                'creator:id,name',
                'paymentApplication.contract',
                'paymentApplication.tradePackage',
                'documents:id,documentable_type,documentable_id,title,file_name,file_size,created_at',
            ])
        );
    }

    // DELETE /payment-notices/{paymentNotice}
    public function destroy(Request $request, PaymentNotice $paymentNotice)
    {
        $project = $paymentNotice->project;
        $this->authorizeProject($request, $project);

        // A Payment Notice is a formal statutory notice the moment it exists
        // (the only creation path — PaymentApplicationController::createPaymentNotice
        // — always sets status='issued') — it must never be silently deleted.
        if ($paymentNotice->status === 'issued') {
            return response()->json(['message' => 'An issued Payment Notice cannot be deleted.'], 422);
        }

        $paymentNotice->delete();
        return response()->json(null, 204);
    }
}
