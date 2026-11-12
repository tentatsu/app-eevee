<?php

namespace App\Services\AppUsers;

use App\Models\AppUser;
use App\Models\Device;
use Carbon\CarbonInterface;

/**
 * アプリユーザーの Sanctum トークンを発行し、必要なら端末と紐づける。
 */
class AppUserTokenIssuer
{
    /**
     * @return array<string, mixed>
     */
    public function issue(AppUser $user, CarbonInterface $expiresAt, string $name = 'app', ?string $installationId = null): array
    {
        $token = $user->createToken($name, ['app'], $expiresAt);

        if ($installationId !== null) {
            $this->linkDevice($user, $installationId);
        }

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601ZuluString(),
            'user' => $user->toApiArray(),
        ];
    }

    public function linkDevice(AppUser $user, string $installationId): ?Device
    {
        $device = Device::query()->where('app_id', $user->app_id)->where('installation_id', $installationId)->first();
        if ($device !== null) {
            $device->forceFill(['app_user_id' => $user->id, 'last_active_at' => now()])->save();
        }

        return $device;
    }

    public function unlinkDevice(AppUser $user, string $installationId): ?Device
    {
        $device = Device::query()->where('app_id', $user->app_id)
            ->where('installation_id', $installationId)
            ->where('app_user_id', $user->id)
            ->first();
        $device?->forceFill(['app_user_id' => null])->save();

        return $device;
    }
}
