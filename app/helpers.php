<?php

use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;

if (! function_exists('eevee_timezone')) {
    /**
     * 表示に使うタイムゾーン（アプリ → 組織 → 既定値の順）。
     */
    function eevee_timezone(): string
    {
        $tenant = app(TenantContext::class);

        return $tenant->app()?->timezone
            ?: $tenant->organization()?->timezone
            ?: (string) config('eevee.default_timezone', 'Asia/Tokyo');
    }
}

if (! function_exists('eevee_dt')) {
    /**
     * UTC で保存した日時を表示用のタイムゾーンで整形する。
     */
    function eevee_dt(?CarbonInterface $value, string $format = 'Y-m-d H:i', ?string $timezone = null): string
    {
        if ($value === null) {
            return '—';
        }

        return $value->copy()->setTimezone($timezone ?? eevee_timezone())->format($format);
    }
}

if (! function_exists('eevee_input_dt')) {
    /**
     * datetime-local 入力欄の value 用。
     */
    function eevee_input_dt(?CarbonInterface $value): string
    {
        return $value === null ? '' : $value->copy()->setTimezone(eevee_timezone())->format('Y-m-d\TH:i');
    }
}
