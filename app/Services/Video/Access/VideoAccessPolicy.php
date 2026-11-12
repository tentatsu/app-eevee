<?php

namespace App\Services\Video\Access;

use App\Models\App;
use App\Models\AppUser;
use App\Models\Video;

/**
 * 視聴権限の判定。config('eevee.video.access_policies') に並べたものを順に評価し、1つでも拒否すれば再生できない。
 *
 * 将来の有料プラン判定は、このインターフェースを実装したクラス（例: SubscriptionVideoAccessPolicy）を追加して
 * config に登録すれば差し込める。
 */
interface VideoAccessPolicy
{
    public function check(App $app, Video $video, ?AppUser $user): AccessDecision;
}
