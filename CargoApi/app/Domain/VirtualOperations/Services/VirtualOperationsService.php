<?php

declare(strict_types=1);

namespace App\Domain\VirtualOperations\Services;

use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\PhotoStore;
use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\VirtualOperations\Support\OperationsLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class VirtualOperationsService
{
    public function __construct(
        private readonly AssistantService $assistant,
        private readonly PhotoStore $photos,
    ) {}

    public function snapshot(Company $company): array
    {
        $layout = $company->virtual_operations_layout ?? [];
        $rooms = OperationsLayout::rooms($layout);
        $rules = $layout['rules'] ?? [];
        $staff = Employee::query()->onStaff()
            ->with(['jobPosition:id,key,name', 'driver:id,status', 'driver.vehicle:id,driver_id,plate'])
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')->get();

        $positions = [];
        foreach (Position::query()->get(['id', 'key', 'name']) as $position) {
            $positions['position:'.$position->id] = $this->position($rules, $rooms, 'position:'.$position->id, $position, $position->name);
        }

        $assignments = $this->assignments($staff->pluck('driver_id')->filter()->all());
        $employees = [];

        foreach ($staff as $employee) {
            $key = OperationsLayout::positionKey($employee);
            $positions[$key] ??= $this->position($rules, $rooms, $key, $employee->jobPosition, $employee->position);
            $assignment = $assignments[$employee->driver_id] ?? null;

            $employees[] = [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->fullName(),
                'position' => $employee->jobPosition?->name ?? $employee->position,
                'position_key' => $key,
                'department' => $employee->department,
                'employment_status' => $employee->status->value,
                'work_status' => $assignment !== null
                    ? ($this->onDelivery($assignment) ? 'on_delivery' : 'assigned')
                    : ($employee->driver?->status === StatusValue::Available ? 'available' : 'unknown'),
                'vehicle' => $assignment?->vehicle?->plate ?? $employee->driver?->vehicle?->plate,
                'delivery' => $assignment?->reference,
                'room' => $positions[$key]['room'],
                'photo_url' => $this->photos->url($employee->photo_path),
                'appearance' => OperationsLayout::appearance($employee),
            ];
        }

        foreach (array_keys($rules) as $key) {
            if (str_starts_with($key, 'title:')) {
                $positions[$key] ??= $this->position($rules, $rooms, $key, null, Str::title(substr($key, 6)));
            }
        }

        $dispatch = Trip::query()->onTheRoad()->selectRaw('status, count(*) as total')->groupBy('status')
            ->toBase()->pluck('total', 'status');

        return [
            'rooms' => collect($rooms)->map(fn (string $name, string $id): array => ['id' => $id, 'name' => $name])->values()->all(),
            'positions' => collect($positions)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'employees' => $employees,
            'yard' => $this->yard(),
            'dispatch' => [
                'assigned' => (int) ($dispatch['assigned'] ?? 0),
                'in_transit' => (int) ($dispatch['in_transit'] ?? 0),
                'overdue' => (int) ($dispatch['overdue'] ?? 0),
            ],
            'agent' => $this->assistant->officeStatus(),
        ];
    }

    private function yard(): array
    {
        $fleet = Vehicle::query()->select(['id', 'plate', 'model', 'status'])->withExists([
            'trips as assigned' => fn (Builder $trips) => $trips->whereIn('status', [StatusValue::Assigned->value, StatusValue::Overdue->value]),
            'trips as traveling' => fn (Builder $trips) => $trips->where(function (Builder $active): void {
                $active->where('status', StatusValue::InTransit->value)
                    ->orWhere(function (Builder $late): void {
                        $late->where('status', StatusValue::Overdue->value)
                            ->whereHas('dispatchRecord', fn (Builder $dispatch) => $dispatch
                                ->whereNotNull('dispatched_at')->whereNull('arrived_at'));
                    });
            }),
        ])->orderBy('plate')->get()->map(fn (Vehicle $truck): array => [
            'id' => $truck->id,
            'plate' => $truck->plate,
            'model' => $truck->model,
            'status' => $truck->status->value,
            'assigned' => $truck->assigned,
            'fleet_status' => $truck->traveling ? 'traveling' : match ($truck->status) {
                StatusValue::Active => 'active',
                StatusValue::Available => $truck->assigned ? 'active' : 'available',
                default => 'unavailable',
            },
        ]);

        return [
            'traveling' => $fleet->where('fleet_status', 'traveling')->count(),
            'parked' => $fleet->where('fleet_status', '!=', 'traveling')->values()->all(),
            'fleet' => $fleet->all(),
        ];
    }

    private function position(array $rules, array $rooms, string $key, ?Position $position, string $title): array
    {
        $default = OperationsLayout::defaultRoom($position, $title);
        $room = $rules[$key] ?? $default;

        return [
            'key' => $key,
            'name' => $position?->name ?? $title,
            'room' => isset($rooms[$room]) ? $room : $default,
            'default_room' => $default,
        ];
    }

    /** @param list<string> $crewIds
     * @return array<string, Trip>
     */
    private function assignments(array $crewIds): array
    {
        if ($crewIds === []) {
            return [];
        }

        $trips = Trip::query()->onTheRoad()->where(function (Builder $query) use ($crewIds): void {
            $query->whereIn('driver_id', $crewIds)
                ->orWhereHas('helpers', fn (Builder $helpers) => $helpers->whereKey($crewIds));
        })->with(['vehicle:id,plate', 'dispatchRecord:trip_id,dispatched_at', 'helpers:id'])
            ->orderByDesc('updated_at')->orderByDesc('id')->get();

        $wanted = array_fill_keys($crewIds, true);
        $assignments = [];
        foreach ($trips as $trip) {
            foreach ([$trip->driver_id, ...$trip->helperIds()] as $crewId) {
                if (isset($wanted[$crewId]) && (! isset($assignments[$crewId])
                    || ($this->onDelivery($trip) && ! $this->onDelivery($assignments[$crewId])))) {
                    $assignments[$crewId] = $trip;
                }
            }
        }

        return $assignments;
    }

    private function onDelivery(Trip $trip): bool
    {
        return $trip->status === StatusValue::InTransit
            || ($trip->status === StatusValue::Overdue && $trip->dispatchRecord?->dispatched_at !== null);
    }
}
