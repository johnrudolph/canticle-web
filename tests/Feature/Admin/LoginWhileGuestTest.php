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

test('logging in as a real user forgets the guest\'s stale "remember me" cookie', function () {
    // Same setup as above: a browser's first-ever visit silently signs
    // it in as a guest with a long-lived remember cookie (see
    // EnsureGuestIdentity). Left uncleared, that cookie is exactly what
    // lets SessionGuard silently re-authenticate as that old guest once
    // the admin's real session later expires — showing up as a 403 at
    // EnsureIsAdmin rather than a clean bounce to /login. See
    // ForgetStaleGuestIdentity.
    $guestResponse = $this->get('/');
    $guestResponse->assertOk();

    $recallerCookie = collect($guestResponse->headers->getCookies())
        ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

    expect($recallerCookie)->not->toBeNull();

    User::updateOrCreate(
        ['email' => 'hello@catacombian.com'],
        ['password' => Hash::make('Molina4lyfe'), 'is_admin' => true, 'is_guest' => false]
    );

    // A real browser would already be carrying this cookie on every
    // subsequent request — the test client doesn't forward cookies
    // between calls automatically, so it's forwarded by hand here to
    // reproduce that.
    $loginResponse = $this->withUnencryptedCookie($recallerCookie->getName(), $recallerCookie->getValue())
        ->post('/login', [
            'email' => 'hello@catacombian.com',
            'password' => 'Molina4lyfe',
        ]);

    // Cleared, not just left alone: Laravel's "forget cookie" convention
    // is the same name set again with Max-Age=0 and an already-expired
    // date.
    $setCookieHeaders = $loginResponse->headers->all('Set-Cookie');
    $forgotten = collect($setCookieHeaders)
        ->first(fn (string $header) => str_contains($header, $recallerCookie->getName()));

    expect($forgotten)->not->toBeNull()
        ->and($forgotten)->toContain('Max-Age=0');
});
