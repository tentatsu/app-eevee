<?php

namespace App\Http\Controllers\Org\Apps;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\NotificationCategory;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationCategoryController extends Controller
{
    public function index(Organization $organization, App $app): View
    {
        $categories = $app->notificationCategories()->get();

        return view('org.apps.categories', compact('organization', 'app', 'categories'));
    }

    public function store(Request $request, Organization $organization, App $app, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:'.NotificationCategory::KEY_PATTERN, Rule::unique('notification_categories')->where('app_id', $app->id)],
            'label' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        $category = NotificationCategory::query()->create($data + [
            'app_id' => $app->id,
            'default_enabled' => $request->boolean('default_enabled'),
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
        $audit->record('notification_category.created', $category, $data, '通知カテゴリを追加: '.$category->key);

        return back()->with('status', '通知カテゴリ「'.$category->label.'」を追加しました。');
    }

    public function update(Request $request, Organization $organization, App $app, NotificationCategory $category, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        $category->update($data + ['default_enabled' => $request->boolean('default_enabled'), 'sort_order' => $data['sort_order'] ?? 0]);
        $audit->record('notification_category.updated', $category, AuditLogger::diff($category), '通知カテゴリを更新: '.$category->key);

        return back()->with('status', '通知カテゴリを更新しました。');
    }

    public function destroy(Organization $organization, App $app, NotificationCategory $category, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        $category->delete();
        $audit->record('notification_category.deleted', $category, summary: '通知カテゴリを削除: '.$category->key);

        return back()->with('status', '通知カテゴリを削除しました。');
    }
}
