<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuthMode;
use App\Mail\AppUserPasswordResetMail;
use App\Models\App;
use App\Models\AppUser;
use App\Models\AppUserPasswordReset;
use App\Services\AppUsers\AppUserTokenIssuer;
use App\Services\AppUsers\AttributeNormalizer;
use App\Support\Api\ApiException;
use App\Support\Api\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * @tags 認証（builtin）
 */
class AuthController extends ApiController
{
    public function __construct(private readonly AppUserTokenIssuer $tokens) {}

    /**
     * 会員登録（builtin 方式）
     *
     * メールアドレスとパスワードで登録し、トークンを発行する。`installation_id` を渡すと、その端末をユーザーに紐づける。
     *
     * @unauthenticated
     */
    public function register(Request $request, AttributeNormalizer $normalizer): JsonResponse
    {
        $app = $this->requireBuiltin();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191', Rule::unique('app_users', 'email')->where('app_id', $app->id)],
            'password' => ['required', 'string', Password::defaults(), 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
            'installation_id' => ['nullable', 'uuid'],
            'attributes' => ['nullable', 'array'],
        ]);
        $attributes = $normalizer->normalize($app, $data['attributes'] ?? [], fromApp: true);

        $user = AppUser::query()->create([
            'app_id' => $app->id,
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
            'name' => $data['name'] ?? null,
            'custom_attributes' => AttributeNormalizer::merge([], $attributes),
            'last_login_at' => now(),
        ]);

        return ApiResponse::ok($this->tokens->issue($user, $this->builtinExpiry(), installationId: $data['installation_id'] ?? null), 201);
    }

    /**
     * ログイン（builtin 方式）
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $app = $this->requireBuiltin();
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'installation_id' => ['nullable', 'uuid'],
        ]);

        $user = AppUser::query()->where('email', mb_strtolower($data['email']))->first();
        if ($user === null || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            throw new ApiException('invalid_credentials', 'The email or password is incorrect.', 401);
        }
        $user->forceFill(['last_login_at' => now()])->save();

        return ApiResponse::ok($this->tokens->issue($user, $this->builtinExpiry(), installationId: $data['installation_id'] ?? null));
    }

    /**
     * ログアウト
     *
     * 現在のトークンを無効にする。`installation_id` を渡すと、その端末とユーザーの紐づけを外す（builtin / external 共通）。
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $this->appUser($request);
        $data = $request->validate(['installation_id' => ['nullable', 'uuid']]);

        if (! empty($data['installation_id'])) {
            $this->tokens->unlinkDevice($user, $data['installation_id']);
        }
        $user->currentAccessToken()->delete();

        return ApiResponse::ok(['logged_out' => true]);
    }

    /**
     * パスワード再設定コードの送信
     *
     * 登録済みのメールアドレスに再設定コードを送る。メールアドレスが登録されているかどうかに関わらず 202 を返す。
     *
     * @unauthenticated
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $app = $this->requireBuiltin();
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = AppUser::query()->where('email', mb_strtolower($data['email']))->first();
        if ($user !== null) {
            $ttl = (int) config('eevee.app_users.password_reset_ttl_minutes', 60);
            $token = Str::upper(Str::random(10));
            AppUserPasswordReset::query()->where('app_user_id', $user->id)->delete();
            AppUserPasswordReset::query()->create([
                'app_user_id' => $user->id,
                'token_hash' => hash('sha256', $user->id.'|'.$token),
                'expires_at' => now()->addMinutes($ttl),
            ]);
            Mail::to($user->email)->send(new AppUserPasswordResetMail($app, $token, $ttl));
        }

        return ApiResponse::ok(['sent' => true], 202);
    }

    /**
     * パスワードの再設定
     *
     * メールで届いた再設定コードで新しいパスワードを設定する。成功するとそのユーザーの全トークンが無効になる。
     *
     * @unauthenticated
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $this->requireBuiltin();
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', Password::defaults(), 'max:191'],
        ]);

        $user = AppUser::query()->where('email', mb_strtolower($data['email']))->first();
        $reset = $user === null ? null : AppUserPasswordReset::query()
            ->where('app_user_id', $user->id)
            ->where('token_hash', hash('sha256', $user->id.'|'.Str::upper($data['token'])))
            ->first();

        if ($user === null || $reset === null || $reset->expires_at->isPast()) {
            throw new ApiException('invalid_reset_token', 'The reset token is invalid or expired.', 422);
        }

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => $data['password']])->save();
            AppUserPasswordReset::query()->where('app_user_id', $user->id)->delete();
            $user->tokens()->delete();
        });

        return ApiResponse::ok(['reset' => true]);
    }

    private function requireBuiltin(): App
    {
        $app = $this->currentApp();
        if ($app->auth_mode !== AuthMode::Builtin) {
            throw new ApiException('auth_mode_mismatch', 'This app does not use built-in authentication.', 409);
        }

        return $app;
    }

    private function builtinExpiry(): CarbonInterface
    {
        return now()->addDays((int) config('eevee.app_users.builtin_token_ttl_days', 365));
    }
}
