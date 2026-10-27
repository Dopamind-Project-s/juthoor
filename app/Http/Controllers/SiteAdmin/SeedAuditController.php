<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\Imports\SeedAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Read-only seed preflight and data-completeness report (the web view of genealogy:seed-audit). */
class SeedAuditController extends Controller
{
    public function __invoke(Request $request, SeedAuditService $audit): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $slug = $request->validate(['family' => ['nullable', 'alpha_dash', 'max:100']])['family'] ?? null;
        // Same guarantee as the command: whatever the report touches is rolled back.
        DB::beginTransaction();
        try {
            $report = $audit->report($slug);
        } finally {
            DB::rollBack();
        }

        return view('site-admin.seed-audit', ['report' => $report, 'families' => Family::query()->orderBy('name_ar')->get(['slug', 'name_ar'])]);
    }
}
