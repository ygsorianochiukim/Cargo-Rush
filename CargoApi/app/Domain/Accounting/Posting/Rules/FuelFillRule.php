<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Rules;

use App\Domain\Accounting\Posting\PostingRule;
use App\Domain\Finance\Models\Truck;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Model;

class FuelFillRule extends PostingRule
{
    public function postings(Model $source): array
    {
        /** @var FuelRecord $fill */
        $fill = $source;

        if ($fill->status !== StatusValue::Active || $fill->isPosted() || $fill->logged_at === null) {
            return [];
        }

        $truck = $fill->vehicle_id === null
            ? null
            : Truck::query()->where('vehicle_id', $fill->vehicle_id)->value('id');

        return [
            $this->posting(
                'fill',
                $fill->logged_at,
                JournalCategory::Fuel,
                trim('Fuel · '.($fill->receipt_no ?? '').($truck === null ? ' · fleet overhead' : ''), ' ·'),
            )->about(['truck_id' => $truck])
                ->debit($this->code('fuel'), (int) $fill->amount_cents, 'Fuel')
                ->credit($this->code('cash'), (int) $fill->amount_cents, 'Fuel'),
        ];
    }

    public function label(Model $source): string
    {
        /** @var FuelRecord $source */
        return 'fuel fill '.($source->receipt_no ?? $source->getKey());
    }
}
