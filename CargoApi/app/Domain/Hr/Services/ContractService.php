<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\Contract;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use Illuminate\Support\Carbon;

class ContractService
{
    public function open(
        Employee $employee,
        PayBasis $basis,
        int $amountCents,
        Carbon|string|null $effectiveFrom = null,
        ?string $reason = null,
        ?EmploymentType $tier = null,
        ?User $author = null,
    ): Contract {
        $contract = new Contract([
            'employee_id' => $employee->getKey(),
            'pay_basis' => $basis->value,
            'tier' => ($tier ?? $employee->employment_type ?? EmploymentType::Regular)->tier()->value,
            'amount_cents' => max(0, $amountCents),
            'effective_from' => Carbon::parse($effectiveFrom ?? Carbon::now())->toDateString(),
            'reason' => $reason,
            'created_by' => $author?->getKey(),
        ]);

        $contract->save();

        $employee->unsetRelation('contracts');

        return $contract->refresh();
    }

    public function openFromPosition(
        Employee $employee,
        Position $position,
        Carbon|string|null $effectiveFrom = null,
        ?User $author = null,
    ): ?Contract {
        $type = $employee->employment_type ?? EmploymentType::Regular;
        $starting = $position->startingPay($type);

        if ($starting === null) {
            return null;
        }

        return $this->open(
            $employee,
            PayBasis::from($starting['pay_basis']),
            $starting['amount_cents'],
            $effectiveFrom ?? $employee->hired_on,
            sprintf('Hired into %s on the %s rate.', $position->name, $type->tier()->label()),
            $type,
            $author,
        );
    }

    public function raise(
        Employee $employee,
        int $amountCents,
        Carbon|string|null $effectiveFrom = null,
        ?string $reason = null,
        ?User $author = null,
    ): Contract {
        $current = $employee->contractOn();

        return $this->open(
            $employee,
            $current?->pay_basis ?? PayBasis::Monthly,
            $amountCents,
            $effectiveFrom,
            $reason,
            $current?->tier,
            $author,
        );
    }
}
