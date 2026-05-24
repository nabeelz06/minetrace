<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Company;
use App\Models\UserLocationRole;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * UserController (SuperAdmin) — kelola semua user lintas company.
 *
 * Arsitektur fleksibel:
 * - Saat company baru ditambah, user langsung bisa di-assign ke company tsb
 * - Role list dinamis dari database, tidak hardcoded
 * - Support untuk user multi-location (UserLocationRole)
 */
class UserController extends Controller
{
    /**
     * Daftar semua user dengan filter.
     */
    public function index(Request $request): Response
    {
        $query = User::with(['company', 'roles'])
            ->withTrashed()
            ->latest();

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'ILIKE', "%{$request->search}%")
                  ->orWhere('email', 'ILIKE', "%{$request->search}%")
                  ->orWhere('employee_id', 'ILIKE', "%{$request->search}%");
            });
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', fn($q) => $q->where('name', $request->role));
        }

        if ($request->filled('status')) {
            match($request->status) {
                'active'   => $query->where('is_active', true)->whereNull('deleted_at'),
                'inactive' => $query->where('is_active', false)->whereNull('deleted_at'),
                'deleted'  => $query->onlyTrashed(),
                default    => null,
            };
        }

        $users = $query->paginate(20)->withQueryString();

        // Map dengan role info
        $users->getCollection()->transform(function($user) {
            return [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'employee_id'  => $user->employee_id,
                'department'   => $user->department,
                'company'      => $user->company ? [
                    'id'   => $user->company->id,
                    'name' => $user->company->name,
                    'code' => $user->company->code,
                ] : null,
                'role'         => $user->roles->first()?->name,
                'is_active'    => $user->is_active,
                'is_deleted'   => !is_null($user->deleted_at),
                'language'     => $user->language,
                'last_login_at'=> $user->last_login_at?->diffForHumans(),
                'created_at'   => $user->created_at?->format('d M Y'),
                'avatar_url'   => $user->avatar_url,
            ];
        });

        $stats = [
            'total'    => User::count(),
            'active'   => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'deleted'  => User::onlyTrashed()->count(),
        ];

        return Inertia::render('SuperAdmin/Users/Index', [
            'users'     => $users,
            'stats'     => $stats,
            'companies' => Company::orderBy('name')->get(['id', 'name', 'code']),
            'roles'     => Role::orderBy('name')->pluck('name'),
            'filters'   => $request->only(['search', 'company_id', 'role', 'status']),
        ]);
    }

    /**
     * Form buat user baru.
     */
    public function create(): Response
    {
        return Inertia::render('SuperAdmin/Users/Create', [
            'companies' => Company::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'is_regulator']),
            'roles'     => Role::orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * Simpan user baru.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id'  => 'nullable|exists:companies,id',
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:users,email',
            'password'    => ['required', Password::min(8)->letters()->numbers()->symbols()],
            'role'        => 'required|in:super_admin,bim_admin,bim_viewer,government_auditor,company_admin,operator_lab,operator_process,company_auditor',
            'employee_id' => 'nullable|string|max:50',
            'department'  => 'nullable|string|max:100',
            'phone'       => 'nullable|string|max:20',
            'language'    => 'nullable|in:id,en',
            'is_active'   => 'boolean',
        ], [
            'email.unique'    => 'Email sudah terdaftar.',
            'password.min'    => 'Password minimal 8 karakter.',
            'role.required'   => 'Role wajib dipilih.',
        ]);

        return DB::transaction(function () use ($validated) {

            $user = User::create([
                'company_id'             => $validated['company_id'] ?? null,
                'name'                   => $validated['name'],
                'email'                  => $validated['email'],
                'password'               => Hash::make($validated['password']),
                'employee_id'            => $validated['employee_id'] ?? null,
                'department'             => $validated['department'] ?? null,
                'phone'                  => $validated['phone'] ?? null,
                'language'               => $validated['language'] ?? 'id',
                'is_active'              => $validated['is_active'] ?? true,
                'force_password_change'  => true,
            ]);

            // Assign role (tanpa team scope untuk simplicity)
            $user->syncRoles([$validated['role']]);

            return redirect()
                ->route('super-admin.users.index')
                ->with('success', "User {$user->name} berhasil dibuat.");
        });
    }

    /**
     * Form edit user.
     */
    public function edit(User $user): Response
    {
        $user->load(['company', 'roles', 'locationRoles.location']);

        return Inertia::render('SuperAdmin/Users/Edit', [
            'user'      => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'employee_id' => $user->employee_id,
                'department'  => $user->department,
                'phone'       => $user->phone,
                'language'    => $user->language,
                'is_active'   => $user->is_active,
                'company_id'  => $user->company_id,
                'role'        => $user->roles->first()?->name,
                'avatar_url'  => $user->avatar_url,
                'last_login_at' => $user->last_login_at?->format('d M Y H:i'),
            ],
            'companies' => Company::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'roles'     => Role::orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * Update user.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'company_id'  => 'nullable|exists:companies,id',
            'name'        => 'required|string|max:255',
            'email'       => "required|email|unique:users,email,{$user->id}",
            'password'    => ['nullable', Password::min(8)->letters()->numbers()->symbols()],
            'role'        => 'required|string|exists:roles,name',
            'employee_id' => 'nullable|string|max:50',
            'department'  => 'nullable|string|max:100',
            'phone'       => 'nullable|string|max:20',
            'language'    => 'nullable|in:id,en',
            'is_active'   => 'boolean',
        ]);

        return DB::transaction(function () use ($validated, $user) {

            $updateData = [
                'company_id'  => $validated['company_id'] ?? null,
                'name'        => $validated['name'],
                'email'       => $validated['email'],
                'employee_id' => $validated['employee_id'] ?? null,
                'department'  => $validated['department'] ?? null,
                'phone'       => $validated['phone'] ?? null,
                'language'    => $validated['language'] ?? 'id',
                'is_active'   => $validated['is_active'] ?? true,
            ];

            if (!empty($validated['password'])) {
                $updateData['password']             = Hash::make($validated['password']);
                $updateData['password_changed_at']  = now();
                $updateData['force_password_change'] = false;
            }

            $user->update($updateData);
            $user->syncRoles([$validated['role']]);

            return redirect()
                ->route('super-admin.users.index')
                ->with('success', "User {$user->name} berhasil diupdate.");
        });
    }

    /**
     * Soft delete user.
     */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->isSuperAdmin()) {
            return back()->withErrors(['user' => 'Super Admin tidak dapat dihapus.']);
        }

        $user->update(['is_active' => false]);
        $user->delete();

        return back()->with('success', "User {$user->name} berhasil dihapus.");
    }

    /**
     * Restore soft-deleted user.
     */
    public function restore(int $id): RedirectResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->restore();
        $user->update(['is_active' => true]);

        return back()->with('success', "User {$user->name} berhasil dipulihkan.");
    }

    /**
     * Toggle active status user.
     */
    public function toggleActive(User $user): RedirectResponse
    {
        $user->update(['is_active' => !$user->is_active]);
        $status = $user->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return back()->with('success', "User {$user->name} berhasil {$status}.");
    }
}