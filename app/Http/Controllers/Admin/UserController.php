<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $search = mb_strtolower(trim($request->string('search')->toString()));

        return Inertia::render('Admin/Users/Index', [
            'users' => User::query()
                ->when($search, fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
                }))
                ->latest()
                ->paginate(10)
                ->withQueryString(),
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        User::create($request->validated());

        return back()->with('success', 'Pengguna berhasil ditambahkan.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        if ($request->user()->is($user) && $validated['role'] !== 'admin') {
            return back()->withErrors(['role' => 'Anda tidak dapat menurunkan role akun sendiri.']);
        }

        if ($user->isAdmin() && $validated['role'] !== 'admin' && User::where('role', 'admin')->count() === 1) {
            return back()->withErrors(['role' => 'Minimal harus ada satu admin.']);
        }

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $user->update($validated);

        return back()->with('success', 'Pengguna berhasil diperbarui.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return back()->withErrors(['user' => 'Anda tidak dapat menghapus akun sendiri.']);
        }

        if ($user->isAdmin() && User::where('role', 'admin')->count() === 1) {
            return back()->withErrors(['user' => 'Admin terakhir tidak dapat dihapus.']);
        }

        $user->delete();

        return back()->with('success', 'Pengguna berhasil dihapus.');
    }
}
