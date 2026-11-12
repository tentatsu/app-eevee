<?php

use App\Enums\AttributeType;
use App\Enums\Platform;
use App\Enums\PushPermission;
use App\Models\App;
use App\Models\AppUser;
use App\Models\AttributeDefinition;
use App\Models\Device;
use App\Models\NotificationCategory;
use App\Services\Segments\SegmentCompiler;
use App\Services\Segments\SegmentQuery;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->clientApp = App::factory()->create(['timezone' => 'UTC']);
    foreach ([
        ['gender', AttributeType::String], ['birth_month', AttributeType::Int], ['total_amount', AttributeType::Decimal],
        ['last_purchase_at', AttributeType::Date], ['premium', AttributeType::Bool],
    ] as [$key, $type]) {
        AttributeDefinition::query()->create(['app_id' => $this->clientApp->id, 'key' => $key, 'type' => $type, 'label' => $key]);
    }
    NotificationCategory::query()->create(['app_id' => $this->clientApp->id, 'key' => 'campaign', 'label' => 'キャンペーン', 'default_enabled' => true]);
    NotificationCategory::query()->create(['app_id' => $this->clientApp->id, 'key' => 'news', 'label' => 'ニュース', 'default_enabled' => false]);

    $this->users = [];
    $make = function (string $name, array $attrs, array $device = [], array $prefs = []) {
        $user = AppUser::factory()->for($this->clientApp)->create(['name' => $name, 'custom_attributes' => $attrs, 'notification_preferences' => $prefs]);
        Device::factory()->for($this->clientApp)->create(array_merge(['app_user_id' => $user->id, 'platform' => Platform::Ios, 'app_version' => '1.0.0'], $device));
        $this->users[$name] = $user;
    };
    $make('alice', ['gender' => 'female', 'birth_month' => 4, 'total_amount' => 1200.5, 'last_purchase_at' => now()->subDays(3)->format('Y-m-d H:i:s'), 'premium' => true], ['app_version' => '2.1.0', 'last_active_at' => now()->subDay()], ['campaign' => false]);
    $make('bob', ['gender' => 'male', 'birth_month' => 12, 'total_amount' => 300, 'last_purchase_at' => now()->subDays(40)->format('Y-m-d H:i:s'), 'premium' => false], ['platform' => Platform::Android, 'app_version' => '1.9.5', 'last_active_at' => now()->subDays(20)], ['news' => true]);
    $make('carol', ['gender' => 'female', 'birth_month' => 7], ['platform' => Platform::Android, 'app_version' => '1.10.0', 'last_active_at' => now()->subDays(2)]);
    $make('dave', [], ['app_version' => '3.0.0', 'last_active_at' => now()->subDays(100)]);

    // 未ログインの端末
    Device::factory()->for($this->clientApp)->create(['app_user_id' => null, 'platform' => Platform::Ios, 'app_version' => '2.0.0', 'last_active_at' => now()]);
    // 送信できない端末（除外される）
    Device::factory()->for($this->clientApp)->create(['app_user_id' => $this->users['alice']->id, 'invalidated_at' => now()]);
    Device::factory()->for($this->clientApp)->create(['app_user_id' => $this->users['bob']->id, 'push_permission' => PushPermission::Denied]);
    Device::factory()->for($this->clientApp)->create(['app_user_id' => $this->users['bob']->id, 'fcm_token' => null]);

    // 別のアプリ（混ざらないこと）
    $other = App::factory()->create();
    AttributeDefinition::query()->create(['app_id' => $other->id, 'key' => 'gender', 'type' => AttributeType::String, 'label' => 'g']);
    $ou = AppUser::factory()->for($other)->create(['custom_attributes' => ['gender' => 'female']]);
    Device::factory()->for($other)->create(['app_user_id' => $ou->id]);
});

/**
 * 条件に一致する端末のユーザー名（未ログイン端末は "anon"）を返す。
 */
function matching(array $conditions): array
{
    $rows = app(SegmentQuery::class)->pushableDevices(test()->clientApp, $conditions)->select('u.name')->get();

    return $rows->map(fn ($r) => $r->name ?? 'anon')->sort()->values()->all();
}

