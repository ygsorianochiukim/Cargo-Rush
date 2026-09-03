import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { StatusValue } from '../models/shared/status.model';
import { TONE_CLASS, TONE_DOT, statusLabel, toneFor } from './status';

/**
 * Status is never colour-alone: the pill always carries its text label, and a
 * dot gives a second, non-colour cue (DESIGN.md sections 1 and 9).
 */
@Component({
  selector: 'app-status-pill',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span
      class="inline-flex items-center gap-1.5 rounded-full px-2 py-[3px] text-[10px] font-semibold tracking-[0.06em] uppercase"
      [class]="toneClass()">
      <span class="h-1.5 w-1.5 rounded-full" [class]="dotClass()"></span>
      {{ label() }}
    </span>
  `,
})
export class StatusPill {
  readonly status = input.required<StatusValue>();

  protected readonly label = computed(() => statusLabel(this.status()));
  protected readonly toneClass = computed(() => TONE_CLASS[toneFor(this.status())]);
  protected readonly dotClass = computed(() => TONE_DOT[toneFor(this.status())]);
}
