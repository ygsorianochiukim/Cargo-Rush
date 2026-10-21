<?php

declare(strict_types=1);

namespace App\Domain\Asset\Controllers;

use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\AssetTurnover;
use App\Domain\Asset\Requests\AssetRequest;
use App\Domain\Asset\Requests\AssetTurnoverRequest;
use App\Domain\Asset\Resources\AssetResource;
use App\Domain\Asset\Resources\AssetTurnoverResource;
use App\Domain\Asset\Services\AssetReportService;
use App\Domain\Asset\Services\AssetService;
use App\Domain\Hr\Models\Employee;
use App\Domain\Printing\Printables\AssetReportPrintable;
use App\Domain\Printing\Services\PrintRenderer;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AssetController extends ApiController
{
    public function __construct(private readonly AssetService $assets) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            ...$this->filters($request),
            ...array_filter($request->only(['category_id', 'custodian_id']), static fn ($v) => $v !== null && $v !== ''),
        ];

        $page = $this->assets->paginate($filters, $this->perPage($request));

        return $this->collection(AssetResource::collection($page), $page);
    }

    public function show(Asset $asset): JsonResponse
    {
        return $this->item(new AssetResource($this->assets->find($asset->id)));
    }

    public function store(AssetRequest $request): JsonResponse
    {
        return $this->item(new AssetResource($this->assets->create($request->toData())), status: 201);
    }

    public function update(AssetRequest $request, Asset $asset): JsonResponse
    {
        return $this->item(new AssetResource($this->assets->update($asset, $request->toData())));
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $this->assets->delete($asset);

        return $this->noContent();
    }

    public function turnover(AssetTurnoverRequest $request, Asset $asset): JsonResponse
    {
        return $this->item(new AssetResource($this->assets->turnover($asset, $request->validated())));
    }

    public function turnovers(Asset $asset): JsonResponse
    {
        $rows = $asset->turnovers()
            ->with(['from', 'to', 'recorder'])
            ->orderByDesc('turned_over_on')
            ->orderByDesc('created_at')
            ->get();

        return $this->collection(AssetTurnoverResource::collection($rows), $rows);
    }

    public function report(AssetReportService $reports): JsonResponse
    {
        return $this->payload($reports->report());
    }

    public function reportPdf(AssetReportService $reports, AssetReportPrintable $printable, PrintRenderer $renderer): Response
    {
        $assets = Asset::query()->with(['category', 'custodian'])->orderBy('tag')->orderBy('name')->get();

        return $renderer->download(
            $printable,
            $printable->data($reports->report(), $assets),
            'asset-report-'.now()->format('Y-m-d').'.pdf',
        );
    }

    public function log(Request $request): JsonResponse
    {
        $rows = AssetTurnover::query()
            ->with(['asset' => fn ($q) => $q->withTrashed(), 'from', 'to', 'recorder'])
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('turned_over_on', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('turned_over_on', '<=', $to))
            ->when($request->query('employee_id'), fn ($q, $id) => $q->where(
                fn ($w) => $w->where('from_employee_id', $id)->orWhere('to_employee_id', $id),
            ))
            ->orderByDesc('turned_over_on')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return $this->collection(AssetTurnoverResource::collection($rows), $rows);
    }

    public function custodians(): JsonResponse
    {
        $people = Employee::query()
            ->onStaff()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'position', 'department']);

        return $this->payload($people->map(static fn (Employee $e) => [
            'value' => $e->id,
            'label' => $e->fullName(),
            'position' => $e->position,
            'department' => $e->department,
        ])->all());
    }
}
