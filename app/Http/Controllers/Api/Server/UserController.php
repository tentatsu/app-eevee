<?php

namespace App\Http\Controllers\Api\Server;

use App\Enums\AuthMode;
use App\Http\Controllers\Api\ApiController;
use App\Models\App;
use App\Models\AppUser;
use App\Services\AppUsers\AppUserTokenIssuer;
use App\Services\AppUsers\AttributeNormalizer;
use App\Support\Api\ApiException;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * サーバー間API（`Authorization: Bearer {app_secret}`）。
 *
 * @tags サーバー間API
 */
class UserController extends ApiController
{
    /**
     * ユーザーの登録・更新（external 方式）
     *
     * 顧客のバックエンドが認証したユーザーを、`external_user_id` をキーに登録・更新する。属性は定義に従って検証し、マージする（`null` で削除）。
     */
    public function upsert(Request $request, string $externalUserId, AttributeNormalizer $normalizer): JsonResponse
    {
        $app = $this->requireExternal();
        $this->validateExternalId($externalUserId);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'attributes' => ['nullable', 'array'],
        ]);
        $changes = $normalizer->normalize($app, $data['attributes'] ?? [], fromApp: false);

        $user = DB::transaction(function () use ($app, $externalUserId, $data, $changes) {
            $user = AppUser::query()->lockForUpdate()->firstOrNew(['app_id' => $app->id, 'external_user_id' => $externalUserId]);
            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }
            if (array_key_exists('email', $data)) {
                $user->email = $data['email'] !== null ? mb_strtolower($data['email']) : null;
            }
            $user->custom_attributes = AttributeNormalizer::merge($user->custom_attributes, $changes);
            $user->save();

            return $user;
        });

        return ApiResponse::ok($user->toApiArray(), $user->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * ユーザーの取得
     */
    public function show(string $externalUserId): JsonResponse
    {
        return ApiResponse::ok($this->findByExternalId($externalUserId)->toApiArray());
    }

    /**
     * ユーザーの削除
     *
     * ユーザーとトークンを削除する。端末は残り、未ログインの端末として扱われる。
     */
    public function destroy(string $externalUserId): JsonResponse
    {
        $user = $this->findByExternalId($externalUserId);
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * アプリ用トークンの発行（external 方式）
     *
     * アプリに渡す短命のトークンを発行する。有効期限はアプリ設定の「external 方式のトークンの有効期限」。
     * `installation_id` を渡すと、その端末をユーザーに紐づける。
     */
    public function issueToken(Request $request, string $externalUserId, AppUserTokenIssuer $tokens): JsonResponse
    {
        $app = $this->requireExternal();
        $data = $request->validate(['installation_id' => ['nullable', 'uuid']]);
        $user = $this->findByExternalId($externalUserId);
        $user->forceFill(['last_login_at' => now()])->save();

        return ApiResponse::ok($tokens->issue($user, now()->addMinutes($app->external_token_ttl_minutes), 'external', $data['installation_id'] ?? null), 201);
    }

    /**
     * 属性の一括更新
     *
     * 最大 1000 件。各要素は `external_user_id` または `user_id` でユーザーを指定する。
     * 存在しないユーザーは `not_found` に入る（external 方式で `create_missing: true` を指定すると作成する）。
     */
    public function bulkAttributes(Request $request, AttributeNormalizer $normalizer): JsonResponse
    {
        $app = $this->currentApp();
        $data = $request->validate([
            'users' => ['required', 'array', 'min:1', 'max:1000'],
            'users.*.external_user_id' => ['nullable', 'string', 'max:191'],
            'users.*.user_id' => ['nullable', 'integer'],
            'users.*.attributes' => ['required', 'array'],
            'create_missing' => ['boolean'],
        ]);
        $createMissing = $request->boolean('create_missing') && $app->auth_mode === AuthMode::External;

        // 先にすべて検証する（1件でも不正なら何も更新しない）
        $normalized = [];
        foreach ($data['users'] as $i => $item) {
            if (empty($item['external_user_id']) && empty($item['user_id'])) {
                throw ValidationException::withMessages(["users.$i" => 'external_user_id or user_id is required.']);
            }
            $normalized[$i] = $normalizer->normalize($app, $item['attributes'], fromApp: false, errorPrefix: "users.$i.attributes");
        }

        $updated = 0;
        $notFound = [];
        DB::transaction(function () use ($app, $data, $normalized, $createMissing, &$updated, &$notFound) {
            foreach ($data['users'] as $i => $item) {
                $query = AppUser::query()->lockForUpdate();
                $user = ! empty($item['user_id'])
                    ? $query->find($item['user_id'])
                    : $query->where('external_user_id', $item['external_user_id'])->first();

                if ($user === null && $createMissing && ! empty($item['external_user_id'])) {
                    $user = new AppUser(['app_id' => $app->id, 'external_user_id' => $item['external_user_id']]);
                }
                if ($user === null) {
                    $notFound[] = $item['external_user_id'] ?? $item['user_id'];

                    continue;
                }
                $user->custom_attributes = AttributeNormalizer::merge($user->custom_attributes, $normalized[$i]);
                $user->save();
                $updated++;
            }
        });

        return ApiResponse::ok(['updated' => $updated, 'not_found' => $notFound]);
    }

    private function findByExternalId(string $externalUserId): AppUser
    {
        $user = AppUser::query()->where('external_user_id', $externalUserId)->first();
        if ($user === null) {
            throw new ApiException('user_not_found', 'The user was not found.', 404);
        }

        return $user;
    }

    private function validateExternalId(string $id): void
    {
        if ($id === '' || mb_strlen($id) > 191) {
            throw ValidationException::withMessages(['external_user_id' => 'Invalid external_user_id.']);
        }
    }

    private function requireExternal(): App
    {
        $app = $this->currentApp();
        if ($app->auth_mode !== AuthMode::External) {
            throw new ApiException('auth_mode_mismatch', 'This app does not use external authentication.', 409);
        }

        return $app;
    }
}
