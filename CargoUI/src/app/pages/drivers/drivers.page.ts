import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { map } from 'rxjs';

import { Driver } from '../../models/driver/driver.model';
import { driverSpec } from '../../services/driver/driver.form';
import { DriverService } from '../../services/driver/driver.service';
import { Card } from '../../shared/card';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

/** Drivers Management — DESIGN.md section 5.1. */
@Component({
  selector: 'app-drivers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon, StatusPill, SkeletonRows, EmptyState, ErrorState, ListToolbar],
  templateUrl: './drivers.page.html',
})
export class DriversPage {
  private readonly driversApi = inject(DriverService);
  private readonly spec = driverSpec();

  protected readonly list = recordList<Driver>(this.spec, () =>
    this.driversApi.list().pipe(map((res) => res.data)),
  );

  protected initials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((p) => p[0]?.toUpperCase() ?? '')
      .join('');
  }

  /** Flags a licence inside its final 90 days so it can be renewed in time. */
  protected expiringSoon(d: Driver): boolean {
    const days = (new Date(d.licence_expiry).getTime() - Date.now()) / 86_400_000;
    return days <= 90;
  }

  protected date = fmt.date;
}
