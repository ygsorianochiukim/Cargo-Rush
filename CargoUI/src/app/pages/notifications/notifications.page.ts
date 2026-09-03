import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { NotificationService } from '../../services/notification/notification.service';
import { TONE_CLASS } from '../../shared/status';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';
import { EmptyState, SkeletonRows } from '../../shared/states';

/** Notification Management — DESIGN.md section 5.1. */
@Component({
  selector: 'app-notifications',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon, SkeletonRows, EmptyState],
  template: `
    <app-card
      heading="Incident notifications"
      icon="bell"
      [hint]="unread() + ' unread'"
      [padded]="false">
      @if (items(); as list) {
        @if (list.length === 0) {
          <app-empty-state
            icon="bell"
            title="You are all caught up"
            body="Incident and system notifications will appear here." />
        } @else {
          <ul class="flex flex-col">
            @for (n of list; track n.id) {
              <li
                class="flex gap-3 border-b border-cr-line/70 px-4 py-3.5 last:border-0"
                [class.bg-cr-tint]="!n.read">
                <span
                  class="flex h-9 w-9 flex-none items-center justify-center rounded-full"
                  [class]="toneClass[n.tone]">
                  <app-icon [name]="n.icon" [size]="18" />
                </span>
                <span class="min-w-0 flex-1">
                  <span class="flex items-center gap-2">
                    <span class="truncate text-[14px] font-semibold">{{ n.title }}</span>
                    @if (!n.read) {
                      <span class="cr-meta flex-none rounded-full bg-cr-blue px-1.5 text-cr-surface">
                        New
                      </span>
                    }
                  </span>
                  <span class="mt-0.5 block text-[13px] text-cr-ink-muted">{{ n.detail }}</span>
                </span>
                <span class="flex-none text-[11px] whitespace-nowrap text-cr-ink-muted">
                  {{ n.at }}
                </span>
              </li>
            }
          </ul>
        }
      } @else {
        <app-skeleton-rows [count]="5" />
      }
    </app-card>
  `,
})
export class NotificationsPage {
  private readonly notificationsApi = inject(NotificationService);

  protected readonly items = toSignal(this.notificationsApi.list().pipe(map((r) => r.data)), {
    initialValue: null,
  });

  protected readonly unread = computed(() => (this.items() ?? []).filter((n) => !n.read).length);

  protected readonly toneClass = TONE_CLASS;
}
