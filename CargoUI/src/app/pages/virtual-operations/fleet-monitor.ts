import { ChangeDetectionStrategy, Component, computed, input, output, signal } from '@angular/core';
import { GarageTruck } from './garage-truck';

export type FleetStatus = 'active' | 'available' | 'unavailable' | 'traveling';

export interface YardTruck {
  id: string;
  plate: string;
  model: string;
  status: string;
  assigned: boolean;
  fleet_status: FleetStatus;
}

export interface YardSnapshot {
  parked: YardTruck[];
  traveling: number;
  fleet: YardTruck[];
}

export const fleetLabels: Record<FleetStatus, string> = {
  active: 'Active in yard',
  available: 'Available',
  unavailable: 'Unavailable',
  traveling: 'Traveling',
};

@Component({
  selector: 'app-fleet-monitor',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [GarageTruck],
  styleUrl: './fleet-monitor.css',
  template: `
    <section class="fleet-board" aria-label="Fleet monitor">
      <div class="fleet-heading">
        <div>
          <h3>Fleet monitor</h3>
          <p>{{ yard().parked.length }} in the yard · {{ yard().fleet.length }} total</p>
        </div>
        <input
          type="search"
          aria-label="Search fleet by plate or model"
          placeholder="Find a truck"
          [value]="query()"
          (input)="query.set($any($event.target).value)"
        />
      </div>
      <div class="fleet-filters" aria-label="Filter fleet status">
        @for (group of groups; track group) {
          <button
            type="button"
            [attr.data-status]="group"
            [attr.aria-pressed]="status() === group"
            (click)="status.set(group)"
          >
            <span class="status-dot"></span>{{ group === 'all' ? 'All trucks' : labels[group] }}
            <strong>{{ totals()[group] }}</strong>
          </button>
        }
      </div>
      <ul class="fleet-list" aria-label="Matching trucks">
        @for (truck of shown(); track truck.id) {
          <li>
            <button
              type="button"
              class="truck-card"
              [attr.data-status]="truck.fleet_status"
              (click)="selected.emit(truck.id)"
            >
              @if (truck.fleet_status !== 'traveling') {
                <app-garage-truck />
              }
              <span class="truck-name"
                ><strong>{{ truck.plate }}</strong
                ><small>{{ truck.model }}</small></span
              >
              <span class="truck-status"
                ><span class="status-dot"></span>{{ labels[truck.fleet_status] }}</span
              >
            </button>
          </li>
        } @empty {
          <li class="empty">No trucks match.</li>
        }
      </ul>
    </section>
  `,
})
export class FleetMonitor {
  readonly yard = input.required<YardSnapshot>();
  readonly selected = output<string>();

  protected readonly groups = ['all', 'available', 'active', 'traveling', 'unavailable'] as const;
  protected readonly status = signal<(typeof this.groups)[number]>('all');
  protected readonly query = signal('');
  protected readonly labels = fleetLabels;

  protected readonly totals = computed(() => {
    const totals = {
      all: this.yard().fleet.length,
      active: 0,
      available: 0,
      unavailable: 0,
      traveling: 0,
    };
    for (const truck of this.yard().fleet) totals[truck.fleet_status]++;
    return totals;
  });

  protected readonly shown = computed(() => {
    const status = this.status();
    const query = this.query().trim().toLocaleLowerCase();
    return this.yard().fleet.filter(
      (truck) =>
        (status === 'all' || status === truck.fleet_status) &&
        (!query ||
          truck.plate.toLocaleLowerCase().includes(query) ||
          truck.model.toLocaleLowerCase().includes(query)),
    );
  });
}
