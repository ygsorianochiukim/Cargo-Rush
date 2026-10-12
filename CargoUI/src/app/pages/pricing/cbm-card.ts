import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';

import { TruckCategory } from '../../models/pricing/pricing.model';
import { RateCardService } from '../../services/pricing/pricing.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';

@Component({
  selector: 'app-cbm-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon],
  template: `
    <app-card heading="Truck sizes" icon="fleet" [hint]="cardCbm() + ' cbm is the rate card'">
      <p class="cr-meta mb-3">
        A size with no rate of its own is priced off Current fleet: ÷ {{ cardCbm() }} cbm × its cbm.
      </p>

      <div class="overflow-x-auto">
        <table class="w-full border-collapse text-left text-[13px] whitespace-nowrap">
          <thead>
            <tr class="border-b border-cr-line">
              <th class="cr-th py-2 pr-3">Size</th>
              <th class="cr-th py-2 pr-3 text-right">CBM</th>
              <th class="cr-th py-2 pr-3 text-right">× rate</th>
              @if (fleet(); as f) {
                <th class="cr-th py-2 pr-3 text-right">Zone {{ f.code }} today</th>
              }
              <th class="py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr class="border-b border-cr-line/60 text-cr-ink-muted">
              <td class="py-2 pr-3">Current fleet</td>
              <td class="cr-num py-2 pr-3 text-right">{{ cardCbm() }}</td>
              <td class="cr-num py-2 pr-3 text-right">1.00</td>
              @if (fleet(); as f) {
                <td class="cr-num py-2 pr-3 text-right">{{ fmt.money(f.cents) }}</td>
              }
              <td class="py-2"></td>
            </tr>
            @for (size of sizes(); track size.id) {
              <tr class="border-b border-cr-line/60 last:border-0">
                @if (editing() === size.id) {
                  <td class="py-1.5 pr-3">
                    <input
                      #name
                      [value]="size.name"
                      aria-label="Size name"
                      [class]="cellClass + ' w-40'"
                    />
                  </td>
                  <td class="py-1.5 pr-3 text-right">
                    <input
                      #cbm
                      type="number"
                      min="1"
                      max="200"
                      [value]="size.cbm"
                      aria-label="CBM"
                      [class]="cellClass + ' w-20 text-right'"
                    />
                  </td>
                  <td class="py-1.5 pr-3" [attr.colspan]="fleet() ? 2 : 1"></td>
                  <td class="py-1.5 text-right">
                    <button
                      type="button"
                      class="h-8 rounded-control bg-cr-blue px-3 text-[12px] font-semibold text-cr-surface hover:bg-cr-blue-hover disabled:opacity-60"
                      [disabled]="busy()"
                      (click)="update(size, name.value, cbm.value)"
                    >
                      Save
                    </button>
                    <button
                      type="button"
                      class="ml-1 h-8 rounded-control px-3 text-[12px] font-semibold text-cr-ink-muted hover:bg-cr-tint"
                      (click)="editing.set(null)"
                    >
                      Cancel
                    </button>
                  </td>
                } @else {
                  <td class="py-2 pr-3 font-semibold">{{ size.name }}</td>
                  <td class="cr-num py-2 pr-3 text-right">{{ size.cbm }}</td>
                  <td class="cr-num py-2 pr-3 text-right">{{ multiplier(size) }}</td>
                  @if (fleet(); as f) {
                    <td class="cr-num py-2 pr-3 text-right font-semibold">
                      {{ fmt.money(scaled(f.cents, size)) }}
                    </td>
                  }
                  <td class="py-2 text-right">
                    <button
                      type="button"
                      [attr.aria-label]="'Edit ' + size.name"
                      class="h-8 w-8 rounded-control text-cr-ink-muted hover:bg-cr-tint hover:text-cr-ink"
                      (click)="editing.set(size.id)"
                    >
                      <app-icon name="edit" [size]="14" class="mx-auto" />
                    </button>
                    <button
                      type="button"
                      [attr.aria-label]="'Remove ' + size.name"
                      class="h-8 w-8 rounded-control text-cr-ink-muted hover:bg-cr-red-bg hover:text-cr-red"
                      (click)="remove(size)"
                    >
                      <app-icon name="close" [size]="14" class="mx-auto" />
                    </button>
                  </td>
                }
              </tr>
            } @empty {
              <tr>
                <td class="cr-meta py-3" colspan="5">No sizes yet. Add one below, e.g. 10 Wheeler · 54 cbm.</td>
              </tr>
            }
          </tbody>
        </table>
      </div>

      <form class="mt-4 flex flex-wrap items-end gap-2" (submit)="add($event, newName, newCbm)">
        <label class="flex flex-col gap-1 text-[12px] font-semibold">
          Size
          <input #newName placeholder="10 Wheeler" [class]="cellClass + ' h-9 w-44'" />
        </label>
        <label class="flex flex-col gap-1 text-[12px] font-semibold">
          CBM
          <input
            #newCbm
            type="number"
            min="1"
            max="200"
            placeholder="54"
            [class]="cellClass + ' h-9 w-24 text-right'"
          />
        </label>
        <button
          type="submit"
          class="h-9 rounded-control bg-cr-blue px-4 text-[13px] font-semibold text-cr-surface hover:bg-cr-blue-hover disabled:opacity-60"
          [disabled]="busy()"
        >
          Add size
        </button>
      </form>

      @if (notice(); as message) {
        <p
          [attr.role]="noticeIsError() ? 'alert' : 'status'"
          class="mt-3 text-[12px] font-medium"
          [class.text-cr-red]="noticeIsError()"
          [class.text-cr-green]="!noticeIsError()"
        >
          {{ message }}
        </p>
      }
    </app-card>
  `,
})
export class CbmCard {
  private readonly rateCard = inject(RateCardService);
  private readonly confirm = inject(Confirm);

