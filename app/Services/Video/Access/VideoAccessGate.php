<?php

namespace App\Services\Video\Access;

use App\Models\App;
use App\Models\AppUser;
use App\Models\Video;
use Illuminate\Contracts\Container\Container;

class VideoAccessGate
{
    public function __construct(private readonly Container $container) {}

    public function check(App $app, Video $video, ?AppUser $user): AccessDecision
    {
        /** @var list<class-string<VideoAccessPolicy>> $policies */
        $policies = (array) config('eevee.video.access_policies', [DefaultVideoAccessPolicy::class]);
        foreach ($policies as $class) {
            $decision = $this->container->make($class)->check($app, $video, $user);
            if (! $decision->allowed) {
                return $decision;
            }
        }

        return AccessDecision::allow();
    }
}
