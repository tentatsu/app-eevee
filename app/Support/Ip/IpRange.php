<?php

namespace App\Support\Ip;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * IPアドレス / CIDR の検証と照合（IPv4・IPv6 両対応）。
 */
final class IpRange
{
    /**
     * 入力を正規化する。不正な値なら null。
     * 例: "192.168.1.10" → "192.168.1.10/32", "2001:DB8::/32" → "2001:db8::/32"
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $prefix = null;
        $address = $value;
        if (str_contains($value, '/')) {
            [$address, $prefixPart] = explode('/', $value, 2);
            if (! ctype_digit($prefixPart)) {
                return null;
            }
            $prefix = (int) $prefixPart;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $max = 32;
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $max = 128;
            $packed = inet_pton($address);
            $address = $packed !== false ? (string) inet_ntop($packed) : $address;
        } else {
            return null;
        }

        $prefix ??= $max;
        if ($prefix < 0 || $prefix > $max) {
            return null;
        }

        return $address.'/'.$prefix;
    }

    public static function isValid(string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /**
     * @param  list<string>  $ranges
     */
    public static function matches(?string $ip, array $ranges): bool
    {
        if ($ip === null || $ip === '' || $ranges === []) {
            return false;
        }

        return IpUtils::checkIp($ip, $ranges);
    }

    /**
     * カンマ・空白・改行区切りの文字列を配列にする（不正な値は除外）。
     *
     * @return list<string>
     */
    public static function parseList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        $items = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($items as $item) {
            $normalized = self::normalize($item);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return array_values(array_unique($out));
    }
}
