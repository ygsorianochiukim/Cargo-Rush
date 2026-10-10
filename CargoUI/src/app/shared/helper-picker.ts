import { ChangeDetectionStrategy, Component, computed, input, model } from '@angular/core';

import { Driver } from '../models/driver/driver.model';
import { Icon } from './icon';

const MAX_HELPERS = 5;

@Component({
  selector: 'app-helper-picker',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div role="group" [attr.aria-label]="label()">
      <span class="cr-meta">{{ label() }}</span>

      <div class="mt-1.5 flex flex-col gap-2">
        @if (chosen().length > 0) {
          <ul class="flex flex-wrap gap-1.5" aria-label="Helpers on this run">
            @for (helper of chosen(); track helper.id) {
              <li
                class="inline-flex h-8 items-center gap-1.5 rounded-full border border-cr-line bg-cr-tint pr-1 pl-3 text-[13px] font-medium text-cr-ink">
                {{ helper.name }}
                <button
                  type="button"
                  class="flex h-6 w-6 items-center justify-center rounded-full text-cr-ink-muted transition-colors hover:bg-cr-surface hover:text-cr-red"
                  [attr.aria-label]="'Remove ' + helper.name"
                  (click)="remove(helper.id)">
                  <app-icon name="close" [size]="12" />
                </button>
              </li>
            }
          </ul>
        }

        @if (chosen().length < max) {
          <select
            [class]="inputClass"
            [attr.aria-label]="chosen().length === 0 ? 'Add a helper' : 'Add another helper'"
            (change)="add($any($event.target))">
            <option value="">{{ chosen().length === 0 ? 'None — add a helper…' : 'Add another helper…' }}</option>
            @for (d of available(); track d.id) {
              <option [value]="d.id">{{ d.name }}</option>
            }
          </select>
        } @else {
          <p class="text-[12px] text-cr-ink-muted">That is the most one run can carry.</p>
        }
      </div>

      @if (error()) {
        <span class="mt-1 block text-[12px] font-medium text-cr-red" role="alert">{{ error() }}</span>
      } @else if (hint()) {
        <span class="mt-1 block text-[12px] text-cr-ink-muted">{{ hint() }}</span>
      }
    </div>
  `,
})
export class HelperPicker {
  readonly label = input('Helpers');
  readonly hint = input<string>();
  readonly error = input<string | null>(null);
  readonly drivers = input.required<Driver[]>();
  readonly driverId = input<string | null>(null);

  readonly value = model<string[]>([]);

  protected readonly max = MAX_HELPERS;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink focus:border-cr-blue focus:outline-none';

  protected readonly chosen = computed(() => {
    const byId = new Map(this.drivers().map((d) => [d.id, d]));

    return this.value()
      .filter((id) => id !== this.driverId())
      .map((id) => ({ id, name: byId.get(id)?.name ?? 'Former crew member' }));
  });

  protected readonly available = computed(() => {
    const taken = new Set([...this.value(), this.driverId()]);

    return this.drivers().filter((d) => !taken.has(d.id));
  });

  protected add(select: HTMLSelectElement): void {
    const id = select.value;

    select.value = '';

    if (id && !this.value().includes(id)) {
      this.value.set([...this.value(), id]);
    }
  }

  protected remove(id: string): void {
    this.value.set(this.value().filter((v) => v !== id));
  }
}