  readonly categories = input<TruckCategory[]>([]);
  readonly cardCbm = input(28);
  readonly fleet = input<{ code: string; cents: number } | null>(null);
  readonly changed = output<void>();

  protected readonly fmt = fmt;
  protected readonly cellClass =
    'h-8 rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] focus:border-cr-blue focus:outline-none';

  protected readonly sizes = computed(() =>
    this.categories()
      .filter((category) => category.cbm !== null && category.cbm !== undefined)
      .sort((a, b) => (a.cbm as number) - (b.cbm as number)),
  );

  protected readonly editing = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly notice = signal<string | null>(null);
  protected readonly noticeIsError = signal(false);

  protected multiplier(size: TruckCategory): string {
    return ((size.cbm as number) / this.cardCbm()).toFixed(2);
  }

  protected scaled(cents: number, size: TruckCategory): number {
    return Math.round((cents * (size.cbm as number)) / this.cardCbm());
  }

  protected add(event: Event, name: HTMLInputElement, cbm: HTMLInputElement): void {
    event.preventDefault();

    const payload = this.read(name.value, cbm.value);
    if (payload === null) return;

    this.busy.set(true);
    this.rateCard.createCategory(payload).subscribe({
      next: (made) => {
        name.value = '';
        cbm.value = '';
        this.done(`${made.name} added.`);
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  protected update(size: TruckCategory, name: string, cbm: string): void {
    const payload = this.read(name, cbm);
    if (payload === null) return;

    this.busy.set(true);
    this.rateCard.updateCategory(size.id, payload).subscribe({
      next: () => {
        this.editing.set(null);
        this.done(`${payload.name} saved.`);
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  protected async remove(size: TruckCategory): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Remove ${size.name}?`,
      body: 'Trips already priced keep their figure. A size on trucks or rate lines is switched off instead.',
      confirmLabel: 'Remove',
      danger: true,
    });
    if (!ok) return;

    this.busy.set(true);
    this.rateCard.removeCategory(size.id).subscribe({
      next: ({ reason }) => this.done(reason ?? `${size.name} removed.`),
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  private read(name: string, cbm: string): { name: string; cbm: number } | null {
    const trimmed = name.trim();
    const size = Math.round(Number(cbm));

    if (trimmed === '' || !Number.isFinite(size) || size < 1 || size > 200) {
      this.say('Give the size a name and a cbm from 1 to 200.', true);
      return null;
    }

    return { name: trimmed, cbm: size };
  }

  private done(message: string): void {
    this.busy.set(false);
    this.say(message, false);
    this.changed.emit();
  }

  private fail(error: HttpErrorResponse): void {
    this.busy.set(false);

    const errors = error.error?.errors as Record<string, string[]> | undefined;
    const message =
      error.status === 0
        ? 'Cannot reach the server.'
        : error.status === 403
          ? 'This account cannot change the rate card.'
          : (Object.values(errors ?? {})[0]?.[0] ?? error.error?.message ?? 'That was not saved.');

    this.say(message, true);
  }

  private say(message: string, isError: boolean): void {
    this.notice.set(message);
    this.noticeIsError.set(isError);
  }
}
