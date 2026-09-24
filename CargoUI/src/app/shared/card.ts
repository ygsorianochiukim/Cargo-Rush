import { ChangeDetectionStrategy, Component, input } from '@angular/core';

import { Icon } from './icon';

/** Card — DESIGN.md section 8. Surface, radius 12, shadow-card, 16px padding. */
@Component({
  selector: 'app-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  /**
   * A block, always — and this is a bug fix rather than a tidy-up.
   *
   * An Angular element is `display: inline` until something says otherwise, and
   * a block-level child of an inline box takes its containing block from the
   * nearest block *ancestor* instead. So the `h-full` below stopped resolving
   * against the card and started resolving against whatever block was above it
   * — on a page, the scroll container, which has a real height. A single card
   * with a figure in it then grew to fill the entire viewport, and Payables
   * showed ₱4,963.20 at the top of eight hundred empty pixels.
   *
   * Every call site that wrote `class="block"` was working around this without
   * anybody writing down why. Setting it here makes the comment below true —
   * the height really is inert outside a grid now — and the eleven bare
   * `<app-card>` elements across six pages stop depending on their parent.
   */
  host: { class: 'block' },
  template: `
    <!-- Full height so a card fills its grid row rather than shrinking to its
         own content: a row of cards with different amounts in them used to
         come out ragged, the short ones leaving a gap under the tallest. It is
         inert outside a grid, because the host is a block and its height is
         therefore its content's. -->
    <section
      class="flex h-full min-h-0 flex-col rounded-card bg-cr-surface shadow-card ring-1 ring-cr-line/60">
      @if (heading()) {
        <header class="flex items-center gap-3 border-b border-cr-line px-4 py-3">
          @if (icon()) {
            <app-icon [name]="icon()!" [size]="18" class="text-cr-blue" />
          }
          <h2 class="text-[16px] font-semibold">{{ heading() }}</h2>
          @if (hint()) {
            <span class="ml-auto text-[12px] text-cr-ink-muted">{{ hint() }}</span>
          }
          <ng-content select="[card-actions]" />
        </header>
      }
      <div class="min-h-0 flex-1" [class.p-4]="padded()">
        <ng-content />
      </div>
    </section>
  `,
})
export class Card {
  readonly heading = input<string>();
  readonly icon = input<string>();
  readonly hint = input<string>();
  readonly padded = input(true);
}
