<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private const ALLOWED_ROLES = ['Super Admin', 'Admin', 'Manager', 'Client', 'Viewer'];

    public function index(Request $request)
    {
        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $sort    = in_array($request->input('sort'), ['name', 'email', 'created_at', 'last_login_at'])
                   ? $request->input('sort') : 'created_at';
        $dir     = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = User::with('roles')->orderBy($sort, $dir);

        if ($search = $request->input('search')) {
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                                      ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($status = $request->input('status')) {
            if ($status === 'active')   $query->where('is_active', true);
            if ($status === 'disabled') $query->where('is_active', false);
        }

        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->transform(fn($u) => $this->formatUser($u));

        return response()->json($paginated);
    }

    public function invite(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255|unique:users,email',
            'role'  => 'required|string|in:' . implode(',', self::ALLOWED_ROLES),
        ]);

        $tempPassword = Str::upper(Str::random(4)) . rand(100, 999) . Str::random(4);

        $user = User::create([
            'name'      => explode('@', $validated['email'])[0],
            'email'     => $validated['email'],
            'password'  => Hash::make($tempPassword),
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(['name' => $validated['role'], 'guard_name' => 'web']);
        $user->assignRole($role);

        return response()->json([
            'message' => 'User created successfully.',
            'data'    => [
                'id'            => $user->id,
                'email'         => $user->email,
                'role'          => $validated['role'],
                'temp_password' => $tempPassword,
            ],
        ], 201);
    }

    public function show(string $id)
    {
        $user = User::with('roles')->findOrFail($id);
        return response()->json(['data' => $this->formatUser($user)]);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        if ((int) $id === Auth::id() && $request->has('role')) {
            return response()->json(['message' => 'You cannot change your own role.'], 422);
        }

        $validated = $request->validate([
            'role'      => 'sometimes|string|in:' . implode(',', self::ALLOWED_ROLES),
            'name'      => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['name']))      $user->name      = $validated['name'];
        if (isset($validated['is_active'])) $user->is_active = $validated['is_active'];
        $user->save();

        if (isset($validated['role'])) {
            $user->syncRoles([]);
            $role = Role::firstOrCreate(['name' => $validated['role'], 'guard_name' => 'web']);
            $user->assignRole($role);
        }

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    public function destroy(string $id)
    {
        if ((int) $id === Auth::id()) {
            return response()->json(['message' => 'You cannot remove your own account.'], 422);
        }

        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User removed.']);
    }

    private function formatUser(User $u): array
    {
        return [
            'id'            => $u->id,
            'name'          => $u->name,
            'email'         => $u->email,
            'roles'         => $u->roles->pluck('name'),
            'is_active'     => $u->is_active ?? true,
            'last_login_at' => $u->last_login_at,
            'created_at'    => $u->created_at,
        ];
    }
}
