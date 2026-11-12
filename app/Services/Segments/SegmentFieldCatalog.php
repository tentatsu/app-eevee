<?php

namespace App\Services\Segments;

use App\Enums\AttributeType;
use App\Enums\Platform;
use App\Models\App;
use App\Models\AttributeDefinition;
use App\Models\NotificationCategory;

/**
 * アプリごとに、セグメント条件で使えるフィールドの一覧を作る。
 *
 * - attr.{key}      : アプリユーザーの属性（属性の定義から）
 * - device.*        : 端末側の条件（platform / app_version / last_active_at / locale）
 * - user.*          : ログイン有無、登録日時、最終ログイン日時
 * - category.{key}  : 通知カテゴリのオン・オフ
 */
class SegmentFieldCatalog
{
    /** @var array<int, array<string, SegmentField>> */
    private array $cache = [];

    /**
     * @return array<string, SegmentField>
     */
    public function for(App $app): array
    {
        if (isset($this->cache[$app->id])) {
            return $this->cache[$app->id];
        }

        $fields = [];
        $definitions = AttributeDefinition::withoutGlobalScopes()->where('app_id', $app->id)->orderBy('sort_order')->orderBy('id')->get();
        foreach ($definitions as $def) {
            $type = match ($def->type) {
                AttributeType::String => SegmentField::TYPE_STRING,
                AttributeType::Int => SegmentField::TYPE_INT,
                AttributeType::Decimal => SegmentField::TYPE_DECIMAL,
                AttributeType::Date => SegmentField::TYPE_DATE,
                AttributeType::Bool => SegmentField::TYPE_BOOL,
            };
            $fields['attr.'.$def->key] = new SegmentField('attr.'.$def->key, '属性: '.$def->label, $type, 'attribute', 'u.custom_attributes', $def->key);
        }

        $fields['device.platform'] = new SegmentField('device.platform', '端末: OS', SegmentField::TYPE_STRING, 'column', 'd.platform', choices: array_map(fn (Platform $p) => $p->value, Platform::cases()));
        $fields['device.app_version'] = new SegmentField('device.app_version', '端末: アプリのバージョン', SegmentField::TYPE_VERSION, 'column', 'd.app_version_num');
        $fields['device.last_active_at'] = new SegmentField('device.last_active_at', '端末: 最終アクティブ日時', SegmentField::TYPE_DATE, 'column', 'd.last_active_at');
        $fields['device.locale'] = new SegmentField('device.locale', '端末: ロケール', SegmentField::TYPE_STRING, 'column', 'd.locale');
        $fields['user.logged_in'] = new SegmentField('user.logged_in', 'ユーザー: ログインしている', SegmentField::TYPE_BOOL, 'logged_in', 'u.id');
        $fields['user.created_at'] = new SegmentField('user.created_at', 'ユーザー: 登録日時', SegmentField::TYPE_DATE, 'column', 'u.created_at');
        $fields['user.last_login_at'] = new SegmentField('user.last_login_at', 'ユーザー: 最終ログイン日時', SegmentField::TYPE_DATE, 'column', 'u.last_login_at');

        $categories = NotificationCategory::withoutGlobalScopes()->where('app_id', $app->id)->orderBy('sort_order')->orderBy('id')->get();
        foreach ($categories as $cat) {
            $fields['category.'.$cat->key] = new SegmentField('category.'.$cat->key, '通知カテゴリ: '.$cat->label, SegmentField::TYPE_CATEGORY, 'category', 'u.notification_preferences', $cat->key, $cat->default_enabled);
        }

        return $this->cache[$app->id] = $fields;
    }

    public function forget(App $app): void
    {
        unset($this->cache[$app->id]);
    }
}
