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

abstract class ApiController extends Controller
{
    /** @param array<string, mixed> $meta */
    protected function item(JsonResource $resource, array $meta = [], int $status = 200): JsonResponse
    {
        return $resource->additional($meta === [] ? [] : ['meta' => $meta])
            ->response()
            ->setStatusCode($status);
    }

    /** @param array<string, mixed> $meta */
    protected function collection(
        ResourceCollection $resource,
        LengthAwarePaginator|Collection|null $source = null,
        array $meta = [],
    ): JsonResponse {
        $meta = array_merge($this->listMeta($source), $meta);

        $response = $resource->additional(['meta' => $meta])->response();

        if ($source instanceof LengthAwarePaginator) {
            $body = $response->getData(true);
            $body['meta'] = $meta;
            unset($body['links']);

            $response->setData($body);
        }

        return $response;
    }

    /**
     * @param  array<mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function payload(array $data, array $meta = []): JsonResponse
    {
        return response()->json(
            $meta === [] ? ['data' => $data] : ['data' => $data, 'meta' => $meta]
        );
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        return array_filter($request->only([
            'status', 'search', 'from', 'to',
            'driver_id', 'vehicle_id', 'customer_id', 'truck_id', 'direction',
            'trucker_id', 'hauled_by', 'booking_source',
        ]), static fn ($v) => $v !== null && $v !== '');
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return min(100, max(1, (int) $request->integer('per_page', $default)));
    }
}
