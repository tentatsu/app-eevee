<?php

namespace App\Http\Controllers\Org\Apps;

use App\Enums\AttributeType;
use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\AttributeDefinition;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttributeDefinitionController extends Controller
{
    public function index(Organization $organization, App $app): View
    {
        $definitions = $app->attributeDefinitions()->get();

        return view('org.apps.attributes', compact('organization', 'app', 'definitions'));
    }

    public function store(Request $request, Organization $organization, App $app, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:'.AttributeDefinition::KEY_PATTERN, Rule::unique('attribute_definitions')->where('app_id', $app->id)],
            'type' => ['required', Rule::enum(AttributeType::class)],
            'label' => ['required', 'string', 'max:100'],
            'app_writable' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        $def = AttributeDefinition::query()->create($data + ['app_id' => $app->id, 'app_writable' => $request->boolean('app_writable'), 'sort_order' => $data['sort_order'] ?? 0]);
        $audit->record('attribute_definition.created', $def, $data, '属性の定義を追加: '.$def->key);

        return back()->with('status', '属性「'.$def->label.'」を追加しました。');
    }

    public function update(Request $request, Organization $organization, App $app, AttributeDefinition $attribute, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        // キーと型は既存データとの整合のため変更不可
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        $attribute->update($data + ['app_writable' => $request->boolean('app_writable'), 'sort_order' => $data['sort_order'] ?? 0]);
        $audit->record('attribute_definition.updated', $attribute, AuditLogger::diff($attribute), '属性の定義を更新: '.$attribute->key);

        return back()->with('status', '属性「'.$attribute->label.'」を更新しました。');
    }

    public function destroy(Organization $organization, App $app, AttributeDefinition $attribute, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        $attribute->delete();
        $audit->record('attribute_definition.deleted', $attribute, summary: '属性の定義を削除: '.$attribute->key);

        return back()->with('status', '属性「'.$attribute->label.'」の定義を削除しました（ユーザーに保存済みの値は残ります）。');
    }
}
