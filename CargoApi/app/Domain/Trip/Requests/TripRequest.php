<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Shared\Enums\CrewRole;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Requests\Concerns\GuardsTheTypedPrice;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TripRequest extends ApiFormRequest
{
    use GuardsTheTypedPrice;

    private const OFFICE_SETTABLE = [
        StatusValue::Scheduled->value,
        StatusValue::Assigned->value,
        StatusValue::Pending->value,
        StatusValue::Cancelled->value,
        StatusValue::Delivered->value,
    ];

    private const PAYMENT_STATUSES = ['unpaid', 'paid', 'partial'];

    private const PAYMENT_FIELDS = ['payment_status', 'paid_cents', 'paid_on', 'payment_method', 'payment_reference'];

    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],
            'new_customer' => ['sometimes', 'nullable', 'array'],
            'new_customer.name' => ['required_with:new_customer', 'string', 'max:160'],
            'new_customer.contact' => ['required_with:new_customer', 'string', 'max:160'],
            'new_customer.address' => ['nullable', 'string', 'max:255'],
            'origin' => [$required, 'string', 'max:160'],
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_lng'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_lat'],
            'destination' => [$required, 'string', 'max:160'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:destination_lng'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:destination_lat'],
            'cargo' => [$required, 'string', 'max:255'],
            'weight_kg' => [$required, 'integer', 'min:0', 'max:60000'],
            'pieces' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'handling' => ['nullable', 'string', 'max:255'],
            'price_cents' => $this->priceRules(),
            'driver_id' => ['nullable', 'string', Rule::exists('drivers', 'id')->where('role', CrewRole::Driver->value)],
            'helper_ids' => ['sometimes', 'nullable', 'array', 'max:5'],
            'helper_ids.*' => ['string', 'distinct', 'exists:drivers,id', 'different:driver_id'],
            'vehicle_id' => ['nullable', 'string', 'exists:vehicles,id'],

            'truck_category_id' => [
                'nullable', 'string',
                Rule::exists('truck_categories', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'pricing_zone_id' => [
                'nullable', 'string',
                Rule::exists('pricing_zones', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'status' => ['sometimes', Rule::in(self::OFFICE_SETTABLE)],
            'delivered_at' => ['nullable', 'date', 'before_or_equal:now'],
            'receiver_name' => ['nullable', 'string', 'max:120'],
            'payment_status' => ['sometimes', 'nullable', Rule::in(self::PAYMENT_STATUSES)],
            'paid_cents' => ['nullable', 'required_if:payment_status,partial', 'integer', 'min:1'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'payment_reference' => ['nullable', 'string', 'max:80'],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'dropoff_place' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => [$required, 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            'distance_total_m' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [$this->mustBeAllowedToPrice(...), $this->oneHaulierOnly(...), $this->mayRecordPayment(...), function (Validator $validator): void {
            if ($this->input('status') !== StatusValue::Delivered->value) {
                return;
            }

            $scheduled = $this->input('scheduled_at') ?? $this->route('trip')?->scheduled_at;

            if ($scheduled === null) {
                return;
            }

            $scheduled = Carbon::parse($scheduled);

            if ($scheduled->isFuture()) {
                $validator->errors()->add('status', 'Only a trip that already happened can be entered as delivered.');

                return;
            }

            if ($this->filled('delivered_at') && Carbon::parse($this->input('delivered_at'))->lt($scheduled)) {
                $validator->errors()->add('delivered_at', 'It cannot have been delivered before it was scheduled.');
            }
        }];
    }

    private function mayRecordPayment(Validator $validator): void
    {
        if (! in_array($this->input('payment_status'), ['paid', 'partial'], true)) {
            return;
        }

        abort_unless(
            (bool) $this->user()?->hasPermission('billing.manage'),
            403,
            'Only someone who manages Billing can record a payment on a trip.',
        );

        $delivered = $this->input('status') === StatusValue::Delivered->value
            || $this->route('trip')?->status === StatusValue::Delivered;

        if (! $delivered) {
            $validator->errors()->add('payment_status', 'Only a delivered trip has an invoice to pay.');
        }
    }

    private function oneHaulierOnly(Validator $validator): void
    {
        $trip = $this->route('trip');

        if (! $this->filled('vehicle_id') || $this->input('vehicle_id') === $trip?->vehicle_id) {
            return;
        }

        if ($this->filled('trucker_id') || $trip?->trucker_id !== null) {
            $validator->errors()->add(
                'vehicle_id',
                'A trucker is hauling this run in their own truck, so it cannot go out on a fleet unit as well. Release the trucker first.',
            );
        }
    }

    public function messages(): array
    {
        return [
            'driver_id.exists' => 'Pick someone from the driver roster to take the wheel.',
            'helper_ids.*.different' => 'A helper cannot be the same person as the driver.',
            'helper_ids.*.distinct' => 'The same helper is named twice.',
            'helper_ids.max' => 'A run can carry at most five helpers.',
            'eta.after_or_equal' => 'The ETA cannot be earlier than the scheduled departure.',
            'origin_lat.required_with' => 'A longitude needs its latitude.',
            'origin_lng.required_with' => 'A latitude needs its longitude.',
            'destination_lat.required_with' => 'A longitude needs its latitude.',
            'destination_lng.required_with' => 'A latitude needs its longitude.',
        ];
    }

    public function toData(): TripData
    {
        return TripData::fromArray($this->payload());
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $payload = collect($this->withoutUnmovedPrice($this->validated()))
            ->except(['new_customer', ...self::PAYMENT_FIELDS])
            ->all();

        return $this->createdCustomer === null
            ? $payload
            : [...$payload, 'customer_id' => $this->createdCustomer];
    }

    /** @return array{amount_cents: int|null, paid_on: string|null, method: string|null, reference: string|null}|null */
    public function payment(): ?array
    {
        $status = $this->validated('payment_status');

        if (! in_array($status, ['paid', 'partial'], true)) {
            return null;
        }

        return [
            'amount_cents' => $status === 'partial' ? (int) $this->validated('paid_cents') : null,
            'paid_on' => $this->validated('paid_on'),
            'method' => $this->validated('payment_method'),
            'reference' => $this->validated('payment_reference'),
        ];
    }

    private ?string $createdCustomer = null;

    /** @return array<string, string>|null */
    public function newCustomer(): ?array
    {
        $fields = $this->validated('new_customer');

        return is_array($fields) && ($fields['name'] ?? '') !== '' ? $fields : null;
    }

    public function useCustomer(string $customerId): void
    {
        $this->createdCustomer = $customerId;
    }
}
