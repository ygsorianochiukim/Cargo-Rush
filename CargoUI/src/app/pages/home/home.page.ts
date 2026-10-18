import { ChangeDetectionStrategy, Component, inject } from '@angular/core';

import { IdentityService } from '../../services/identity/identity.service';
import { BusinessDashboardPage } from '../business-dashboard/business-dashboard.page';
import { DashboardPage } from '../dashboard/dashboard.page';

@Component({
  selector: 'app-home',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [BusinessDashboardPage, DashboardPage],
  template: `
    @if (business) {
      <app-business-dashboard />
    } @else {
      <app-dashboard />
    }
  `,
})
export class HomePage {
  protected readonly business = inject(IdentityService).can('finance.view');
}
