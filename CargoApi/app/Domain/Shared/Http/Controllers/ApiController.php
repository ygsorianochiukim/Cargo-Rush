<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * The envelope from DESIGN.md section 7.1, in one place.
 *
 *   single  { "data": {}, "meta": {} }
 *   list    { "data": [], "meta": { "page": 1, "per_page": 25, "total": 132 } }
 *
 * Controllers in every module extend this so no module can invent its own
 * response shape.
 */
abstract class ApiController extends Controller
{
    /**
     * @param  array<string, mixed>  $meta
     */
    protected function item(JsonResource $resource, array $meta = [], int $status = 200): JsonResponse
    {
        return $resource->additional($meta === [] ? [] : ['meta' => $meta])
            ->response()
            ->setStatusCode($status);
    }

    /**
     * A list. A paginator contributes the section 7.1 `meta`; a plain
     * collection gets a `total` so the client can render a count either way.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function collection(
        ResourceCollection $resource,
        LengthAwarePaginator|Collection|null $source = null,
        array $meta = [],
    ): JsonResponse {
        $meta = array_merge($this->listMeta($source), $meta);

        return $resource->additional(['meta' => $meta])->response();
    }

    /**
     * Raw payload for the read-only, computed endpoints (dashboard tiles,
     * profitability roll-ups) that have no model behind them.
     *
     * @param  array<mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function payload(array $data, array $meta = []): JsonResponse
    {
        return response()->json(
            $meta === [] ? ['data' => $data] : ['data' => $data, 'meta' => $meta]
        );
    }

    /** 204, for a destroy that has nothing to say. */
    protected function noContent(): JsonResponse
    {
        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function listMeta(LengthAwarePaginator|Collection|null $source): array
    {
        return match (true) {
            $source instanceof LengthAwarePaginator => [
                'page' => $source->currentPage(),
                'per_page' => $source->perPage(),
                'total' => $source->total(),
            ],
            $source instanceof Collection => [
                'page' => 1,
                'per_page' => $source->count(),
                'total' => $source->count(),
            ],
            default => [],
        };
    }

    /**
     * The list filters a module honours, pulled off the query string.
     *
     * Every module accepts the same vocabulary, so a client can page and
     * search anything the same way; a repository quietly ignores the keys it
     * has no column for.
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        return array_filter($request->only([
            'status', 'search', 'from', 'to',
            'driver_id', 'vehicle_id', 'customer_id', 'truck_id', 'direction',
        ]), static fn ($v) => $v !== null && $v !== '');
    }

    /** Page size, clamped so a client cannot ask for the whole table. */
    protected function perPage(Request $request, int $default = 25): int
    {
        return min(100, max(1, (int) $request->integer('per_page', $default)));
    }
}
