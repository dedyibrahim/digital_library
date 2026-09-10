<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_user_management_page(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Budi Pengguna']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index')
                ->has('users.data', 2));
    }

    public function test_regular_user_cannot_access_user_management(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_update_and_delete_user(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Siti User',
            'email' => 'siti@example.com',
            'role' => 'user',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'siti@example.com')->firstOrFail();
        $this->assertSame('user', $user->role);

        $this->actingAs($admin)->patch(route('admin.users.update', $user), [
            'name' => 'Siti Admin',
            'email' => 'siti@example.com',
            'role' => 'admin',
            'password' => '',
            'password_confirmation' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('admin', $user->fresh()->role);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $user))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_admin_cannot_demote_or_delete_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'user',
            'password' => '',
            'password_confirmation' => '',
        ])->assertSessionHasErrors('role');

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);
    }
}
