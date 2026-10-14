import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { map } from 'rxjs';

import { Driver } from '../../models/driver/driver.model';
import { helperSpec } from '../../services/driver/helper.form';
import { DriverService } from '../../services/driver/driver.service';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

@Component({
  selector: 'app-helpers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon, StatusPill, SkeletonRows, EmptyState, ErrorState, ListToolbar],
  templateUrl: './helpers.page.html',
})
export class HelpersPage {
  private readonly driversApi = inject(DriverService);
  private readonly spec = helperSpec();

  protected readonly list = recordList<Driver>(this.spec, () =>
    this.driversApi.helpers().pipe(map((res) => res.data)),
  );

  protected initials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((p) => p[0]?.toUpperCase() ?? '')
      .join('');
  }
}
