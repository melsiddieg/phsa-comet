<?php

namespace Tests\Feature;

use App\Livewire\UserAdmin;
use App\Models\User;
use App\Support\TemporaryPassword;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class LocalAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.comet.local_login' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'name' => 'Admin', 'auth_source' => 'local', 'enabled' => true, 'is_portal_admin' => true,
        ]);
    }

    private function localUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'auth_source' => 'local', 'enabled' => true, 'password' => 'old-password-123',
        ], $attrs));
    }

    private function fakeSessions(User $user, array $ids): void
    {
        foreach ($ids as $id) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
            ]);
        }
    }

    // ── Creating accounts ──────────────────────────────────────────────

    public function test_admin_creates_local_account_with_temporary_password(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(UserAdmin::class)
            ->set('newName', 'Dana Mapper')
            ->set('newEmail', 'Dana@PHSA.ca')
            ->set('newMapper', true)
            ->call('createLocalUser')
            ->assertHasNoErrors()
            ->assertSee('Temporary password for Dana Mapper');

        $user = User::where('email', 'dana@phsa.ca')->firstOrFail();
        $this->assertSame('local', $user->auth_source);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->is_mapper);
        $this->assertFalse($user->is_portal_admin);

        $temp = $component->get('issued')['password'];
        $this->assertTrue(Hash::check($temp, $user->password));
        $this->assertDatabaseHas('user_audits', ['user_id' => $user->id, 'field' => 'account_created', 'changed_by' => 'Admin']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->localUser(['email' => 'taken@phsa.ca']);

        Livewire::actingAs($this->admin())->test(UserAdmin::class)
            ->set('newName', 'Someone')
            ->set('newEmail', 'taken@phsa.ca')
            ->call('createLocalUser')
            ->assertHasErrors(['newEmail' => 'unique']);
    }

    public function test_temporary_password_format(): void
    {
        $p = TemporaryPassword::generate();
        $this->assertMatchesRegularExpression('/^[A-Za-z2-9]{4}(-[A-Za-z2-9]{4}){3}$/', $p);
        $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $p);
        $this->assertNotSame($p, TemporaryPassword::generate());
    }

    // ── First sign-in forces a password change ─────────────────────────

    public function test_new_user_is_sent_to_change_password_after_login(): void
    {
        $user = $this->localUser(['password' => 'Temp-Pass-1234', 'must_change_password' => true]);

        $this->post('/auth/local', ['email' => $user->email, 'password' => 'Temp-Pass-1234'])
            ->assertRedirect(route('account.password'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_who_must_change_password_cannot_use_the_app(): void
    {
        $user = $this->localUser(['must_change_password' => true]);

        $this->actingAs($user)->get('/')->assertRedirect(route('account.password'));
        $this->actingAs($user)->get('/sheets')->assertRedirect(route('account.password'));
        $this->actingAs($user)->get(route('account.password'))->assertOk()->assertSee('Choose your own password');
        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
    }

    // ── Changing your own password ─────────────────────────────────────

    public function test_user_changes_own_password(): void
    {
        $user = $this->localUser(['must_change_password' => true]);
        $this->fakeSessions($user, ['other-browser-1', 'other-browser-2']);

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'old-password-123',
            'password' => 'a new long passphrase',
            'password_confirmation' => 'a new long passphrase',
        ])->assertRedirect(route('home'))->assertSessionHas('status');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('a new long passphrase', $user->password));
        $this->assertDatabaseHas('user_audits', ['user_id' => $user->id, 'field' => 'password_changed']);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);   // other browsers signed out
    }

    public function test_password_change_validation(): void
    {
        $user = $this->localUser();
        $url = route('account.password.update');

        $cases = [
            'wrong current' => [['current_password' => 'nope', 'password' => 'a new long passphrase', 'password_confirmation' => 'a new long passphrase'], 'current_password'],
            'too short' => [['current_password' => 'old-password-123', 'password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'no match' => [['current_password' => 'old-password-123', 'password' => 'a new long passphrase', 'password_confirmation' => 'something else'], 'password'],
            'same as old' => [['current_password' => 'old-password-123', 'password' => 'old-password-123', 'password_confirmation' => 'old-password-123'], 'password'],
        ];

        foreach ($cases as $name => [$input, $field]) {
            $this->actingAs($user)->from(route('account.password'))->put($url, $input)
                ->assertSessionHasErrors($field, null, 'default');
            $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password), "password changed on: {$name}");
        }
    }

    public function test_entra_users_have_no_password_page(): void
    {
        $user = User::factory()->create(['auth_source' => 'entra', 'enabled' => true]);

        $this->actingAs($user)->get(route('account.password'))->assertNotFound();
    }

    // ── Admin resets ───────────────────────────────────────────────────

    public function test_admin_resets_password_and_signs_user_out(): void
    {
        $admin = $this->admin();
        $user = $this->localUser();
        $this->fakeSessions($user, ['s1', 's2']);

        $component = Livewire::actingAs($admin)->test(UserAdmin::class)
            ->call('resetPassword', $user->id)
            ->assertSee('Temporary password for');

        $user->refresh();
        $temp = $component->get('issued')['password'];
        $this->assertTrue($user->must_change_password);
        $this->assertFalse(Hash::check('old-password-123', $user->password));
        $this->assertTrue(Hash::check($temp, $user->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('user_audits', ['user_id' => $user->id, 'field' => 'password_reset']);
    }

    public function test_admin_cannot_reset_entra_or_own_password(): void
    {
        $admin = $this->admin();
        $entra = User::factory()->create(['auth_source' => 'entra', 'enabled' => true]);

        Livewire::actingAs($admin)->test(UserAdmin::class)
            ->call('resetPassword', $entra->id)->assertSee('Only local accounts')
            ->call('resetPassword', $admin->id)->assertSee('To change your own password')
            ->assertSet('issued', null);
    }

    public function test_disabling_a_user_signs_them_out(): void
    {
        $user = $this->localUser();
        $this->fakeSessions($user, ['s1']);

        Livewire::actingAs($this->admin())->test(UserAdmin::class)->call('toggle', $user->id, 'enabled');

        $this->assertFalse($user->fresh()->enabled);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_revoke_sessions_can_keep_the_current_one(): void
    {
        $user = $this->localUser();
        $this->fakeSessions($user, ['keep-me', 'drop-me']);

        $user->revokeSessions('keep-me');

        $this->assertDatabaseHas('sessions', ['id' => 'keep-me']);
        $this->assertDatabaseMissing('sessions', ['id' => 'drop-me']);
    }

    // ── Login protection ───────────────────────────────────────────────

    public function test_login_is_rate_limited(): void
    {
        $user = $this->localUser();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/auth/local', ['email' => $user->email, 'password' => 'wrong'])
                ->assertSessionHasErrors('auth');
        }

        // Even the right password is refused while locked out.
        $this->post('/auth/local', ['email' => $user->email, 'password' => 'old-password-123'])
            ->assertSessionHasErrors('auth');
        $this->assertStringStartsWith('Too many sign-in attempts', session('errors')->first('auth'));
        $this->assertGuest();
    }

    public function test_disabled_local_user_cannot_log_in(): void
    {
        $user = $this->localUser(['enabled' => false]);

        $this->post('/auth/local', ['email' => $user->email, 'password' => 'old-password-123'])
            ->assertSessionHasErrors('auth');
        $this->assertGuest();
    }

    // ── Seeder ─────────────────────────────────────────────────────────

    public function test_seeder_does_not_reset_an_existing_admin_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@comet.local')->firstOrFail();
        $this->assertTrue($admin->must_change_password);   // default password → must change

        $admin->update(['password' => 'my own strong password', 'must_change_password' => false]);
        $this->seed(DatabaseSeeder::class);

        $admin->refresh();
        $this->assertTrue(Hash::check('my own strong password', $admin->password));
        $this->assertFalse($admin->must_change_password);
    }

    // ── Console recovery ───────────────────────────────────────────────

    public function test_console_reset_password_for_local_account(): void
    {
        $user = $this->localUser(['email' => 'admin@comet.local']);
        $this->fakeSessions($user, ['s1']);

        $this->artisan('comet:reset-password', ['email' => 'ADMIN@comet.local'])
            ->expectsOutputToContain('Temporary password for')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertFalse(Hash::check('old-password-123', $user->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('user_audits', ['user_id' => $user->id, 'field' => 'password_reset', 'changed_by' => 'server console']);
    }

    public function test_console_reset_refuses_entra_and_unknown_accounts(): void
    {
        User::factory()->create(['email' => 'sso@phsa.ca', 'auth_source' => 'entra']);

        $this->artisan('comet:reset-password', ['email' => 'sso@phsa.ca'])->assertFailed();
        $this->artisan('comet:reset-password', ['email' => 'nobody@phsa.ca'])->assertFailed();
    }
}
