<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSsoTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_redirect_is_disabled_by_default(): void
    {
        config()->set('account.enabled', false);

        $this->get('/auth/account/redirect')->assertNotFound();
    }

    public function test_account_redirect_builds_authorize_url_when_enabled(): void
    {
        $this->enableAccountSso();

        $response = $this->get('/auth/account/redirect?return=/applications');

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://account.pbb.ph/oauth/authorize?', $location);
        $this->assertStringContainsString('client_id=pbb-maestro', $location);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fmaestro.pbb.ph%2Fauth%2Faccount%2Fcallback', $location);
        $this->assertStringContainsString('scope=openid+profile', $location);
        $this->assertStringContainsString('state=', $location);
    }

    public function test_invalid_callback_redirects_home_with_flash_error(): void
    {
        $this->enableAccountSso();

        $response = $this->get('/auth/account/callback?code=bad-code&state=wrong-state');

        $response->assertRedirect('/');
        $response->assertSessionHas('account_login_error', 'Account callback state is invalid or expired.');

        $this->assertGuest();
    }

    public function test_account_logout_clears_local_session_and_redirects_to_account(): void
    {
        $this->enableAccountSso();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/auth/logout');

        $response->assertRedirect('https://account.pbb.ph/oauth/logout?client_id=pbb-maestro&post_logout_redirect_uri=https%3A%2F%2Fmaestro.pbb.ph');
        $this->assertGuest();
    }

    private function enableAccountSso(): void
    {
        config()->set('account.enabled', true);
        config()->set('account.base_url', 'https://account.pbb.ph');
        config()->set('account.client_id', 'pbb-maestro');
        config()->set('account.client_secret', 'test-secret');
        config()->set('account.redirect_uri', 'https://maestro.pbb.ph/auth/account/callback');
        config()->set('account.post_logout_redirect_uri', 'https://maestro.pbb.ph');
        config()->set('account.scopes', ['openid', 'profile']);
    }
}
