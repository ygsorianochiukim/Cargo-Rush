<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use Illuminate\Support\Carbon;

final class PayPeriod
{
    public const FIRST_HALF = 'first';

    public const SECOND_HALF = 'second';

    public const WHOLE_MONTH = 'month';

    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly int $index,
        public readonly int $count,
    ) {}

    public static function between(Carbon $start, Carbon $end, int $index, int $count): self
    {
        return new self($start->copy()->startOfDay(), $end->copy()->startOfDay(), $index, max(1, $count));
    }

    public function isFirst(): bool
    {
        return $this->index === 0;
    }

    public function isOnly(): bool
    {
        return $this->count === 1;
    }

    public function half(): string
    {
        return match (true) {
            $this->isOnly() => self::WHOLE_MONTH,
            $this->isFirst() => self::FIRST_HALF,
            default => self::SECOND_HALF,
        };
    }

    public function cutoff(): Carbon
    {
        return $this->end->copy();
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    public function label(): string
    {
        return self::formatRange($this->start, $this->end);
    }

    public function short(): string
    {
        return $this->start->isSameMonth($this->end)
            ? $this->start->format('j').'–'.$this->end->format('j')
            : $this->start->format('j M').'–'.$this->end->format('j');
    }

    public function matches(self $other): bool
    {
        return $this->start->isSameDay($other->start) && $this->end->isSameDay($other->end);
    }

    public static function formatRange(Carbon $start, Carbon $end): string
    {
        if ($start->isSameMonth($end)) {
            return $start->format('j').'–'.$end->format('j M Y');
        }

        return $start->isSameYear($end)
            ? $start->format('j M').'–'.$end->format('j M Y')
            : $start->format('j M Y').'–'.$end->format('j M Y');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'half' => $this->half(),
            'index' => $this->index,
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'label' => $this->label(),
            'short' => $this->short(),
            'cutoff' => $this->cutoff()->toDateString(),
            'days' => $this->days(),
        ];
    }
}
