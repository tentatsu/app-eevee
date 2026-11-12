<?php

namespace App\Console\Commands;

use App\Services\Video\PlaybackSessionManager;
use Illuminate\Console\Command;

class ExpirePlaybackSessions extends Command
{
    protected $signature = 'eevee:playback:expire';

    protected $description = 'ハートビートが途絶えた（初期値90秒）視聴セッションを期限切れにする（スケジューラーで毎分実行）';

    public function handle(PlaybackSessionManager $sessions): int
    {
        $count = $sessions->expireStale();
        if ($count > 0) {
            $this->info("{$count} 件の視聴セッションを期限切れにしました。");
        }

        return self::SUCCESS;
    }
}
