<?php

namespace App\Services\Video\Access;

use App\Models\App;
use App\Models\AppUser;
use App\Models\Video;

/**
 * 有料の動画（required_entitlement がある）は、その権利を持つユーザーだけが再生できる。
 */
class EntitlementVideoAccessPolicy implements VideoAccessPolicy
{
    public function check(App $app, Video $video, ?AppUser $user): AccessDecision
    {
        if ($video->required_entitlement === null || $video->required_entitlement === '') {
            return AccessDecision::allow();
        }
        if ($user === null) {
            return AccessDecision::deny('login_required', 'Log in to watch this video.', 401);
        }
        if (! $user->hasEntitlement($video->required_entitlement)) {
            return AccessDecision::deny('entitlement_required', 'A subscription is required to watch this video.', 403);
        }

        return AccessDecision::allow();
    }
}
