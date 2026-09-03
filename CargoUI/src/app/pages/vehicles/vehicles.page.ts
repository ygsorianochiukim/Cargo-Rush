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

/** Vehicle Management — DESIGN.md section 5.1. */
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

  /**
   * A typical interval between services, used only to draw the progress bar.
   *
   * The due point itself is the vehicle's own `next_service_km`; this is just
   * how far back the bar starts filling from.
   */
  private readonly serviceIntervalKm = 20000;

  /** How far through the current service interval a vehicle is. */
  protected servicePct(v: Vehicle): number {
    const remaining = v.next_service_km - v.odometer_km;

    return Math.max(0, Math.min(100, Math.round((1 - remaining / this.serviceIntervalKm) * 100)));
  }

  protected serviceDue(v: Vehicle): boolean {
    return v.km_to_service <= 2000;
  }

  protected km = fmt.km;
  protected kg = fmt.kg;
}
