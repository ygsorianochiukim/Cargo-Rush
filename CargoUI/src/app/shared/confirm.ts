import { ChangeDetectionStrategy, Component, Injectable, inject, signal } from '@angular/core';

import { Modal } from './modal';

export interface ConfirmOptions {
  title: string;
  body: string;
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
  icon?: string;
}

@Injectable({ providedIn: 'root' })
export class Confirm {
  readonly options = signal<ConfirmOptions | null>(null);
  private resolver: ((value: boolean) => void) | null = null;

  ask(options: ConfirmOptions): Promise<boolean> {
    this.resolver?.(false);
    this.options.set(options);
    return new Promise<boolean>((resolve) => {
      this.resolver = resolve;
    });
  }

  settle(value: boolean): void {
    this.options.set(null);
    const resolve = this.resolver;
    this.resolver = null;
    resolve?.(value);
  }
}

@Component({
  selector: 'app-confirm-host',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Modal],
  template: `
    @if (confirm.options(); as o) {
      <app-modal
        [open]="true"
        (openChange)="!$event && confirm.settle(false)"
        [title]="o.title"
        [icon]="o.icon ?? (o.danger ? 'incident' : 'bell')"
        [danger]="!!o.danger"
        size="sm">
        <p class="text-[14px] text-cr-ink-muted">{{ o.body }}</p>

        <ng-container modal-footer>
          <button
            type="button"
            class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-ink transition-colors hover:bg-cr-tint"
            (click)="confirm.settle(false)">
            {{ o.cancelLabel ?? 'Cancel' }}
          </button>
          <button
            type="button"
            class="h-10 rounded-control px-4 text-[14px] font-semibold text-cr-surface transition-colors"
            [class]="o.danger ? 'bg-cr-red hover:bg-cr-red-hover' : 'bg-cr-blue hover:bg-cr-blue-hover'"
            (click)="confirm.settle(true)">
            {{ o.confirmLabel ?? 'Confirm' }}
          </button>
        </ng-container>
      </app-modal>
    }
  `,
})
export class ConfirmHost {
  protected readonly confirm = inject(Confirm);
}
