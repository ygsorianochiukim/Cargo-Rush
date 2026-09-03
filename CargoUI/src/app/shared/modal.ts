import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  HostListener,
  effect,
  inject,
  input,
  model,
  output,
  viewChild,
} from '@angular/core';

import { Icon } from './icon';

export type ModalSize = 'sm' | 'md' | 'lg';

const WIDTHS: Record<ModalSize, number> = { sm: 420, md: 560, lg: 820 };

/**
 * The one modal in the system (DESIGN.md section 8). Every dialog — create,
 * edit, detail, confirm — uses this shell, so focus handling, Escape, the
 * scrim and the header/footer layout are written once.
 *
 * ```html
 * <app-modal [(open)]="editing" title="Edit trip" size="md" (closed)="reset()">
 *   <p>…form…</p>
 *   <ng-container modal-footer>
 *     <button (click)="save()">Save</button>
 *   </ng-container>
 * </app-modal>
 * ```
 */
@Component({
  selector: 'app-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    @if (open()) {
      <div class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
        <div
          class="absolute inset-0 bg-cr-shell/50 motion-safe:animate-[cr-fade_.15s_ease-out]"
          (click)="dismiss()"
          aria-hidden="true"></div>

        <div
          #panel
          role="dialog"
          aria-modal="true"
          [attr.aria-labelledby]="titleId"
          [attr.aria-describedby]="subtitle() ? subtitleId : null"
          class="relative flex max-h-full w-full flex-col overflow-hidden rounded-panel bg-cr-surface shadow-panel motion-safe:animate-[cr-rise_.18s_ease-out]"
          [style.max-width.px]="width()">
          <header class="flex flex-none items-start gap-3 border-b border-cr-line px-5 py-4">
            @if (icon()) {
              <span
                class="flex h-9 w-9 flex-none items-center justify-center rounded-control"
                [class]="danger() ? 'bg-cr-red-bg text-cr-red' : 'bg-cr-tint text-cr-blue'">
                <app-icon [name]="icon()!" [size]="18" />
              </span>
            }
            <div class="min-w-0 flex-1">
              <h2 [id]="titleId" class="text-[16px] font-semibold">{{ title() }}</h2>
              @if (subtitle()) {
                <p [id]="subtitleId" class="mt-0.5 text-[13px] text-cr-ink-muted">
                  {{ subtitle() }}
                </p>
              }
            </div>
            <button
              type="button"
              class="-mr-1 flex h-8 w-8 flex-none items-center justify-center rounded-control text-cr-ink-muted transition-colors hover:bg-cr-tint hover:text-cr-ink"
              (click)="dismiss()"
              aria-label="Close dialog">
              <app-icon name="close" [size]="18" />
            </button>
          </header>

          <div class="cr-scroll min-h-0 flex-1 px-5 py-4">
            <ng-content />
          </div>

          <footer
            class="flex flex-none items-center justify-end gap-2 border-t border-cr-line bg-cr-tint/40 px-5 py-3">
            <ng-content select="[modal-footer]" />
          </footer>
        </div>
      </div>
    }
  `,
  styles: `
    @keyframes cr-fade {
      from {
        opacity: 0;
      }
    }
    @keyframes cr-rise {
      from {
        opacity: 0;
        transform: translateY(8px) scale(0.985);
      }
    }
  `,
})
export class Modal {
  private readonly host = inject(ElementRef<HTMLElement>);

  readonly open = model(false);
  readonly title = input.required<string>();
  readonly subtitle = input<string>();
  readonly icon = input<string>();
  readonly size = input<ModalSize>('md');
  /** Destructive dialogs tint the header icon red. */
  readonly danger = input(false);
  /** Blocks scrim-click and Escape while a request is in flight. */
  readonly locked = input(false);

  readonly closed = output<void>();

  private static seq = 0;
  private readonly uid = `cr-modal-${Modal.seq++}`;
  protected readonly titleId = `${this.uid}-title`;
  protected readonly subtitleId = `${this.uid}-subtitle`;

  private readonly panel = viewChild<ElementRef<HTMLElement>>('panel');
  private restoreTo: HTMLElement | null = null;

  protected width() {
    return WIDTHS[this.size()];
  }

  constructor() {
    effect(() => {
      if (this.open()) {
        this.restoreTo = document.activeElement as HTMLElement | null;
        // Wait for the panel to exist, then move focus into it.
        queueMicrotask(() => this.focusFirst());
      } else if (this.restoreTo) {
        this.restoreTo.focus?.();
        this.restoreTo = null;
      }
    });
  }

  protected dismiss(): void {
    if (this.locked()) return;
    this.open.set(false);
    this.closed.emit();
  }

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this.open()) this.dismiss();
  }

  /** Keeps Tab inside the dialog while it is open. */
  @HostListener('document:keydown.tab', ['$event'])
  @HostListener('document:keydown.shift.tab', ['$event'])
  protected onTab(raw: Event): void {
    const event = raw as KeyboardEvent;
    if (!this.open()) return;
    const items = this.focusable();
    if (items.length === 0) return;

    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && (active === first || !this.contains(active))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  private contains(node: Element | null): boolean {
    return !!node && !!this.panel()?.nativeElement.contains(node);
  }

  private focusable(): HTMLElement[] {
    const root = this.panel()?.nativeElement;
    if (!root) return [];
    return Array.from(
      root.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      ),
    ).filter((el) => el.offsetParent !== null);
  }

  private focusFirst(): void {
    const items = this.focusable();
    // Skip the close button when there is something more useful to focus.
    const target = items.find((el) => el.getAttribute('aria-label') !== 'Close dialog') ?? items[0];
    target?.focus();
  }
}
