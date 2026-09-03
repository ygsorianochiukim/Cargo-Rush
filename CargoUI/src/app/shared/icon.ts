import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { ICON_PATHS, IconName } from './icon-paths';

/**
 * The one `icon name -> shape` map for the web client (DESIGN.md section 7.3).
 * Strokes use `currentColor`, so an icon inherits the colour of its container.
 */
@Component({
  selector: 'app-icon',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <svg
      [attr.width]="size()"
      [attr.height]="size()"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      [attr.stroke-width]="strokeWidth()"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      focusable="false">
      <path [attr.d]="d()" />
    </svg>
  `,
  styles: `
    :host {
      display: inline-flex;
      flex: none;
    }
  `,
})
export class Icon {
  readonly name = input.required<string>();
  readonly size = input(20);
  readonly strokeWidth = input(2);

  protected readonly d = computed(() => ICON_PATHS[this.name() as IconName] ?? '');
}
