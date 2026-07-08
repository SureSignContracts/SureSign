<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TradePackages\TradePackageCatalogueService;

class TradePackageCatalogueController extends Controller
{
    /**
     * GET /api/trade-packages/catalogue
     *
     * Read-only. Returns the standard trade package catalogue so the
     * frontend (and future imports/AI) don't hardcode their own copy.
     */
    public function index()
    {
        return response()->json([
            'packages'      => TradePackageCatalogueService::all(),
            'allow_custom'  => true,
        ]);
    }
}
