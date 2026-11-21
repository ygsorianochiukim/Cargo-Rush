import { ChangeDetectionStrategy, Component, inject } from '@angular/core';

import { Driver } from '../../models/driver/driver.model';
import { helperSpec } from '../../services/driver/helper.form';
import { DriverService } from '../../services/driver/driver.service';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

import { TableView } from '../../shared/table-view';

import { allPages } from '../../services/shared/all-pages';

@Component({
  selector: 'app-helpers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TableView, Card, Icon, StatusPill, SkeletonRows, EmptyState, ErrorState, ListToolbar],
  templateUrl: './helpers.page.html',
})
export class HelpersPage {
  private readonly driversApi = inject(DriverService);
  private readonly spec = helperSpec();

  protected readonly list = recordList<Driver>(this.spec, () =>
    allPages((page) => this.driversApi.helpers(page)),
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
