<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Projects\ProjectPortfolioService;
use Illuminate\Http\Request;

/**
 * Global Projects — organisation-wide portfolio discovery, search,
 * filtering, and navigation. Read-only. Kept as a separate endpoint from
 * ProjectController::index() (which several other consumers already rely on
 * for its existing response shape — the Documents project picker, the
 * Prompt Library context modal, and tour launch logic) rather than changing
 * that endpoint's contract.
 */
class ProjectPortfolioController extends Controller
{
    public function __construct(private ProjectPortfolioService $portfolio) {}

    /**
     * GET /projects/portfolio
     */
    public function index(Request $request)
    {
        $params = $request->only(['search', 'status', 'attention', 'currency', 'sort', 'page', 'per_page']);

        return response()->json($this->portfolio->build($request->user(), $params));
    }
}
