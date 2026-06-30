<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Account\AccountClientFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Pbb\AccountSdk\AccountException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AccountSsoController extends Controller
{
    public function redirect(Request $request, AccountClientFactory $accounts): RedirectResponse
    {
        abort_unless(config('account.enabled'), 404);

        $request->session()->put('pbb_account.return_to', $this->safeReturnPath($request->query('return', '/')));

        return redirect()->away($accounts->make($request)->authorizationUrl());
    }

    public function callback(Request $request, AccountClientFactory $accounts): RedirectResponse
    {
        abort_unless(config('account.enabled'), 404);

        try {
            $identity = $accounts->make($request)->handleCallback($request->query())->toArray();
            $user = $this->resolveLocalUser($identity);
            $this->assertLocalAccessAllowed($user);

            Auth::guard('web')->login($user, true);
            $request->session()->regenerate();

            return redirect($this->safeReturnPath($request->session()->pull('pbb_account.return_to', '/')))
                ->with('account_login_success', true);
        } catch (AccountException $exception) {
            return redirect('/')->with('account_login_error', $exception->getMessage());
        } catch (HttpExceptionInterface $exception) {
            return redirect('/')->with('account_login_error', $exception->getMessage() ?: 'Account sign in was rejected.');
        } catch (\Throwable $exception) {
            report($exception);

            return redirect('/')->with('account_login_error', 'Unable to complete Account sign in.');
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! config('account.enabled')) {
            return redirect('/');
        }

        return redirect()->away($this->accountLogoutUrl());
    }

    private function resolveLocalUser(array $identity): User
    {
        $pbbUserId = trim((string) ($identity['pbb_user_id'] ?? ''));
        $email = mb_strtolower(trim((string) ($identity['email'] ?? '')));
        $name = trim((string) ($identity['name'] ?? '')) ?: ($email ?: 'PBB User');

        abort_if($pbbUserId === '', 422, 'Account identity is missing pbb_user_id.');

        $user = User::query()->where('pbb_user_id', $pbbUserId)->first();

        if (! $user && $email !== '') {
            $user = User::query()->where('email', $email)->first();
            abort_if($user && $user->pbb_user_id && $user->pbb_user_id !== $pbbUserId, 409, 'This email is already linked to another Account identity.');
        }

        if (! $user) {
            $user = new User([
                'role' => 'user',
                'status' => 'active',
                'password' => Hash::make(Str::random(64)),
            ]);
        }

        $user->pbb_user_id = $pbbUserId;
        $user->name = $name;
        if ($email !== '') {
            $user->email = $email;
        }
        if (! $user->role) {
            $user->role = 'user';
        }
        if (! $user->status) {
            $user->status = 'active';
        }
        $user->save();

        return $user;
    }

    private function assertLocalAccessAllowed(User $user): void
    {
        if ($user->status === 'disabled') {
            abort(403, 'This Maestro account is disabled.');
        }
    }

    private function safeReturnPath(mixed $value): string
    {
        $path = trim((string) $value);

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return $path;
    }

    private function accountLogoutUrl(): string
    {
        $baseUrl = rtrim((string) config('account.base_url'), '/');
        $postLogout = trim((string) config('account.post_logout_redirect_uri')) ?: url('/');

        return $baseUrl.'/oauth/logout?'.http_build_query([
            'client_id' => config('account.client_id'),
            'post_logout_redirect_uri' => $postLogout,
        ]);
    }
}
