<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests\Concerns;

trait GuardsTheTypedPrice
{
    /** @return array<int, string> */
    protected function priceRules(): array
    {
        return ['sometimes', 'nullable', 'integer', 'min:0'];
    }

    protected function mustBeAllowedToPrice(): void
    {
        if (! $this->priceMoves()) {
            return;
        }

        abort_unless(
            (bool) $this->user()?->hasPermission('pricing.manage'),
            403,
            'Only someone who manages the Pricing card can enter a price on a trip. '
                .'Ask them to add a zone line, or to price this run.',
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function withoutUnmovedPrice(array $validated): array
    {
        if (array_key_exists('price_cents', $validated) && ! $this->priceMoves()) {
            unset($validated['price_cents']);
        }

        return $validated;
    }

    private function priceMoves(): bool
    {
        if (! $this->exists('price_cents')) {
            return false;
        }

        $trip = $this->route('trip');
        $sent = $this->input('price_cents');
        $sent = $sent === null || $sent === '' ? null : (int) $sent;

        if ($trip === null) {
            return $sent !== null;
        }

        return $sent !== $trip->price_cents;
    }
}