function cond(string $field, string $operator, mixed $value = null): array
{
    return ['op' => 'and', 'conditions' => [['field' => $field, 'operator' => $operator, 'value' => $value]]];
}

it('returns all pushable devices for an empty segment', function () {
    expect(matching(['op' => 'and', 'conditions' => []]))->toBe(['alice', 'anon', 'bob', 'carol', 'dave']);
});

it('supports string operators', function () {
    expect(matching(cond('attr.gender', 'eq', 'female')))->toBe(['alice', 'carol'])
        ->and(matching(cond('attr.gender', 'neq', 'female')))->toBe(['bob'])
        ->and(matching(cond('attr.gender', 'in', ['male', 'other'])))->toBe(['bob'])
        ->and(matching(cond('attr.gender', 'not_in', ['male'])))->toBe(['alice', 'carol'])
        ->and(matching(cond('attr.gender', 'exists')))->toBe(['alice', 'bob', 'carol'])
        ->and(matching(cond('attr.gender', 'not_exists')))->toBe(['anon', 'dave']);
});

it('supports numeric operators', function () {
    expect(matching(cond('attr.birth_month', 'eq', 4)))->toBe(['alice'])
        ->and(matching(cond('attr.birth_month', 'gt', 4)))->toBe(['bob', 'carol'])
        ->and(matching(cond('attr.birth_month', 'gte', 7)))->toBe(['bob', 'carol'])
        ->and(matching(cond('attr.birth_month', 'lt', 7)))->toBe(['alice'])
        ->and(matching(cond('attr.birth_month', 'lte', 7)))->toBe(['alice', 'carol'])
        ->and(matching(cond('attr.birth_month', 'between', [4, 7])))->toBe(['alice', 'carol'])
        ->and(matching(cond('attr.birth_month', 'in', [4, 12])))->toBe(['alice', 'bob'])
        ->and(matching(cond('attr.birth_month', 'neq', 4)))->toBe(['bob', 'carol'])
        ->and(matching(cond('attr.total_amount', 'gte', '1000')))->toBe(['alice'])
        ->and(matching(cond('attr.total_amount', 'between', [100, 500])))->toBe(['bob']);
});

it('compares integers numerically, not as strings', function () {
    // '12' > '4' は文字列比較だと偽になる
    expect(matching(cond('attr.birth_month', 'gt', 10)))->toBe(['bob']);
});

it('supports date operators', function () {
    expect(matching(cond('attr.last_purchase_at', 'within_days', 7)))->toBe(['alice'])
        ->and(matching(cond('attr.last_purchase_at', 'before_days', 30)))->toBe(['bob'])
        ->and(matching(cond('attr.last_purchase_at', 'gte', now()->subDays(10)->toDateTimeString())))->toBe(['alice'])
        ->and(matching(cond('attr.last_purchase_at', 'between', [now()->subDays(50)->toDateTimeString(), now()->subDays(30)->toDateTimeString()])))->toBe(['bob'])
        ->and(matching(cond('attr.last_purchase_at', 'exists')))->toBe(['alice', 'bob']);
});

it('supports bool attributes', function () {
    expect(matching(cond('attr.premium', 'eq', true)))->toBe(['alice'])
        ->and(matching(cond('attr.premium', 'eq', false)))->toBe(['bob'])
        ->and(matching(cond('attr.premium', 'neq', true)))->toBe(['bob']);
});

it('supports device conditions', function () {
    expect(matching(cond('device.platform', 'eq', 'android')))->toBe(['bob', 'carol'])
        ->and(matching(cond('device.platform', 'in', ['ios'])))->toBe(['alice', 'anon', 'dave'])
        ->and(matching(cond('device.app_version', 'gte', '2.0')))->toBe(['alice', 'anon', 'dave'])
        ->and(matching(cond('device.app_version', 'lt', '1.10.0')))->toBe(['bob'])
        ->and(matching(cond('device.app_version', 'between', ['1.9.0', '1.10.0'])))->toBe(['bob', 'carol'])
        ->and(matching(cond('device.last_active_at', 'within_days', 7)))->toBe(['alice', 'anon', 'carol'])
        ->and(matching(cond('device.last_active_at', 'before_days', 30)))->toBe(['dave']);
});

