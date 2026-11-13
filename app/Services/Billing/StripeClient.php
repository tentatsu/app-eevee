<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Stripe API の薄いクライアント（公式 SDK は使わず、必要な API だけを Http で呼ぶ）。
 * キーはプラットフォームのもの。連結アカウントの売上は destination charge（transfer_data）で振り分ける。
 */
class StripeClient
{
    public const API_BASE = 'https://api.stripe.com/v1';

    public function __construct(private readonly StripePlatform $platform) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function post(string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $request = $this->client();
        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $this->result($request->post(self::API_BASE.$path, self::flatten($params)), $path);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->result($this->client()->get(self::API_BASE.$path, self::flatten($query)), $path);
    }

    private function client(): PendingRequest
    {
        $key = $this->platform->secretKey();
        if ($key === null) {
            throw new BillingException('Stripe is not configured.');
        }

        return Http::withToken($key)->asForm()->acceptJson()->timeout(20)->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Response $res, string $path): array
    {
        if (! $res->successful()) {
            $message = (string) ($res->json('error.message') ?? 'HTTP '.$res->status());
            throw new BillingException("Stripe API error on {$path}: {$message}");
        }

        /** @var array<string, mixed> */
        return (array) $res->json();
    }

    /**
     * ネストした配列を Stripe のフォーム形式（a[b][0]=…）にする。
     *
     * @param  array<string, mixed>  $params
     * @return array<string, scalar>
     */
    public static function flatten(array $params, string $prefix = ''): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
            if (is_array($value)) {
                $out += self::flatten($value, $name);
            } elseif (is_bool($value)) {
                $out[$name] = $value ? 'true' : 'false';
            } elseif ($value !== null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * Webhook の署名（Stripe-Signature: t=…,v1=…）を検証する。v1 = hex(HMAC-SHA256(secret, "{t}.{payload}"))
     */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $timestamp = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
