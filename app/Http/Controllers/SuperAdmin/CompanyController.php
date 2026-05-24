<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\ExportLog;
use App\Services\Company\OnboardingService;   // ← FIX: was App\Services\OnboardingService
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CompanyController (SuperAdmin) — kelola company dalam sistem MINETRA.
 */
class CompanyController extends Controller
{
    public function __construct(
        private OnboardingService $onboardingService  // ← Now resolves correctly
    ) {}

    public function index(): Response
    {
        $companies = Company::withCount(['users', 'batches'])
            ->withTrashed()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id'                 => $c->id,
                'code'               => $c->code,
                'name'               => $c->name,
                'industry'           => $c->industry,
                'city'               => $c->city,
                'primary_color'      => $c->primary_color,
                'is_active'          => $c->is_active,
                'is_regulator'       => $c->is_regulator,
                'is_deleted'         => !is_null($c->deleted_at),
                'users_count'        => $c->users_count,
                'batches_count'      => $c->batches_count,
                'onboarded_at'       => $c->onboarded_at?->format('d M Y'),
                'is_fully_onboarded' => $c->isFullyOnboarded(),
            ]);

        $stats = [
            'total'      => Company::count(),
            'active'     => Company::where('is_active', true)->count(),
            'regulator'  => Company::where('is_regulator', true)->count(),
            'onboarding' => Company::where('is_active', true)->where('is_regulator', false)
                ->get()->filter(fn($c) => !$c->isFullyOnboarded())->count(),
        ];

        return Inertia::render('SuperAdmin/Companies/Index', [
            'companies' => $companies,
            'stats'     => $stats,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('SuperAdmin/Companies/Create', [
            'industry_options' => [
                'Mining - Tin & Rare Earth',
                'Mining - Nickel',
                'Mining - Bauxite',
                'Mining - Coal',
                'Mining - Gold',
                'Government - Mineral Regulator',
                'Government - Environmental Agency',
                'Processing - Smelter',
                'Processing - Refinery',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code'          => 'required|string|max:20|unique:companies,code|regex:/^[A-Z0-9_]+$/',
            'name'          => 'required|string|max:255',
            'legal_name'    => 'nullable|string|max:255',
            'industry'      => 'required|string|max:100',
            'primary_color' => 'nullable|string|max:20',
            'city'          => 'nullable|string|max:100',
            'province'      => 'nullable|string|max:100',
            'phone'         => 'nullable|string|max:50',
            'email'         => 'nullable|email|max:255',
            'website'       => 'nullable|url|max:255',
            'is_regulator'  => 'boolean',
            'template'      => 'nullable|in:tin_mining,nickel_mining,blank',
        ]);

        return DB::transaction(function () use ($validated) {
            $company = Company::create([
                'code'          => strtoupper($validated['code']),
                'name'          => $validated['name'],
                'legal_name'    => $validated['legal_name'] ?? null,
                'industry'      => $validated['industry'],
                'primary_color' => $validated['primary_color'] ?? '#3E5C74',
                'schema_name'   => strtolower($validated['code']),
                'city'          => $validated['city'] ?? null,
                'province'      => $validated['province'] ?? null,
                'phone'         => $validated['phone'] ?? null,
                'email'         => $validated['email'] ?? null,
                'website'       => $validated['website'] ?? null,
                'is_active'     => true,
                'is_regulator'  => $validated['is_regulator'] ?? false,
                'onboarded_at'  => now(),
                'settings'      => json_encode(['timezone' => 'Asia/Jakarta', 'default_locale' => 'id']),
            ]);

            $template = $validated['template'] ?? 'blank';
            if ($template !== 'blank' && !($validated['is_regulator'] ?? false)) {
                $this->onboardingService->setupFromTemplate($company, $template);
            }

            return redirect()
                ->route('super-admin.companies.show', $company)
                ->with('success', "Company {$company->name} berhasil dibuat.");
        });
    }

    public function show(Company $company): Response
    {
        $company->load(['users.roles', 'locations', 'productCodes']);

        $stats = [
            'users'         => $company->users()->count(),
            'batches'       => Batch::where('company_id', $company->id)->count(),
            'locations'     => $company->locations()->count(),
            'product_codes' => $company->productCodes()->count(),
            'exports'       => ExportLog::where('company_id', $company->id)->count(),
        ];

        $settings = CompanySetting::where('company_id', $company->id)
            ->get()
            ->groupBy('group')
            ->map(fn($group) => $group->map(fn($s) => [
                'key'   => $s->key,
                'value' => $s->getCastedValue(),
                'label' => $s->label,
                'type'  => $s->type,
            ]));

        return Inertia::render('SuperAdmin/Companies/Show', [
            'company'       => [
                'id'                 => $company->id,
                'code'               => $company->code,
                'name'               => $company->name,
                'legal_name'         => $company->legal_name,
                'industry'           => $company->industry,
                'primary_color'      => $company->primary_color,
                'city'               => $company->city,
                'province'           => $company->province,
                'phone'              => $company->phone,
                'email'              => $company->email,
                'website'            => $company->website,
                'is_active'          => $company->is_active,
                'is_regulator'       => $company->is_regulator,
                'onboarded_at'       => $company->onboarded_at?->format('d M Y'),
                'is_fully_onboarded' => $company->isFullyOnboarded(),
                'settings'           => $settings,
            ],
            'stats'         => $stats,
            'users'         => $company->users->map(fn($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'role'      => $u->roles->first()?->name,
                'is_active' => $u->is_active,
            ]),
            'locations'     => $company->locations,
            'product_codes' => $company->productCodes,
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'legal_name'    => 'nullable|string|max:255',
            'industry'      => 'required|string|max:100',
            'primary_color' => 'nullable|string|max:20',
            'city'          => 'nullable|string|max:100',
            'province'      => 'nullable|string|max:100',
            'phone'         => 'nullable|string|max:50',
            'email'         => 'nullable|email|max:255',
            'website'       => 'nullable|url|max:255',
            'is_active'     => 'boolean',
        ]);
        $company->update($validated);
        return back()->with('success', 'Company berhasil diupdate.');
    }

    public function toggleActive(Company $company): RedirectResponse
    {
        if ($company->is_regulator) {
            return back()->withErrors(['company' => 'Company regulator tidak dapat dinonaktifkan.']);
        }
        $company->update(['is_active' => !$company->is_active]);
        $status = $company->is_active ? 'diaktifkan' : 'dinonaktifkan';
        return back()->with('success', "Company {$company->name} berhasil {$status}.");
    }
}