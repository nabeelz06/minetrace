<?php

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Analytics\BimAnalyticsService;
use App\Services\Analytics\CompanyAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * ApiCompanyController — info company dan BIM overview untuk API.
 */
class ApiCompanyController extends Controller
{
    public function __construct(
        private CompanyAnalyticsService $companyAnalytics,
        private BimAnalyticsService     $bimAnalytics
    ) {}

    /**
     * GET /api/v1/company/info
     */
    public function info(): JsonResponse
    {
        $user    = Auth::user();
        $company = $user->company;

        return response()->json([
            'success' => true,
            'data'    => [
                'id'            => $company->id,
                'name'          => $company->name,
                'code'          => $company->code,
                'industry'      => $company->industry,
                'primary_color' => $company->primary_color,
                'kpi'           => $this->companyAnalytics->getKpiCards($company->id),
            ],
            'message' => 'OK',
        ]);
    }

    /**
     * GET /api/v1/bim/overview
     */
    public function overview(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->bimAnalytics->getSystemOverview(),
            'message' => 'OK',
        ]);
    }

    /**
     * GET /api/v1/bim/companies
     */
    public function list(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->bimAnalytics->getCompanyStatuses(),
            'message' => 'OK',
        ]);
    }
}