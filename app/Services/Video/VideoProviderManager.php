<?php

namespace App\Services\Video;

use App\Models\App;
use App\Services\Video\Contracts\VideoProvider;
use Illuminate\Contracts\Container\Container;

/**
 * アプリの設定（apps.video_provider）に応じて動画プロバイダを選ぶ。
 */
class VideoProviderManager
{
    /** @var array<string, VideoProvider> */
    private array $overrides = [];

    public function __construct(private readonly Container $container) {}

    public function for(App $app): VideoProvider
    {
        $name = $app->video_provider;
        if (isset($this->overrides[$name])) {
            return $this->overrides[$name];
        }
        if (config('eevee.video.fake')) {
            return $this->container->make(FakeVideoProvider::class);
        }

        /** @var array<string, class-string<VideoProvider>> $providers */
        $providers = (array) config('eevee.video.providers', []);
        $class = $providers[$name] ?? null;
        if ($class === null) {
            throw new VideoProviderException("Unknown video provider: {$name}");
        }

        return $this->container->make($class);
    }

    /**
     * テストなどでプロバイダを差し替える。
     */
    public function extend(string $name, VideoProvider $provider): void
    {
        $this->overrides[$name] = $provider;
    }
}
