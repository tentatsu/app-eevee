<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * ロードバランサー / Cloudflare の背後で正しい送信元IPを得るための設定。
 * 信頼するプロキシは config('eevee.trusted_proxies')（.env の TRUSTED_PROXIES）で指定する。
 */
class TrustProxies extends Middleware
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $value = trim((string) config('eevee.trusted_proxies'));
        if ($value === '') {
            return null;
        }
        if ($value === '*' || $value === '**') {
            return $value;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
