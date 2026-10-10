<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Resources;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin Account */
class AccountResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'label' => $this->label(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'normal_balance' => $this->normalBalance()->value,
            'permanent' => $this->type->isPermanent(),
            'group' => $this->group,
            'description' => $this->description,
            'is_system' => (bool) $this->is_system,
            'position' => $this->position,
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
