<?php

use App\Http\Middleware\AuthenticateAppSecret;
use App\Http\Middleware\EnsureAppUserToken;
use App\Http\Middleware\EnsureOrganizationMember;
use App\Http\Middleware\ResetTenantContext;
use App\Http\Middleware\ResolveAppByKey;
use App\Http\Middleware\ResolveCurrentApp;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\RestrictOrganizationIp;
use App\Http\Middleware\RestrictSystemIp;
use App\Http\Middleware\TrustProxies;
use App\Support\Api\ApiException;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$isApiRequest = fn (Request $request): bool => $request->is('api/*', 'webhooks/*');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
        then: function () {
            Route::middleware('webhook')->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class);
        $middleware->prepend(ResetTenantContext::class);

        $middleware->alias([
            'org' => ResolveOrganization::class,
            'org.ip' => RestrictOrganizationIp::class,
            'org.member' => EnsureOrganizationMember::class,
            'org.app' => ResolveCurrentApp::class,
            'system.ip' => RestrictSystemIp::class,
            'api.app' => ResolveAppByKey::class,
            'api.user' => EnsureAppUserToken::class,
            'api.secret' => AuthenticateAppSecret::class,
        ]);

        $middleware->group('webhook', []);

        // テナントの解決は、ルートモデルバインディング（SubstituteBindings）より前に行う。
        // こうすることで子リソースのバインディングにもテナントのグローバルスコープがかかる。
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ResolveAppByKey::class,
            ResolveOrganization::class,
            RestrictOrganizationIp::class,
            RestrictSystemIp::class,
            AuthenticatesRequests::class,
            AuthenticateAppSecret::class,
            EnsureAppUserToken::class,
            EnsureOrganizationMember::class,
            ResolveCurrentApp::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('system', 'system/*')) {
                return route('system.login');
            }
            $org = $request->route('organization');
            $slug = is_object($org) ? $org->slug : (string) $org;

            return $slug !== '' ? route('org.login', ['organization' => $slug]) : '/';
        });

        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isApiRequest): void {
        $exceptions->dontReport([ApiException::class]);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isApiRequest($request) || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return match (true) {
                $e instanceof ApiException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->details),
                $e instanceof ValidationException => ApiResponse::error('validation_failed', 'The given data was invalid.', 422, ['fields' => $e->errors()]),
                $e instanceof AuthenticationException => ApiResponse::error('unauthenticated', 'Authentication is required.', 401),
                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error('forbidden', 'This action is not allowed.', 403),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error('not_found', 'The requested resource was not found.', 404),
                $e instanceof ThrottleRequestsException => ApiResponse::error('rate_limited', 'Too many requests.', 429)->withHeaders($e->getHeaders()),
                $e instanceof MethodNotAllowedHttpException => ApiResponse::error('method_not_allowed', 'The HTTP method is not allowed.', 405),
                $e instanceof HttpExceptionInterface => ApiResponse::error('http_error', $e->getMessage() ?: 'HTTP error.', $e->getStatusCode()),
                default => ApiResponse::error('server_error', config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.', 500),
            };
        });
    })->create();
