<?php

namespace App\Http\Controllers\Org\Apps;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Organization;
use App\Models\Segment;
use App\Services\Audit\AuditLogger;
use App\Services\Segments\SegmentCompiler;
use App\Services\Segments\SegmentFieldCatalog;
use App\Services\Segments\SegmentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SegmentController extends Controller
{
    public function index(Organization $organization, App $app): View
    {
        $segments = Segment::query()->latest('id')->get();

        return view('org.apps.segments.index', compact('organization', 'app', 'segments'));
    }

    public function create(Organization $organization, App $app, SegmentFieldCatalog $catalog): View
    {
        Gate::authorize('edit-content');
        $segment = new Segment(['conditions' => ['op' => 'and', 'conditions' => []]]);

        return view('org.apps.segments.form', $this->formData($organization, $app, $segment, $catalog));
    }

    public function store(Request $request, Organization $organization, App $app, SegmentCompiler $compiler, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $data = $this->validated($request, $app, $compiler);
        $segment = Segment::query()->create($data + ['app_id' => $app->id]);
        $audit->record('segment.created', $segment, ['name' => $segment->name, 'conditions' => $segment->conditions], 'セグメントを作成: '.$segment->name);

        return redirect()->route('org.apps.segments.edit', [$organization, $app, $segment])->with('status', 'セグメントを作成しました。');
    }

    public function edit(Organization $organization, App $app, Segment $segment, SegmentFieldCatalog $catalog): View
    {
        return view('org.apps.segments.form', $this->formData($organization, $app, $segment, $catalog));
    }

    public function update(Request $request, Organization $organization, App $app, Segment $segment, SegmentCompiler $compiler, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $segment->update($this->validated($request, $app, $compiler));
        $audit->record('segment.updated', $segment, AuditLogger::diff($segment), 'セグメントを更新: '.$segment->name);

        return back()->with('status', 'セグメントを保存しました。');
    }

    public function destroy(Organization $organization, App $app, Segment $segment, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $segment->delete();
        $audit->record('segment.deleted', $segment, summary: 'セグメントを削除: '.$segment->name);

        return redirect()->route('org.apps.segments.index', [$organization, $app])->with('status', 'セグメントを削除しました。');
    }

    /**
     * 送信前のプレビュー（対象のユーザー数と端末数）。保存前の条件でも計算できる。
     */
    public function preview(Request $request, Organization $organization, App $app, SegmentQuery $query, SegmentCompiler $compiler): JsonResponse
    {
        $conditions = $this->decodeConditions($request);
        try {
            $compiler->validate($app, $conditions);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()['conditions'] ?? []], 422);
        }

        return response()->json($query->preview($app, $conditions));
    }

    /**
     * @return array{name: string, description: string|null, conditions: array<array-key, mixed>}
     */
    private function validated(Request $request, App $app, SegmentCompiler $compiler): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:191'],
            'conditions' => ['required', 'string', 'max:65535'],
        ]);
        $conditions = $this->decodeConditions($request);
        $compiler->validate($app, $conditions);

        return ['name' => $data['name'], 'description' => $data['description'] ?? null, 'conditions' => $conditions];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeConditions(Request $request): array
    {
        $raw = $request->input('conditions');
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['conditions' => '条件の JSON が不正です。']);
        }

        return $decoded === [] ? ['op' => 'and', 'conditions' => []] : $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Organization $organization, App $app, Segment $segment, SegmentFieldCatalog $catalog): array
    {
        return [
            'organization' => $organization,
            'app' => $app,
            'segment' => $segment,
            'fields' => array_values(array_map(fn ($f) => $f->toArray(), $catalog->for($app))),
        ];
    }
}
