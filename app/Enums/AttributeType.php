<?php

namespace App\Enums;

enum AttributeType: string
{
    case String = 'string';
    case Int = 'int';
    case Decimal = 'decimal';
    case Date = 'date';
    case Bool = 'bool';

    public function label(): string
    {
        return match ($this) {
            self::String => '文字列',
            self::Int => '整数',
            self::Decimal => '小数',
            self::Date => '日時',
            self::Bool => '真偽値',
        };
    }
}
