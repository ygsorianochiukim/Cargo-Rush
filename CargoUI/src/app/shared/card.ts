import { ChangeDetectionStrategy, Component, input } from '@angular/core';

import { Icon } from './icon';

/** Card — DESIGN.md section 8. Surface, radius 12, shadow-card, 16px padding. */
@Component({
  selector: 'app-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <!-- Full height so a card fills its grid row rather than shrinking to its
         own content: a row of cards with different amounts in them used to
         come out ragged, the short ones leaving a gap under the tallest. It is
         inert outside a grid, where the host height is auto anyway. -->
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
