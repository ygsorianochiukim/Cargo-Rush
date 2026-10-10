import { ChangeDetectionStrategy, Component, inject, input, output } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';

import { IdentityService } from '../services/identity/identity.service';
import { Icon } from '../shared/icon';
import { Wordmark } from '../shared/wordmark';
import { AccountMenu } from './account-menu';

@Component({
  selector: 'app-sidebar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AccountMenu, RouterLink, RouterLinkActive, Icon, Wordmark],
  templateUrl: './sidebar.html',
})
export class Sidebar {
  private readonly identity = inject(IdentityService);

  readonly collapsed = input(false);
  readonly navigate = output<void>();

  protected readonly groups = this.identity.navGroups;

  protected readonly skeletonRows = [0, 1, 2, 3, 4, 5, 6, 7];
}
