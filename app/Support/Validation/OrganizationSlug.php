<?php

namespace App\Support\Validation;

use App\Models\Organization;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 組織 slug: 英小文字・数字・ハイフンのみ、3〜32文字、予約語は不可。
 */
class OrganizationSlug implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[a-z0-9-]{3,32}$/', $value) !== 1) {
            $fail('slugは英小文字・数字・ハイフンのみ、3〜32文字で入力してください。');

            return;
        }
        if (str_starts_with($value, '-') || str_ends_with($value, '-')) {
            $fail('slugの先頭と末尾にハイフンは使えません。');

            return;
        }
        if (in_array($value, Organization::RESERVED_SLUGS, true)) {
            $fail('このslugは予約語のため使用できません。');
        }
    }
}
