import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * A single ratio as a small ring — net margin, beside the net income figure.
 *
 * One value, so no legend: the number in the middle says it, and the ring is
 * only its shape. Green for a profit and red for a loss are the status
 * colours, and never carry the meaning alone — the percentage and the word
 * under it do.
 */
@Component({
  selector: 'app-margin-ring',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-none flex-col items-center" role="img" [attr.aria-label]="label() + ' ' + text()">
      <span class="relative block h-14 w-14">
      <svg viewBox="0 0 44 44" class="h-14 w-14 -rotate-90">
        <circle cx="22" cy="22" r="18" fill="none" stroke="#E5E7EB" stroke-width="5" />
        @if (fill() > 0) {
          <circle
            cx="22"
            cy="22"
            r="18"
            fill="none"
            [attr.stroke]="negative() ? '#A11807' : '#12805c'"
            stroke-width="5"
            stroke-linecap="round"
            [attr.stroke-dasharray]="fill() + ' ' + (circumference - fill())" />
        }
      </svg>
      <span
        class="cr-num absolute inset-0 flex items-center justify-center text-[11px] font-semibold"
        [class.text-cr-red]="negative()">
        {{ text() }}
      </span>
      </span>
      <span class="mt-1 text-[11px] text-cr-ink-muted">{{ label() }}</span>
    </div>
  `,
})
export class MarginRing {
  /** The ratio, 0–1 (negative for a loss). Null when there is nothing to divide by. */
  readonly ratio = input<number | null>(null);
  readonly label = input('margin');

  protected readonly circumference = 2 * Math.PI * 18;

  protected readonly negative = computed(() => (this.ratio() ?? 0) < 0);

  protected readonly fill = computed(() => {
    const r = Math.min(1, Math.abs(this.ratio() ?? 0));

    return r * this.circumference;
  });

  protected readonly text = computed(() => {
    const r = this.ratio();

    return r === null ? '—' : `${Math.round(r * 100)}%`;
  });
}
