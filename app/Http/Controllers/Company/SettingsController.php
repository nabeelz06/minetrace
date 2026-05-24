<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\MassBalanceThreshold;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SettingsController — pengaturan company.
 *
 * CATATAN Laravel 11:
 * $this->middleware() di __construct() sudah deprecated.
 * Gunakan abort_unless() per method atau implementasikan HasMiddleware interface.
 * Di sini kita pakai abort_unless() untuk konsistensi dengan controller lain.
 */
class SettingsController extends Controller
{
    // ─── TIDAK ada __construct() dengan middleware ──────────────────────────
    // Validasi role dilakukan per-method menggunakan abort_unless()

    /**
     * Halaman settings utama.
     */
    public function index(): Response
    {
        // ← FIX: ganti $this->middleware() dengan abort_unless()
        abort_unless(
            Auth::user()->hasRole('company_admin'),
            403, 'Hanya Company Admin yang dapat mengakses pengaturan.'
        );

        $companyId = Auth::user()->company_id;

        $settings = CompanySetting::where('company_id', $companyId)
            ->get()
            ->groupBy('group')
            ->map(fn($group) => $group->map(fn($s) => [
                'key'         => $s->key,
                'value'       => $s->getCastedValue(),
                'label'       => $s->label,
                'description' => $s->description,
                'type'        => $s->type,
            ])->values());

        $thresholds = MassBalanceThreshold::where('company_id', $companyId)
            ->orderBy('process_stage')
            ->get()
            ->map(fn($t) => [
                'id'                     => $t->id,
                'process_stage'          => $t->process_stage,
                'stage_label'            => $t->stage_label,
                'warning_threshold_pct'  => $t->warning_threshold_pct,
                'critical_threshold_pct' => $t->critical_threshold_pct,
                'notes'                  => $t->notes,
            ]);

        $locations = Location::where('company_id', $companyId)
            ->get()
            ->map(fn($l) => [
                'id'                  => $l->id,
                'code'                => $l->code,
                'name'                => $l->name,
                'full_name'           => $l->full_name,
                'location_type'       => $l->location_type,
                'max_capacity_kg'     => $l->max_capacity_kg,
                'capacity_warning_pct'=> $l->capacity_warning_pct,
                'is_active'           => $l->is_active,
                'city'                => $l->city,
            ]);

        return Inertia::render('Company/Settings/Index', [
            'settings'   => $settings,
            'thresholds' => $thresholds,
            'locations'  => $locations,
            'company'    => Auth::user()->company->only(['id', 'name', 'code', 'primary_color', 'email', 'phone']),
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasRole('company_admin'), 403);

        $validated = $request->validate([
            'settings'         => 'required|array',
            'settings.*.key'   => 'required|string',
            'settings.*.value' => 'nullable',
        ]);

        $companyId = Auth::user()->company_id;

        foreach ($validated['settings'] as $setting) {
            CompanySetting::where('company_id', $companyId)
                ->where('key', $setting['key'])
                ->update(['value' => is_array($setting['value'])
                    ? json_encode($setting['value'])
                    : (string)$setting['value']
                ]);
        }

        return back()->with('success', 'Pengaturan umum berhasil disimpan.');
    }

    public function updateThresholds(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasRole('company_admin'), 403);

        $validated = $request->validate([
            'thresholds'                              => 'required|array',
            'thresholds.*.id'                         => 'required|exists:mass_balance_thresholds,id',
            'thresholds.*.warning_threshold_pct'      => 'required|numeric|min:0|max:100',
            'thresholds.*.critical_threshold_pct'     => 'required|numeric|min:0|max:100',
            'thresholds.*.notes'                      => 'nullable|string|max:500',
        ]);

        $companyId = Auth::user()->company_id;

        foreach ($validated['thresholds'] as $data) {
            if ($data['warning_threshold_pct'] >= $data['critical_threshold_pct']) {
                return back()->withErrors([
                    'thresholds' => "Warning threshold harus lebih kecil dari critical threshold."
                ]);
            }

            MassBalanceThreshold::where('company_id', $companyId)
                ->find($data['id'])
                ?->update([
                    'warning_threshold_pct'  => $data['warning_threshold_pct'],
                    'critical_threshold_pct' => $data['critical_threshold_pct'],
                    'notes'                  => $data['notes'] ?? null,
                ]);
        }

        return back()->with('success', 'Threshold mass balance berhasil diupdate.');
    }

    public function updateWarehouse(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasRole('company_admin'), 403);

        $validated = $request->validate([
            'locations'                            => 'required|array',
            'locations.*.id'                       => 'required|exists:locations,id',
            'locations.*.max_capacity_kg'          => 'nullable|numeric|min:0',
            'locations.*.capacity_warning_pct'     => 'nullable|numeric|min:0|max:100',
        ]);

        $companyId = Auth::user()->company_id;

        foreach ($validated['locations'] as $data) {
            Location::where('company_id', $companyId)->find($data['id'])
                ?->update([
                    'max_capacity_kg'      => $data['max_capacity_kg'] ?? null,
                    'capacity_warning_pct' => $data['capacity_warning_pct'] ?? 80,
                ]);
        }

        return back()->with('success', 'Kapasitas warehouse berhasil diupdate.');
    }
}