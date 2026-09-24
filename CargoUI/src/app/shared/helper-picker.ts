import { ChangeDetectionStrategy, Component, computed, input, model } from '@angular/core';

import { Driver } from '../models/driver/driver.model';
import { Icon } from './icon';

/** As many as a cab seats beside the driver. The API refuses a sixth. */
const MAX_HELPERS = 5;

/**
 * The run's helpers — any number of them, in the order they were added.
 *
 * A trip used to take one helper from a single select, which was the
 * workbook's shape and not the yard's: a heavy load goes out with two or three
 * people to lift it, and the others were left off the record. Chips for who is
 * on, and one select underneath to add the next, so the common case — one
 * helper or none — is still a single choice.
 *
 * Its own heading rather than an `app-field`, deliberately. That wraps its
 * content in a `<label>`, and a label holding several buttons hands a click on
 * its text to the first of them — which here would be the first helper's
 * remove button.
 *
 * The driver is left out of the list, and dropped from the chips if the desk
 * changes the driver to somebody already riding along: the same person in both
 * seats is paid for the run twice.
 */
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
  /** Everybody who could ride along — the roster, helpers and drivers alike. */
  readonly drivers = input.required<Driver[]>();
  /** Whoever is driving, so they are not offered as their own helper. */
  readonly driverId = input<string | null>(null);

  /** The chosen helpers' ids, in order. */
  readonly value = model<string[]>([]);

  protected readonly max = MAX_HELPERS;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink focus:border-cr-blue focus:outline-none';

  /**
   * The chips, by name. An id the roster no longer holds — somebody archived
   * since the trip was booked — still shows, so it can be seen and removed
   * rather than silently kept.
   */
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

    // Back to the prompt, so the same select can add the next one.
    select.value = '';

    if (id && !this.value().includes(id)) {
      this.value.set([...this.value(), id]);
    }
  }

  protected remove(id: string): void {
    this.value.set(this.value().filter((v) => v !== id));
  }
}
