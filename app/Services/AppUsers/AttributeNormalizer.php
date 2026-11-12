<?php

namespace App\Services\AppUsers;

use App\Enums\AttributeType;
use App\Models\App;
use App\Models\AttributeDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * アプリユーザーの属性を、アプリの「属性の定義」に従って検証・正規化する。
 *
 * - 定義にないキーはエラー
 * - アプリからの更新（$fromApp = true）では app_writable の定義だけを受け付ける
 * - null を指定したキーは削除する
 * - date は UTC の "Y-m-d H:i:s" に正規化する（セグメントの比較で使う）
 */
class AttributeNormalizer
{
    /** @var array<int, Collection<string, AttributeDefinition>> */
    private array $definitions = [];

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed> 正規化した値（削除するキーは null）
     */
    public function normalize(App $app, array $input, bool $fromApp, string $errorPrefix = 'attributes'): array
    {
        $defs = $this->definitionsFor($app);
        $out = [];
        $errors = [];

        foreach ($input as $key => $value) {
            $key = (string) $key;
            $field = $errorPrefix.'.'.$key;
            $def = $defs->get($key);
            if ($def === null) {
                $errors[$field][] = "Unknown attribute: {$key}";

                continue;
            }
            if ($fromApp && ! $def->app_writable) {
                $errors[$field][] = "The attribute {$key} cannot be updated from the app.";

                continue;
            }
            if ($value === null) {
                $out[$key] = null;

                continue;
            }
            $normalized = self::cast($def->type, $value);
            if ($normalized === null) {
                $errors[$field][] = "The attribute {$key} must be of type {$def->type->value}.";

                continue;
            }
            $out[$key] = $normalized;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * 正規化した値を既存の属性にマージする（null のキーは削除）。
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function merge(?array $current, array $changes): array
    {
        $current ??= [];
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }
        ksort($current);

        return $current;
    }

    /**
     * 型に合わせて値を変換する。変換できなければ null。
     */
    public static function cast(AttributeType $type, mixed $value): string|int|float|bool|null
    {
        return match ($type) {
            AttributeType::String => is_scalar($value) && ! is_bool($value) && mb_strlen((string) $value) <= 255 ? (string) $value : null,
            AttributeType::Int => match (true) {
                is_int($value) => $value,
                is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 => (int) $value,
                is_float($value) && floor($value) === $value && abs($value) < 1e18 => (int) $value,
                default => null,
            },
            AttributeType::Decimal => is_numeric($value) ? round((float) $value, 4) : null,
            AttributeType::Bool => match (true) {
                is_bool($value) => $value,
                in_array($value, [1, '1', 'true'], true) => true,
                in_array($value, [0, '0', 'false'], true) => false,
                default => null,
            },
            AttributeType::Date => self::castDate($value),
        };
    }

    private static function castDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return Collection<string, AttributeDefinition>
     */
    public function definitionsFor(App $app): Collection
    {
        return $this->definitions[$app->id] ??= AttributeDefinition::withoutGlobalScopes()
            ->where('app_id', $app->id)
            ->get()
            ->keyBy('key');
    }
}
