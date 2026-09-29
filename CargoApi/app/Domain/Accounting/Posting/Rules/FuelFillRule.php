<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Rules;

use App\Domain\Accounting\Posting\PostingRule;
use App\Domain\Finance\Models\Truck;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Model;

/**
 * A fill logged at `/fuel` that no sheet row carries.
 *
 * An active fill posts itself into its truck's Fuel column
 * (`FuelPostingService`), and that column is in `SheetDayRule` — so a posted
 * fill posts nothing here, or it would be in the books twice. What is left is
 * what Finance adds on its own: a fill for a vehicle no truck points at
 * (overhead) and one logged before posting existed. Dated the day it was
 * logged, as `FuelRepository::unpostedBetween()` dates it. A pending fill is a
 * request nobody approved and a cancelled one never happened.
 */
class FuelFillRule extends PostingRule
{
    public function postings(Model $source): array
    {
        /** @var FuelRecord $fill */
        $fill = $source;

        if ($fill->status !== StatusValue::Active || $fill->isPosted() || $fill->logged_at === null) {
            return [];
        }

        // Guarded: `where('vehicle_id', null)` would match a truck with no unit.
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
