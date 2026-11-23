<?php

use App\Http\Middleware\EnsureGuestIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('a visitor already auto-authenticated as a guest can still reach and submit the login form', function () {
    // Simulate what every real visitor already has by the time they
    // think to look for /login: a silently-created, fully authenticated
    // guest session (see EnsureGuestIdentity). Fortify's own /login
    // routes are wrapped in `guest` middleware, which would treat this
    // as "already logged in" and bounce away — the whole reason
    // FortifyServiceProvider re-registers these routes without it.
    $this->get('/')->assertOk();
    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->is_guest)->toBeTrue();

    $this->get('/login')->assertOk();

    // DatabaseSeeder already seeded this exact admin once for the whole
    // test run (see tests/TestCase.php's $seed = true) — reuse it
    // rather than colliding on the unique email.
    $admin = User::updateOrCreate(
        ['email' => 'hello@catacombian.com'],
        ['password' => Hash::make('Molina4lyfe'), 'is_admin' => true, 'is_guest' => false]
    );

    $this->post('/login', [
        'email' => 'hello@catacombian.com',
        'password' => 'Molina4lyfe',
    ])->assertRedirect('/admin');

    expect(auth()->id())->toBe($admin->id);

    $this->get('/admin')->assertOk();
});
