import { ChangeDetectionStrategy, Component, booleanAttribute, input } from '@angular/core';

/**
 * Label + control + error wrapper, so every form in the app spaces and
 * announces its fields the same way (DESIGN.md section 8).
 */
@Component({
  selector: 'app-field',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <label class="block">
      <span class="cr-meta">
        {{ label() }}
        @if (required()) {
          <span class="text-cr-red" aria-hidden="true">*</span>
        }
      </span>
      <span class="mt-1.5 block">
        <ng-content />
      </span>
      @if (error()) {
        <span class="mt-1 block text-[12px] font-medium text-cr-red" role="alert">
          {{ error() }}
        </span>
      } @else if (hint()) {
        <span class="mt-1 block text-[12px] text-cr-ink-muted">{{ hint() }}</span>
      }
    </label>
  `,
})
export class Field {
  readonly label = input.required<string>();
  readonly hint = input<string>();
  readonly error = input<string | null>(null);
  readonly required = input(false, { transform: booleanAttribute });
}
