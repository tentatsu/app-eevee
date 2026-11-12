<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExpirePlaybackSessions extends Command
{
    protected $signature = 'eevee:playback:expire';

    protected $description = 'ハートビートが途絶えた視聴セッションを期限切れにする（Phase 9 で実装）';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
