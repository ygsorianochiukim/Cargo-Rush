import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterOutlet } from '@angular/router';
import { filter, map, startWith } from 'rxjs';

import { IdentityService } from '../services/identity/identity.service';
import { NotificationService } from '../services/notification/notification.service';
import { ConfirmHost } from '../shared/confirm';
import { Icon } from '../shared/icon';
import { LedgerForm } from '../shared/ledger-form';
import { PaymentForm } from '../shared/payment-dialog';
import { RecordForm } from '../shared/record-form';
import { TripDialog } from '../shared/trip-dialog';
import { TripForm } from '../shared/trip-form';
import { Sidebar } from './sidebar';

type Breakpoint = 'wide' | 'rail' | 'drawer' | 'mobile';

@Component({
  selector: 'app-layout',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterOutlet,
    RouterLink,
    Sidebar,
    Icon,
    TripForm,
    LedgerForm,
    RecordForm,
    PaymentForm,
    ConfirmHost,
  ],
  templateUrl: './layout.html',
})
export class Layout {
  private readonly router = inject(Router);
  private readonly identity = inject(IdentityService);
  private readonly notifications = inject(NotificationService);
  private readonly tripDialog = inject(TripDialog);

  private readonly width = signal(typeof window === 'undefined' ? 1440 : window.innerWidth);

  protected readonly drawerOpen = signal(false);

  protected readonly unread = signal(0);

  protected readonly title = toSignal(
    this.router.events.pipe(
      filter((e) => e instanceof NavigationEnd),
      startWith(null),
      map(() => {
        let route = this.router.routerState.snapshot.root;
        while (route.firstChild) route = route.firstChild;

        return (route.data?.['title'] as string | undefined) ?? 'Dashboard';
      }),
    ),
    { initialValue: 'Dashboard' },
  );

  protected readonly breakpoint = computed<Breakpoint>(() => {
    const w = this.width();
    if (w >= 1280) return 'wide';
    if (w >= 1024) return 'rail';
    if (w >= 768) return 'drawer';

    return 'mobile';
  });

  protected readonly collapsed = computed(() => this.breakpoint() === 'rail');

  protected readonly overlay = computed(
    () => this.breakpoint() === 'drawer' || this.breakpoint() === 'mobile',
  );

  protected readonly compact = computed(() => this.breakpoint() === 'mobile');

  constructor() {
    this.identity.loadNavigation().subscribe();

    this.notifications.list({ per_page: 1 }).subscribe((res) => {
      this.unread.set(Number(res.meta?.['unread'] ?? 0));
    });

    if (typeof window !== 'undefined') {
      if (new URLSearchParams(window.location.search).get('new') === 'trip') {
        this.tripDialog.create();
      }

      window.addEventListener('resize', () => {
        this.width.set(window.innerWidth);
        if (!this.overlay()) this.drawerOpen.set(false);
      });
    }
  }

  protected newTrip(): void {
    this.tripDialog.create();
  }

  protected toggleDrawer(): void {
    this.drawerOpen.update((open) => !open);
  }

  protected closeDrawer(): void {
    this.drawerOpen.set(false);
  }
}
