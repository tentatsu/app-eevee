<?php

namespace App\Services\Video\Access;

use App\Enums\VideoStatus;
use App\Enums\VideoVisibility;
use App\Models\App;
use App\Models\AppUser;
use App\Models\Video;

/**
 * 基本の判定: 再生可能な状態か、公開期間内か、メンバー限定ならログインしているか。
 */
class DefaultVideoAccessPolicy implements VideoAccessPolicy
{
    public function check(App $app, Video $video, ?AppUser $user): AccessDecision
    {
        if ($video->status !== VideoStatus::Ready || ! $video->isWithinPublishWindow()) {
            return AccessDecision::deny('video_not_available', 'The video is not available.', 404);
        }
        if ($video->visibility === VideoVisibility::Members && $user === null) {
            return AccessDecision::deny('login_required', 'Log in to watch this video.', 401);
        }

        return AccessDecision::allow();
    }
}
