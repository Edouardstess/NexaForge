<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Enveloppe unique : { data, meta, error }.
 *
 * Un client hors-ligne qui reçoit tantôt un objet nu, tantôt une enveloppe,
 * finit par parser au petit bonheur. Une seule forme, toujours.
 */
final class ApiResponse
{
    public static function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => (object) $meta,
            'error' => null,
        ], $status);
    }

    public static function created(mixed $data, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta, 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => (object) [],
            'error' => null,
        ], 200);
    }

    public static function paginated(LengthAwarePaginator $page, callable $transform): JsonResponse
    {
        return self::ok(
            array_map($transform, $page->items()),
            [
                'page' => $page->currentPage(),
                'page_size' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => (object) [],
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
            ], fn ($v) => $v !== null),
        ], $status);
    }
}
