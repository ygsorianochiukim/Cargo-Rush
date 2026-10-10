<?php

declare(strict_types=1);

namespace App\Domain\Driver\Services;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Repositories\DriverRepository;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;

class DriverService extends CrudService
{
    public function __construct(
        private readonly DriverRepository $drivers,
        private readonly NotificationService $notifications,
    ) {}

    protected function repository(): Repository
    {
        return $this->drivers;
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
