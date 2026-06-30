<?php

namespace App\Services\Account;

use App\Services\MaestroSettings;
use Illuminate\Http\Request;
use Pbb\AccountSdk\AccountClient;
use Pbb\AccountSdk\AccountConfig;

class AccountClientFactory
{
    public function __construct(private readonly MaestroSettings $settings)
    {
    }

    public function make(Request $request): AccountClient
    {
        $config = $this->settings->accountSsoConfig();

        return new AccountClient(
            new AccountConfig([
                'base_url' => $config['base_url'],
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $config['redirect_uri'],
                'scopes' => array_filter(explode(' ', $config['scopes'])),
                'timeout_seconds' => $config['timeout_seconds'],
                'ca_bundle' => $config['ca_bundle'],
            ]),
            new LaravelAccountStateStore($request->session()),
        );
    }
}