it('supports login and notification category conditions', function () {
    expect(matching(cond('user.logged_in', 'eq', false)))->toBe(['anon'])
        ->and(matching(cond('user.logged_in', 'eq', true)))->toBe(['alice', 'bob', 'carol', 'dave'])
        // 初期値オン: alice だけオフにしている。未ログインは初期値
        ->and(matching(cond('category.campaign', 'eq', true)))->toBe(['anon', 'bob', 'carol', 'dave'])
        ->and(matching(cond('category.campaign', 'eq', false)))->toBe(['alice'])
        // 初期値オフ: bob だけオンにしている
        ->and(matching(cond('category.news', 'eq', true)))->toBe(['bob']);
});

it('combines AND, OR and nested groups', function () {
    $tree = ['op' => 'or', 'conditions' => [
        ['op' => 'and', 'conditions' => [
            ['field' => 'attr.gender', 'operator' => 'eq', 'value' => 'female'],
            ['field' => 'device.platform', 'operator' => 'eq', 'value' => 'android'],
        ]],
        ['field' => 'attr.birth_month', 'operator' => 'eq', 'value' => 12],
        ['op' => 'and', 'conditions' => [
            ['field' => 'user.logged_in', 'operator' => 'eq', 'value' => true],
            ['field' => 'attr.gender', 'operator' => 'not_exists'],
        ]],
    ]];
    expect(matching($tree))->toBe(['bob', 'carol', 'dave']);

    $and = ['op' => 'and', 'conditions' => [
        ['field' => 'attr.gender', 'operator' => 'eq', 'value' => 'female'],
        ['op' => 'or', 'conditions' => [
            ['field' => 'attr.birth_month', 'operator' => 'lt', 'value' => 5],
            ['field' => 'device.app_version', 'operator' => 'gte', 'value' => '1.10'],
        ]],
    ]];
    expect(matching($and))->toBe(['alice', 'carol']);
});

it('previews device and user counts', function () {
    $preview = app(SegmentQuery::class)->preview($this->clientApp, cond('device.platform', 'eq', 'ios'));
    expect($preview['devices'])->toBe(3)->and($preview['users'])->toBe(2)->and($preview['anonymous_devices'])->toBe(1);
});

it('checks whether a user matches a segment', function () {
    $q = app(SegmentQuery::class);
    expect($q->userMatches($this->clientApp, $this->users['alice'], cond('attr.gender', 'eq', 'female')))->toBeTrue()
        ->and($q->userMatches($this->clientApp, $this->users['bob'], cond('attr.gender', 'eq', 'female')))->toBeFalse()
        ->and($q->userMatches($this->clientApp, $this->users['bob'], cond('device.platform', 'eq', 'android')))->toBeTrue();
});

it('rejects invalid conditions', function (array $tree) {
    expect(fn () => app(SegmentCompiler::class)->validate($this->clientApp, $tree))->toThrow(ValidationException::class);
})->with([
    'unknown field' => [cond('attr.nope', 'eq', 'x')],
    'bad operator for type' => [cond('attr.gender', 'gt', 'a')],
    'within_days on string' => [cond('attr.gender', 'within_days', 3)],
    'int with string value' => [cond('attr.birth_month', 'eq', 'abc')],
    'between needs two values' => [cond('attr.birth_month', 'between', [1])],
    'in needs an array' => [cond('attr.birth_month', 'in', 3)],
    'negative days' => [cond('device.last_active_at', 'within_days', -1)],
    'bad platform' => [cond('device.platform', 'eq', 'windows')],
    'bad group op' => [['op' => 'xor', 'conditions' => []]],
    'too deep' => [['op' => 'and', 'conditions' => [['op' => 'and', 'conditions' => [['op' => 'and', 'conditions' => [['op' => 'and', 'conditions' => [['op' => 'and', 'conditions' => [['op' => 'and', 'conditions' => []]]]]]]]]]]]],
]);

it('produces parameterized SQL (no value is inlined)', function () {
    $sql = app(SegmentQuery::class)->pushableDevices($this->clientApp, cond('attr.gender', 'eq', "x' OR 1=1 --"))->toSql();
    expect($sql)->not->toContain('OR 1=1');
    expect(matching(cond('attr.gender', 'eq', "x' OR 1=1 --")))->toBe([]);
});
