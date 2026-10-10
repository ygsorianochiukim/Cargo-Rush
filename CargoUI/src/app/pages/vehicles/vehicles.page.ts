import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { map } from 'rxjs';

import { Vehicle } from '../../models/vehicle/vehicle.model';
import { vehicleSpec } from '../../services/vehicle/vehicle.form';
import { VehicleService } from '../../services/vehicle/vehicle.service';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { recordList } from '../../shared/record-list';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

@Component({
  selector: 'app-vehicles',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, StatusPill, SkeletonRows, EmptyState, ErrorState],
  templateUrl: './vehicles.page.html',
})
export class VehiclesPage {
  private readonly vehiclesApi = inject(VehicleService);
  private readonly spec = vehicleSpec();

  protected readonly list = recordList<Vehicle>(this.spec, () =>
    this.vehiclesApi.list().pipe(map((res) => res.data)),
  );

  private readonly serviceIntervalKm = 20000;

  protected servicePct(v: Vehicle): number {
    const remaining = v.next_service_km - v.odometer_km;

    return Math.max(0, Math.min(100, Math.round((1 - remaining / this.serviceIntervalKm) * 100)));
  }

  protected serviceDue(v: Vehicle): boolean {
    return v.km_to_service <= 2000;
  }

  protected km = fmt.km;
  protected kg = fmt.kg;
  protected money = fmt.money;

  protected rate(bp: number): string {
    return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : 1)}%`;
  }
}
