<?php

namespace App\Http\Controllers\Org\Apps;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\BillingPlan;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingException;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripePlatform;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * アプリの課金プラン（月額・年額の購読）と購読者。
 */
class BillingPlanController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function index(Organization $organization, App $app, StripePlatform $platform): View
    {
        $plans = BillingPlan::query()->orderBy('sort_order')->orderBy('id')->get();
        $counts = Subscription::query()->entitled()->select('billing_plan_id', DB::raw('count(*) as c'))->groupBy('billing_plan_id')->pluck('c', 'billing_plan_id');
        $subscriptions = Subscription::query()->with(['appUser', 'plan'])->latest('updated_at')->limit(50)->get();

        return view('org.apps.billing', [
            'organization' => $organization,
            'app' => $app,
            'plans' => $plans,
            'counts' => $counts,
            'subscriptions' => $subscriptions,
            'configured' => $platform->isConfigured(),
        ]);
    }

    public function store(Request $request, Organization $organization, App $app, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'entitlement' => ['required', 'string', 'regex:'.BillingPlan::ENTITLEMENT_PATTERN],
            'amount' => ['required', 'integer', 'min:50', 'max:1000000'],
            'interval' => ['required', Rule::in(['month', 'year'])],
        ], ['entitlement.regex' => '英小文字で始まる英小文字・数字・_（例: premium）で入力してください。']);

        $plan = BillingPlan::query()->create($data + ['app_id' => $app->id, 'currency' => 'jpy', 'is_active' => true]);
        try {
            $this->billing->createPlan($app, $plan);
        } catch (BillingException $e) {
            $plan->delete();

            return back()->withInput()->withErrors(['stripe' => 'Stripe にプランを作成できませんでした: '.$e->getMessage()]);
        }
        $audit->record('app.billing_plan.created', $plan, ['name' => $plan->name, 'amount' => $plan->amount, 'interval' => $plan->interval, 'entitlement' => $plan->entitlement], 'プランを作成: '.$plan->name);

        return back()->with('status', 'プランを作成しました。');
    }

    public function update(Request $request, Organization $organization, App $app, int $plan, AuditLogger $audit): RedirectResponse
    {
        $model = BillingPlan::query()->findOrFail($plan);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        $model->fill(['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => $request->boolean('is_active'), 'sort_order' => $data['sort_order'] ?? 0]);
        $changes = $model->getDirty();
        $model->save();
        try {
            $this->billing->updatePlan($model);
        } catch (BillingException $e) {
            return back()->withErrors(['stripe' => '保存しましたが、Stripe への反映に失敗しました: '.$e->getMessage()]);
        }
        $audit->record('app.billing_plan.updated', $model, $changes, 'プランを更新: '.$model->name);

        return back()->with('status', '保存しました。');
    }
}
