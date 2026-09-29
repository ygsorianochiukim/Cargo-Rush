<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests\Concerns;

/**
 * A price typed onto a trip is a rate-card decision, and only the people who
 * manage the card make it.
 *
 * The zone card prices every run; a typed figure overrules it for good (the
 * trip is marked `manual` and never re-derived). That is exactly the power
 * `pricing.manage` guards on the card itself, so a dispatcher who cannot edit
 * a zone line cannot route round it by typing a number on one trip either.
 *
 * Only a price that **moves** counts. Forms re-send the whole trip, and a
 * dispatcher correcting the cargo on a quoted run re-sends the figure the card
 * gave it; refusing that, or marking the run manual because of it, would punish
 * a save that changed nothing about the money. So an unchanged figure is
 * dropped from the payload before it reaches the service.
 *
 * Null is allowed and means "hand it back to the card" — also a pricing
 * decision, and guarded the same way.
 */
trait GuardsTheTypedPrice
{
    /** @return array<int, string> */
    protected function priceRules(): array
    {
        return ['sometimes', 'nullable', 'integer', 'min:0'];
    }

    /** Refuse a moved price from somebody who does not manage the card. */
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
     * The validated payload, less a price that did not move.
     *
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

        // On a booking there is nothing to move from, and a null is simply
        // "let the card price it" — which is what happens anyway.
        if ($trip === null) {
            return $sent !== null;
        }

        return $sent !== $trip->price_cents;
    }
}
