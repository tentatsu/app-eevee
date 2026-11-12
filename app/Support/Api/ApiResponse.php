<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * API の統一レスポンス。
 *
 * 成功: {"data": ..., "meta": {...}}
 * 失敗: {"error": {"code": "machine_readable_code", "message": "...", "details": {...}}}
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, $status);
    }

    /**
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $paginator
     * @param  callable(TItem): array<string, mixed>  $map
     * @param  array<string, mixed>  $meta
     */
    public static function paginated(LengthAwarePaginator $paginator, callable $map, array $meta = []): JsonResponse
    {
        return self::ok(
            array_map($map, $paginator->items()),
            200,
            array_merge([
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ], $meta),
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
