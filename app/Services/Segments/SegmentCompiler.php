<?php

namespace App\Services\Segments;

use App\Models\App;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * セグメント条件（JSON）を検証し、SQL の WHERE 句に変換する。
 *
 * 条件の形式:
 *   グループ: {"op": "and" | "or", "conditions": [ <グループ or 条件>, ... ]}
 *   条件:     {"field": "attr.gender", "operator": "eq", "value": "female"}
 *
 * クエリは devices を d、app_users を u という別名で JOIN している前提。
 * 属性の条件は「値が存在し、条件を満たす」ときだけ真（neq / not_in も、値がないユーザーには一致しない）。
 */
class SegmentCompiler
{
    public const MAX_DEPTH = 5;

    public const MAX_CONDITIONS = 100;

    private int $count = 0;

    public function __construct(private readonly SegmentFieldCatalog $catalog) {}

    /**
     * 条件を検証する。不正なら ValidationException（キーは conditions）。
     *
     * @param  array<array-key, mixed>|null  $tree
     */
    public function validate(App $app, ?array $tree): void
    {
        $this->count = 0;
        if ($tree === null || $tree === []) {
            return;
        }
        $errors = [];
        $this->validateNode($app, $tree, 'conditions', 1, $errors);
        if ($this->count > self::MAX_CONDITIONS) {
            $errors[] = '条件は最大'.self::MAX_CONDITIONS.'個までです。';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['conditions' => $errors]);
        }
    }

    /**
     * 検証済みの条件をクエリに適用する。
     *
     * @param  array<array-key, mixed>|null  $tree
     */
    public function apply(Builder $query, App $app, ?array $tree): Builder
    {
        if ($tree === null || $tree === [] || $this->isEmptyGroup($tree)) {
            return $query;
        }
        $this->validate($app, $tree);
        $fields = $this->catalog->for($app);

        return $query->where(fn (Builder $q) => $this->applyNode($q, $tree, $fields, $app));
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $errors
     */
    private function validateNode(App $app, array $node, string $path, int $depth, array &$errors): void
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = "{$path}: グループの入れ子は".self::MAX_DEPTH.'段までです。';

            return;
        }

        if (array_key_exists('op', $node) || array_key_exists('conditions', $node)) {
            if (! in_array($node['op'] ?? null, ['and', 'or'], true)) {
                $errors[] = "{$path}.op: and または or を指定してください。";
            }
            if (! is_array($node['conditions'] ?? null) || ! array_is_list($node['conditions'])) {
                $errors[] = "{$path}.conditions: 配列を指定してください。";

                return;
            }
            foreach ($node['conditions'] as $i => $child) {
                if (! is_array($child)) {
                    $errors[] = "{$path}.conditions.{$i}: 不正な条件です。";

                    continue;
                }
                $this->validateNode($app, $child, "{$path}.conditions.{$i}", $depth + 1, $errors);
            }

            return;
        }

        $this->count++;
        $fields = $this->catalog->for($app);
        $fieldName = $node['field'] ?? null;
        $operator = $node['operator'] ?? null;
        $field = is_string($fieldName) ? ($fields[$fieldName] ?? null) : null;
        if ($field === null) {
            $errors[] = "{$path}.field: 不明なフィールド ".(is_string($fieldName) ? $fieldName : '').' です。';

            return;
        }
        if (! is_string($operator) || ! in_array($operator, $field->operators(), true)) {
            $errors[] = "{$path}.operator: {$field->name} に演算子 ".(is_string($operator) ? $operator : '').' は使えません。';

            return;
        }

        try {
            $this->bindValues($field, $operator, $node['value'] ?? null, $app);
        } catch (InvalidSegmentValue $e) {
            $errors[] = "{$path}.value: ".$e->getMessage();
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, SegmentField>  $fields
     */
    private function applyNode(Builder $q, array $node, array $fields, App $app): void
    {
        if (array_key_exists('op', $node)) {
            $boolean = $node['op'] === 'or' ? 'or' : 'and';
            /** @var list<array<array-key, mixed>> $children */
            $children = $node['conditions'] ?? [];
            if ($children === []) {
                // 空のグループ: AND は常に真、OR は常に偽
                $q->whereRaw($boolean === 'and' ? '1 = 1' : '1 = 0');

                return;
            }
            foreach ($children as $child) {
                $q->where(fn (Builder $sub) => $this->applyNode($sub, $child, $fields, $app), boolean: $boolean);
            }

            return;
        }

        $field = $fields[(string) $node['field']];
        [$sql, $bindings] = $this->conditionSql($field, (string) $node['operator'], $node['value'] ?? null, $app);
        $q->whereRaw($sql, $bindings);
    }

    /**
     * 1つの条件の SQL とバインド値。
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function conditionSql(SegmentField $field, string $operator, mixed $value, App $app): array
    {
        $values = $this->bindValues($field, $operator, $value, $app);

        if ($field->kind === 'logged_in') {
            $want = (bool) ($values[0] ?? true);
            if ($operator === 'neq') {
                $want = ! $want;
            }
            if ($operator === 'exists') {
                return ['1 = 1', []];
            }
            if ($operator === 'not_exists') {
                return ['1 = 0', []];
            }

            return [$want ? 'u.id IS NOT NULL' : 'u.id IS NULL', []];
        }

        if ($field->kind === 'category') {
            // ユーザーの設定がなければカテゴリの初期値。未ログイン（u が NULL）も初期値で判定する。
            $sql = 'COALESCE(JSON_UNQUOTE(JSON_EXTRACT(u.notification_preferences, ?)), ?) = ?';

            return [$sql, [self::jsonPath((string) $field->key), $field->default ? 'true' : 'false', $values[0] ? 'true' : 'false']];
        }

        [$expr, $exprBindings, $existsSql, $existsBindings] = $this->expression($field);

        if ($operator === 'exists') {
            return [$existsSql, $existsBindings];
        }
        if ($operator === 'not_exists') {
            return ['NOT ('.$existsSql.')', $existsBindings];
        }

        $sql = match ($operator) {
            'eq' => "{$expr} = ?",
            'neq' => "{$expr} <> ?",
            'gt' => "{$expr} > ?",
            'within_days' => "{$expr} >= ?",
            'gte' => "{$expr} >= ?",
            'lt', 'before_days' => "{$expr} < ?",
            'lte' => "{$expr} <= ?",
            'between' => "{$expr} BETWEEN ? AND ?",
            'in' => "{$expr} IN (".implode(', ', array_fill(0, count($values), '?')).')',
            'not_in' => "{$expr} NOT IN (".implode(', ', array_fill(0, count($values), '?')).')',
            default => throw new InvalidSegmentValue("Unsupported operator {$operator}"),
        };

        // 値が存在しない場合（NULL）は比較が NULL になり、どの演算子でも一致しない
        return [$sql, array_merge($exprBindings, $values)];
    }

    /**
     * フィールドの値の SQL 式と、「値が存在する」の SQL。
     *
     * @return array{0: string, 1: list<mixed>, 2: string, 3: list<mixed>}
     */
    private function expression(SegmentField $field): array
    {
        if ($field->kind === 'column') {
            return [$field->column, [], "{$field->column} IS NOT NULL", []];
        }

        // JSON の属性
        $path = self::jsonPath((string) $field->key);
        $raw = "JSON_UNQUOTE(JSON_EXTRACT({$field->column}, ?))";
        $expr = match ($field->type) {
            SegmentField::TYPE_INT => "CAST({$raw} AS SIGNED)",
            SegmentField::TYPE_DECIMAL => "CAST({$raw} AS DECIMAL(24,4))",
            SegmentField::TYPE_DATE => "CAST({$raw} AS DATETIME)",
            default => $raw,
        };
        $exists = "(JSON_EXTRACT({$field->column}, ?) IS NOT NULL AND JSON_TYPE(JSON_EXTRACT({$field->column}, ?)) <> 'NULL')";

        return [$expr, [$path], $exists, [$path, $path]];
    }

    /**
     * 値を検証し、バインドする値に変換する。
     *
     * @return list<mixed>
     */
    private function bindValues(SegmentField $field, string $operator, mixed $value, App $app): array
    {
        if (in_array($operator, ['exists', 'not_exists'], true)) {
            return [];
        }

        if (in_array($operator, ['within_days', 'before_days'], true)) {
            if (! is_numeric($value) || (int) $value < 0 || (int) $value > 36500 || (int) $value != $value) {
                throw new InvalidSegmentValue('日数（0以上の整数）を指定してください。');
            }

            return [CarbonImmutable::now()->subDays((int) $value)->utc()->format('Y-m-d H:i:s')];
        }

        if (in_array($operator, ['in', 'not_in'], true)) {
            if (! is_array($value) || $value === [] || count($value) > 1000) {
                throw new InvalidSegmentValue('1〜1000個の値の配列を指定してください。');
            }

            return array_values(array_map(fn ($v) => $this->scalar($field, $v, $app), $value));
        }

        if ($operator === 'between') {
            if (! is_array($value) || count($value) !== 2 || ! array_is_list($value)) {
                throw new InvalidSegmentValue('[下限, 上限] の2つの値を指定してください。');
            }

            return [$this->scalar($field, $value[0], $app), $this->scalar($field, $value[1], $app)];
        }

        return [$this->scalar($field, $value, $app)];
    }

    private function scalar(SegmentField $field, mixed $value, App $app): string|int|float|bool
    {
        switch ($field->type) {
            case SegmentField::TYPE_STRING:
                if (! is_scalar($value) || is_bool($value) || (string) $value === '' || mb_strlen((string) $value) > 255) {
                    throw new InvalidSegmentValue('文字列を指定してください。');
                }
                if ($field->choices !== null && ! in_array((string) $value, $field->choices, true)) {
                    throw new InvalidSegmentValue(implode(' / ', $field->choices).' のいずれかを指定してください。');
                }

                return (string) $value;
            case SegmentField::TYPE_INT:
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    throw new InvalidSegmentValue('整数を指定してください。');
                }

                return (int) $value;
            case SegmentField::TYPE_DECIMAL:
                if (! is_numeric($value)) {
                    throw new InvalidSegmentValue('数値を指定してください。');
                }

                return (float) $value;
            case SegmentField::TYPE_DATE:
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidSegmentValue('日時を指定してください。');
                }
                try {
                    // 管理画面で入力した日時はアプリの表示タイムゾーンとして解釈し、UTC に変換する
                    return CarbonImmutable::parse($value, $app->displayTimezone())->utc()->format('Y-m-d H:i:s');
                } catch (Throwable) {
                    throw new InvalidSegmentValue('日時として解釈できません。');
                }
            case SegmentField::TYPE_VERSION:
                if (! is_string($value) || preg_match('/^\d+(\.\d+){0,2}/', $value) !== 1) {
                    throw new InvalidSegmentValue('バージョン（例: 1.2.0）を指定してください。');
                }

                return Device::versionToNumber($value);
            case SegmentField::TYPE_BOOL:
            case SegmentField::TYPE_CATEGORY:
                $bool = is_bool($value) ? $value : match ($value) {
                    'true', '1', 1 => true,
                    'false', '0', 0 => false,
                    default => null,
                };
                if ($bool === null) {
                    throw new InvalidSegmentValue('true または false を指定してください。');
                }
                // 属性の bool は JSON_UNQUOTE で 'true' / 'false' になる
                if ($field->kind === 'attribute') {
                    return $bool ? 'true' : 'false';
                }

                return $bool;
        }

        throw new InvalidSegmentValue('Unsupported field type.');
    }

    /**
     * @param  array<array-key, mixed>  $tree
     */
    private function isEmptyGroup(array $tree): bool
    {
        return ($tree['op'] ?? null) === 'and' && ($tree['conditions'] ?? null) === [];
    }

    public static function jsonPath(string $key): string
    {
        return '$."'.str_replace(['\\', '"'], ['\\\\', '\\"'], $key).'"';
    }
}
