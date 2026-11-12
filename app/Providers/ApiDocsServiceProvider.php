<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * OpenAPI（Scramble）の設定。php artisan scramble:export で docs/openapi.json に出力する。
 */
class ApiDocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewApiDocs', fn ($user = null) => auth('system')->check() || auth('admin')->check());

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->components->addSecurityScheme('userToken', SecurityScheme::http('bearer')->setDescription('アプリユーザーのトークン（ログインAPI / トークン発行APIで取得）'));
                $openApi->components->addSecurityScheme('appSecret', SecurityScheme::http('bearer')->setDescription('サーバー間API用の app_secret（管理画面で発行）'));
            })
            ->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo) {
                self::fixPathParameters($operation, $routeInfo);

                $middleware = $routeInfo->route->gatherMiddleware();
                if (in_array('api.secret', $middleware, true)) {
                    $operation->addSecurity(new SecurityRequirement(['appSecret' => []]));
                } elseif (in_array('api.user', $middleware, true)) {
                    $operation->addSecurity(new SecurityRequirement(['userToken' => []]));
                } else {
                    // 未ログインでも呼べる（トークンを付けるとユーザーを特定する）
                    $operation->addSecurity([]);
                    $operation->addSecurity(new SecurityRequirement(['userToken' => []]));
                }
            });
    }

    /**
     * {app_key} はミドルウェアで解決するためコントローラーの引数にない。
     * Scramble が引数の位置でパスパラメータを対応づけてしまうので、ルートの定義どおりに直す。
     */
    private static function fixPathParameters(Operation $operation, RouteInfo $routeInfo): void
    {
        $route = $routeInfo->route;
        $operation->setPath('/'.preg_replace('#^api/v1/#', '', $route->uri()));

        $existing = [];
        $others = [];
        foreach ($operation->parameters as $param) {
            if ($param instanceof Parameter && $param->in === 'path') {
                $existing[$param->name] = $param;
            } else {
                $others[] = $param;
            }
        }

        $pathParams = [];
        foreach ($route->parameterNames() as $name) {
            $param = $existing[$name] ?? null;
            if ($param === null) {
                $param = new Parameter($name, 'path');
                $param->setSchema(Schema::fromType(new StringType));
                $param->description = match ($name) {
                    'app_key' => 'アプリの公開ID（管理画面のアプリ概要に表示）',
                    'external_user_id' => '顧客のシステムでのユーザーID',
                    'session' => '再生開始APIが返した session_id',
                    default => '',
                };
            }
            $param->required = true;
            $pathParams[] = $param;
        }
        $operation->parameters = array_merge($pathParams, $others);
    }
}
