import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { IsActiveMatchOptions, NavigationEnd, Router, RouterLink } from '@angular/router';
import { filter } from 'rxjs';

import { NavItem } from '../models/identity/identity.model';
import { IdentityService } from '../services/identity/identity.service';
import { Icon } from '../shared/icon';
import { Wordmark } from '../shared/wordmark';
import { AccountMenu } from './account-menu';

const pageMatch: IsActiveMatchOptions = {
  paths: 'subset',
  queryParams: 'ignored',
  matrixParams: 'ignored',
  fragment: 'ignored',
};

const sectionIcons: Record<string, string> = {
  Operations: 'dashboard',
  Fleet: 'fleet',
  'Sales & Billing': 'billing',
  Reports: 'trend',
  Accounting: 'wallet',
  People: 'customers',
  Custodian: 'shipments',
  Support: 'inbox',
  Administration: 'shield',
  Development: 'gauge',
};

@Component({
  selector: 'app-sidebar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AccountMenu, RouterLink, Icon, Wordmark],
  templateUrl: './sidebar.html',
})
export class Sidebar {
  private readonly identity = inject(IdentityService);
  private readonly router = inject(Router);
  private readonly navigationEnd = toSignal(
    this.router.events.pipe(filter((event) => event instanceof NavigationEnd)),
  );

  readonly collapsed = input(false);
  readonly navigate = output<void>();

  protected readonly groups = computed(
    () =>
      this.identity.navGroups()?.map((group) => ({
        ...group,
        id: 'sidebar-section-' + group.items[0].key,
        icon: sectionIcons[group.name] ?? group.items[0].icon,
        pending: group.items.reduce((total, item) => total + (item.badge ?? 0), 0),
      })) ?? null,
  );

  protected readonly activeItem = computed(() => {
    this.navigationEnd();
    let match: NavItem | null = null;
    for (const item of this.identity.navigation() ?? []) {
      if (
        item.route.length > (match?.route.length ?? 0) &&
        this.router.isActive(item.route, pageMatch)
      ) {
        match = item;
      }
    }
    return match;
  });

  protected readonly openSection = signal<string | null>(null);

  protected readonly skeletonRows = [0, 1, 2, 3, 4, 5, 6, 7];

  constructor() {
    effect(() => {
      const active = this.activeItem();
      this.openSection.set(active?.group ?? this.groups()?.[0]?.name ?? null);
    });
  }

  protected toggleSection(name: string): void {
    this.openSection.update((open) => (open === name ? null : name));
  }
}
