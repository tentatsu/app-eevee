<?php

namespace App\Services\Segments;

/**
 * セグメント条件に使えるフィールド（型・SQL式・使える演算子）。
 */
final class SegmentField
{
    public const TYPE_STRING = 'string';

    public const TYPE_INT = 'int';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_DATE = 'date';

    public const TYPE_BOOL = 'bool';

    public const TYPE_VERSION = 'version';

    public const TYPE_CATEGORY = 'category';

    /** 型ごとに使える演算子 */
    public const OPERATORS = [
        self::TYPE_STRING => ['eq', 'neq', 'in', 'not_in', 'exists', 'not_exists'],
        self::TYPE_INT => ['eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'between', 'exists', 'not_exists'],
        self::TYPE_DECIMAL => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'exists', 'not_exists'],
        self::TYPE_DATE => ['gt', 'gte', 'lt', 'lte', 'between', 'within_days', 'before_days', 'exists', 'not_exists'],
        self::TYPE_BOOL => ['eq', 'neq', 'exists', 'not_exists'],
        self::TYPE_VERSION => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'],
        self::TYPE_CATEGORY => ['eq'],
    ];

    /**
     * @param  'attribute'|'column'|'logged_in'|'category'  $kind
     * @param  list<string>|null  $choices
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly string $kind,
        public readonly string $column,
        public readonly ?string $key = null,
        public readonly bool $default = true,
        public readonly ?array $choices = null,
    ) {}

    /**
     * @return list<string>
     */
    public function operators(): array
    {
        return self::OPERATORS[$this->type];
    }

    /**
     * 管理画面の条件ビルダー用。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'operators' => $this->operators(),
            'choices' => $this->choices,
        ];
    }
}
