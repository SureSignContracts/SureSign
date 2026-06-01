<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayLessNotice;
use App\Models\Project;
use Illuminate\Http\Request;

class PayLessNoticeController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $notices = PayLessNotice::where('project_id', $project->id)
            ->with('creator:id,name')
            ->latest('notice_date')
            ->paginate(25);

        return response()->json($notices);
    }

    public function store(Request $request, Project $project)
    {
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

    public function show(PayLessNotice $payLessNotice)
    {
        return response()->json($payLessNotice->load('creator:id,name'));
    }

    public function update(Request $request, PayLessNotice $payLessNotice)
    {
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

    public function destroy(PayLessNotice $payLessNotice)
    {
        $payLessNotice->delete();
        return response()->json(null, 204);
    }
}
