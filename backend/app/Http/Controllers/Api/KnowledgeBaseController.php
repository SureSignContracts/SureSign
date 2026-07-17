<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KnowledgeBaseService;
use Illuminate\Http\Request;

class KnowledgeBaseController extends Controller
{
    // GET /knowledge-base?q=...&route=/app/projects/1/commercial
    public function index(Request $request)
    {
        $request->validate([
            'q'     => 'nullable|string|max:100',
            'route' => 'nullable|string|max:255',
        ]);

        return response()->json([
            'data' => KnowledgeBaseService::search($request->string('q')->toString() ?: null),
            'suggested_categories' => $request->filled('route')
                ? KnowledgeBaseService::categoriesForRoute($request->string('route')->toString())
                : [],
        ]);
    }
}
