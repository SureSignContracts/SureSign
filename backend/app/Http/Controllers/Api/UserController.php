<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private const ALLOWED_ROLES = ['Admin', 'Client'];

    /**
     * Display a listing of all users (Admin/Super Admin only).
     */
    public function index(Request $request)
    {
        $users = User::with('roles')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'roles'      => $u->roles->pluck('name'),
                'created_at' => $u->created_at,
            ]);

        return response()->json(['data' => $users]);
    }

    /**
     * Invite a new user — creates account + sends password-reset email.
     */
    public function invite(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255|unique:users,email',
            'role'  => 'required|string|in:' . implode(',', self::ALLOWED_ROLES),
        ]);

        // Generate a readable temporary password
        $tempPassword = Str::upper(Str::random(4)) . rand(100, 999) . Str::random(4);

        // Create user with the temporary password
        $user = User::create([
            'name'     => explode('@', $validated['email'])[0],
            'email'    => $validated['email'],
            'password' => Hash::make($tempPassword),
        ]);

        // Ensure the role exists (create if missing) and assign it
        $role = Role::firstOrCreate(['name' => $validated['role'], 'guard_name' => 'web']);
        $user->assignRole($role);

        return response()->json([
            'message' => 'User created successfully.',
            'data'    => [
                'id'           => $user->id,
                'email'        => $user->email,
                'role'         => $validated['role'],
                'temp_password' => $tempPassword,
            ],
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
