<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_with_microsoft_button(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in with Microsoft');
    }

    public function test_home_requires_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_local_break_glass_login_works_when_enabled(): void
    {
        config(['services.comet.local_login' => true]);

        $user = User::factory()->create([
            'auth_source' => 'local',
            'password' => 'secret-password',
            'enabled' => true,
        ]);

        $this->post('/auth/local', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_local_login_is_404_when_disabled(): void
    {
        config(['services.comet.local_login' => false]);

        $this->post('/auth/local', [
            'email' => 'x@y.z',
            'password' => 'whatever',
        ])->assertNotFound();
    }

    public function test_entra_accounts_cannot_use_local_login(): void
    {
        config(['services.comet.local_login' => true]);

        $user = User::factory()->create([
            'auth_source' => 'entra',
            'password' => 'secret-password',
        ]);

        $this->post('/auth/local', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertSessionHasErrors('auth');

        $this->assertGuest();
    }

    public function test_role_gates(): void
    {
        $mapper = User::factory()->create(['is_mapper' => true, 'enabled' => true]);
        $nobody = User::factory()->create(['enabled' => true]);
        $disabledAdmin = User::factory()->create(['is_portal_admin' => true, 'enabled' => false]);

        $this->assertTrue(Gate::forUser($mapper)->allows('map'));
        $this->assertFalse(Gate::forUser($mapper)->allows('admin'));
        $this->assertFalse(Gate::forUser($nobody)->allows('map'));
        $this->assertFalse(Gate::forUser($disabledAdmin)->allows('admin'));
    }
}
