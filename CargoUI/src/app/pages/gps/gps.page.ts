import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { GpsService } from '../../services/gps/gps.service';
import { GpsUnit } from '../../models/gps/gps.model';
import { TONE_DOT, toneFor } from '../../shared/status';
import { Card } from '../../shared/card';
import { Icon } from '../../shared/icon';
import { fmt } from '../../shared/format';
import { SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';

@Component({
  selector: 'app-gps',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon, StatusPill, SkeletonRows],
  templateUrl: './gps.page.html',
})
export class GpsPage {
  private readonly gpsApi = inject(GpsService);

  protected readonly units = toSignal(this.gpsApi.units(), {
    initialValue: null,
  });

  protected readonly selectedId = signal<string | null>(null);

  protected readonly selected = computed<GpsUnit | null>(() => {
    const list = this.units() ?? [];
    const id = this.selectedId();
    return list.find((u) => u.id === id) ?? list[0] ?? null;
  });

  protected readonly moving = computed(() => (this.units() ?? []).filter((u) => u.speed_kph > 0).length);
  protected readonly stopped = computed(() => (this.units() ?? []).filter((u) => u.speed_kph === 0).length);

  protected readonly toneDot = TONE_DOT;
  protected tone(u: GpsUnit) {
    return toneFor(u.status);
  }

  protected readonly fmt = fmt;

  protected pin(u: GpsUnit, axis: 'x' | 'y'): number {
    let h = 0;
    for (const ch of u.id + axis) h = (h * 31 + ch.charCodeAt(0)) % 1000;
    return 14 + (h % 72);
  }
}
