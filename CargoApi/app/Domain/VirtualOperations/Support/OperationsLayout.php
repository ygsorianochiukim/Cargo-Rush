<?php

declare(strict_types=1);

namespace App\Domain\VirtualOperations\Support;

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use Illuminate\Support\Str;

class OperationsLayout
{
    public const ROOMS = [
        'drivers' => 'Driver Area',
        'helpers' => 'Helper Area',
        'dispatch' => 'Dispatch Center',
        'ai' => 'AI Agent Office',
        'warehouse' => 'Warehouse',
        'administration' => 'Administration Office',
        'management' => 'Management Office',
        'operations' => 'Operations Office',
        'finance' => 'Accounting & Finance',
        'hr' => 'Human Resources',
        'other' => 'Other Employees',
    ];

    public static function rooms(array $layout): array
    {
        $rooms = $layout['rooms'] ?? [];

        foreach (self::ROOMS as $id => $name) {
            $rooms[$id] ??= $name;
        }

        return $rooms;
    }

    public static function positionKey(Employee $employee): string
    {
        return $employee->position_id === null
            ? 'title:'.Str::lower(Str::squish($employee->position))
            : 'position:'.$employee->position_id;
    }

    public static function defaultRoom(?Position $position, string $title): string
    {
        return match ($position?->key ?? Str::slug($title)) {
            'driver', 'truck-driver', 'delivery-driver' => 'drivers',
            'helper', 'delivery-helper', 'truck-helper' => 'helpers',
            'dispatcher', 'dispatch-officer' => 'dispatch',
            'warehouse-staff', 'warehouse-personnel', 'warehouse-associate', 'warehouse-clerk' => 'warehouse',
            'administrator', 'admin', 'office-staff', 'administrative-assistant' => 'administration',
            'general-manager', 'manager', 'supervisor', 'operations-manager' => 'management',
            'operations-staff', 'operations-officer', 'operations-assistant', 'mechanic' => 'operations',
            'accountant', 'accounting-staff', 'finance-officer', 'treasury-officer' => 'finance',
            'hr-officer', 'hr-assistant', 'human-resources' => 'hr',
            default => 'other',
        };
    }

    public static function appearance(Employee $employee): array
    {
        $hash = hash('sha256', (string) $employee->getKey(), binary: true);

        return $employee->virtual_appearance ?? [
            'hair' => ord($hash[0]) % 4,
            'skin' => ord($hash[1]) % 5,
            'outfit' => 0,
            'accessory' => 0,
        ];
    }
}
