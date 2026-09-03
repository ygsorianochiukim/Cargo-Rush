import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * The advance width of `CARGORUSH` in Race Sport, measured from the font's own
 * `hmtx` table: **8.156 em**.
 *
 * It is written down because it is the number that decides whether the lockup
 * fits. At 22px the name alone is 179px, and the sidebar's brand row has 208px
 * of content width for the mark *and* the name — which is how it came to
 * overflow the panel.
 */
const NAME_EM = 8.16;

/** The mark's own aspect, fixed by the asset (DESIGN.md section 2). */
const MARK_ASPECT = 1.572;

/**
 * The mark reads as the same weight as the type a little above cap height.
 */
const MARK_SCALE = 1.35;

/** Gap between the mark and the name, in pixels. */
const GAP = 10;

/**
 * The Cargo Rush lockup: the mark as an image, the company name as live text
 * set in Race Sport.
 *
 * The name is text rather than part of the PNG so it stays crisp at any size,
 * scales with the OS font setting, and is readable by a screen reader and by
 * search. The mark stays an image because it is a drawn shape, not type.
 *
 * **Sizing.** Pass `size` when you know the room you have; pass `maxWidth`
 * when the container decides. Given a `maxWidth` this picks the largest size
 * that fits, rather than trusting a number somebody typed and hoping — which
 * is what put the wordmark through the side of the sidebar.
 */
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
  /** `full` is the mark plus the name; `mark` is the arrows on their own. */
  readonly variant = input<'full' | 'mark'>('full');

  /** Cap height of the wordmark in pixels. Ignored when `maxWidth` is set. */
  readonly size = input(20);

  /**
   * Total width the whole lockup has to fit inside, in pixels.
   *
   * Takes precedence over `size`, because a lockup that fits is worth more
   * than a lockup at a particular size.
   */
  readonly maxWidth = input<number | null>(null);

  readonly tagline = input(true);

  protected readonly gap = GAP;

  protected readonly fontSize = computed(() => {
    const limit = this.maxWidth();
    if (limit === null) return this.size();

    // width = name + gap + mark, and both name and mark scale with font size:
    //   limit = size * NAME_EM + GAP + size * MARK_SCALE * MARK_ASPECT
    const perPixel = NAME_EM + MARK_SCALE * MARK_ASPECT;

    return Math.max(10, Math.floor((limit - GAP) / perPixel));
  });

  protected markHeight() {
    return Math.round(this.fontSize() * MARK_SCALE);
  }
}
