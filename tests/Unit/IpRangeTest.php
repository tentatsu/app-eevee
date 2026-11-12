<?php

use App\Support\Ip\IpRange;

it('normalizes IPs and CIDRs', function (string $in, ?string $out) {
    expect(IpRange::normalize($in))->toBe($out);
})->with([
    ['192.168.1.10', '192.168.1.10/32'],
    [' 10.0.0.0/8 ', '10.0.0.0/8'],
    ['2001:DB8::1', '2001:db8::1/128'],
    ['2001:0db8:0000::/32', '2001:db8::/32'],
    ['10.0.0.0/33', null],
    ['2001:db8::/129', null],
    ['10.0.0.256', null],
    ['10.0.0.0/abc', null],
    ['example.com', null],
    ['', null],
]);

it('matches IPv4 and IPv6 ranges', function () {
    expect(IpRange::matches('10.1.2.3', ['10.0.0.0/8']))->toBeTrue()
        ->and(IpRange::matches('11.0.0.1', ['10.0.0.0/8']))->toBeFalse()
        ->and(IpRange::matches('2001:db8::1', ['2001:db8::/32']))->toBeTrue()
        ->and(IpRange::matches('2001:db9::1', ['2001:db8::/32']))->toBeFalse()
        ->and(IpRange::matches('10.0.0.1', []))->toBeFalse()
        ->and(IpRange::matches(null, ['10.0.0.0/8']))->toBeFalse();
});

it('parses comma separated lists', function () {
    expect(IpRange::parseList('10.0.0.0/8, 192.0.2.1  bogus 2001:db8::/32'))->toBe(['10.0.0.0/8', '192.0.2.1/32', '2001:db8::/32']);
});
