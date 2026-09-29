import { ChangeDetectionStrategy, Component, inject, input, output } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';

import { IdentityService } from '../services/identity/identity.service';
import { Icon } from '../shared/icon';
import { Wordmark } from '../shared/wordmark';
import { AccountMenu } from './account-menu';

/**
 * Sidebar — DESIGN.md section 4.
 *
 * Three stacked regions: brand, nav (grows and scrolls), and the account chip
 * pinned to the bottom. The nav list and the chip both come from the API, and
 * this component keeps no list of its own.
 *
 * The chip is `AccountMenu`, which owns signing out and changing a password —
 * they are its business rather than the sidebar's, and keeping them there is
 * what stopped this template growing a dialog inside a navigation panel.
 */
@Component({
  selector: 'app-sidebar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AccountMenu, RouterLink, RouterLinkActive, Icon, Wordmark],
  templateUrl: './sidebar.html',
})
export class Sidebar {
  private readonly identity = inject(IdentityService);

  /** Icons-only rail at narrower widths. */
  readonly collapsed = input(false);
  readonly navigate = output<void>();

  /** Already sorted and permission-filtered by the API; grouped here. */
  protected readonly groups = this.identity.navGroups;

  protected readonly skeletonRows = [0, 1, 2, 3, 4, 5, 6, 7];
}
