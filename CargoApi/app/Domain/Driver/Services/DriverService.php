<?php

declare(strict_types=1);

namespace App\Domain\Driver\Services;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Repositories\DriverRepository;
use App\Domain\Hr\Services\EmployeeService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DriverService extends CrudService
{
    public function __construct(
        private readonly DriverRepository $drivers,
        private readonly NotificationService $notifications,
        private readonly EmployeeService $employees,
    ) {}

    protected function repository(): Repository
    {
        return $this->drivers;
    }

    public function create(Data $data): Model
    {
        return DB::transaction(function () use ($data): Driver {
            /** @var Driver $driver */
            $driver = $this->drivers->create($data);

            $this->employees->enrolCrew($driver);

            return $driver->load('employee');
        });
    }

    public function update(Model $model, Data $data): Model
    {
        return DB::transaction(function () use ($model, $data): Driver {
            /** @var Driver $driver */
            $driver = $this->drivers->update($model, $data);

            $this->employees->syncCrew($driver);

            return $driver->load('employee');
        });
    }

    public function forUser(int $userId): ?Driver
    {
        return $this->drivers->findByUser($userId);
    }

    public function setAvailability(Driver $driver, bool $available): Driver
    {
        if ($driver->status === StatusValue::Active) {
            return $driver;
        }

        $driver->update([
            'status' => $available ? StatusValue::Available->value : StatusValue::Inactive->value,
        ]);

        return $driver->refresh();
    }

    public function flagExpiringLicences(int $days = 60): int
    {
        $drivers = $this->drivers->licencesExpiringWithin($days);

        foreach ($drivers as $driver) {
            $this->notifications->push(
                icon: 'profile',
                title: 'Licence expiring soon',
                detail: "{$driver->name} — licence expires {$driver->licence_expiry->format('j M Y')}",
                tone: Tone::Warning,
                userId: $driver->user_id,
            );
        }

        return $drivers->count();
    }
}
