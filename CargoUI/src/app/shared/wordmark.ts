import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

const NAME_EM = 8.16;

const MARK_ASPECT = 1.572;

const MARK_SCALE = 1.35;

const GAP = 10;

@Component({
  selector: 'app-wordmark',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (variant() === 'mark') {
      <img
        src="brand/logo-mark.png"
        alt="Cargo Rush"
        class="w-auto"
        [style.height.px]="markHeight()" />
    } @else {
      <span class="flex items-center" [style.gap.px]="gap">
        <img
          src="brand/logo-mark.png"
          alt=""
          aria-hidden="true"
          class="w-auto flex-none"
          [style.height.px]="markHeight()" />
        <span class="flex min-w-0 flex-col justify-center">
          <span class="cr-wordmark leading-none text-cr-blue" [style.font-size.px]="fontSize()">
            Cargo<span class="text-cr-red">Rush</span>
          </span>
          @if (tagline()) {
            <span class="mt-1 text-[11px] leading-none font-medium text-cr-ink-muted">
              Fleet Management System
            </span>
          }
        </span>
      </span>
    }
  `,
})
export class Wordmark {
  readonly variant = input<'full' | 'mark'>('full');

  readonly size = input(20);

  readonly maxWidth = input<number | null>(null);

  readonly tagline = input(true);

  protected readonly gap = GAP;

  protected readonly fontSize = computed(() => {
    const limit = this.maxWidth();
    if (limit === null) return this.size();

    const perPixel = NAME_EM + MARK_SCALE * MARK_ASPECT;

    return Math.max(10, Math.floor((limit - GAP) / perPixel));
  });

  protected markHeight() {
    return Math.round(this.fontSize() * MARK_SCALE);
  }
}
