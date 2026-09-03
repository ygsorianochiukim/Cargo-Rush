import { ChangeDetectionStrategy, Component, inject, input, output } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';

import { IdentityService } from '../services/identity/identity.service';
import { Icon } from '../shared/icon';
import { Wordmark } from '../shared/wordmark';

/**
 * Sidebar — DESIGN.md section 4.
 *
 * Three stacked regions: brand, nav (grows and scrolls), user chip pinned to
 * the bottom. The nav list and the user chip both come from the API, and this
 * component keeps no list of its own.
 */
@Component({
  selector: 'app-sidebar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, RouterLinkActive, Icon, Wordmark],
  templateUrl: './sidebar.html',
})
export class Sidebar {
  private readonly identity = inject(IdentityService);
  private readonly router = inject(Router);

  /** Icons-only rail at narrower widths. */
  readonly collapsed = input(false);
  readonly navigate = output<void>();

  /** Already sorted and permission-filtered by the API; grouped here. */
  protected readonly groups = this.identity.navGroups;

  protected readonly me = this.identity.me;
  protected readonly initials = this.identity.initials;

  /**
   * Sign out.
   *
   * The redirect runs either way: a failed call still means the person asked
   * to leave, and the guard will send them back if the session turns out to
   * still be alive.
   */
  protected signOut(): void {
    this.identity.logout().subscribe({
      next: () => this.router.navigate(['/login']),
      error: () => this.router.navigate(['/login']),
    });
  }

  protected readonly skeletonRows = [0, 1, 2, 3, 4, 5, 6, 7];
}
