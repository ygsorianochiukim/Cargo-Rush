import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { interval } from 'rxjs';

import { FleetMonitor, fleetLabels, YardSnapshot } from './fleet-monitor';
import { GarageTruck, truckBounds } from './garage-truck';
import {
  FloorPoint,
  ConversationPair,
  OfficePose,
  OfficeStation,
  StationVisit,
  employeeZone,
  homePositions,
  Zone,
  officePose,
  stationForEmployee,
  stations,
} from './office-activity';
import { OfficeDesk, discordDesk, officeAgents } from './office-agents';
import { OfficeCamera } from './office-camera';
import { officeCapacity } from './office-capacity';
import { bedCoverHeight, chairSeatHeight } from './office-furniture';
import { adminWorkstations } from './office-furniture-layout';
import { OfficePlan } from './office-plan';
import { project } from './office-projection';
import { Appearance, OperationsAvatar } from './operations-avatar';

export interface OfficePerson {
  id: string;
  name: string;
  room: string;
  position: string;
  appearance: Appearance;
  work_status: 'available' | 'assigned' | 'on_delivery' | 'unknown';
}

@Component({
  selector: 'app-office-yard',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FleetMonitor, GarageTruck, OfficeCamera, OfficePlan, OperationsAvatar],
  styleUrls: ['./office-yard.css', './office-motion.css'],
  templateUrl: './office-yard.html',
})
export class OfficeYard {
  readonly yard = input.required<YardSnapshot>();
  readonly people = input.required<OfficePerson[]>();
  readonly agentsReady = input.required<boolean>();
  readonly discordOnline = input.required<boolean>();
  readonly followedId = input<string | null>(null);
  readonly personSelected = output<string>();
  readonly truckSelected = output<string>();
  readonly deskSelected = output<OfficeDesk>();
  readonly stopFollowing = output<void>();

  private readonly time = signal(0);
  private readonly viewport = viewChild<ElementRef<HTMLDivElement>>('viewport');
  protected readonly camera = viewChild(OfficeCamera);
  private readonly visits = signal<Record<string, StationVisit>>({});
  protected readonly motion = signal(
    typeof matchMedia === 'undefined' || !matchMedia('(prefers-reduced-motion: reduce)').matches,
  );
  protected readonly notice = signal('');
  protected readonly zoomPercent = computed(() => Math.round((this.camera()?.zoom() ?? 1) * 100));
  private readonly counts = computed(
    () => {
      const counts: Record<Zone, number> = { crew: 0, garage: 0, admin: 0 };
      for (const person of this.people()) counts[employeeZone(person.room)]++;
      return counts;
    },
    { equal: (a, b) => a.crew === b.crew && a.garage === b.garage && a.admin === b.admin },
  );
  private readonly fleetSize = computed(() => this.yard().fleet.length);
  protected readonly capacity = computed(() => officeCapacity(this.counts(), this.fleetSize()));
  protected readonly width = computed(() => this.capacity().width);
  protected readonly height = computed(() => this.capacity().height);
  protected readonly stations = computed(() =>
    stations.map((station) => {
      const y = station.y + (station.zone === 'garage' ? this.capacity().garageExtension : 0);
      return { ...station, y, screen: project({ x: station.x, y }) };
    }),
  );
  protected readonly fleetLabels = fleetLabels;
  protected readonly truckWidth = computed(() => (truckBounds.width / this.width()) * 100);
  protected readonly truckAnchor = `translate(${(-truckBounds.left / truckBounds.width) * 100}%, ${(-truckBounds.top / truckBounds.height) * 100}%)`;
  protected readonly desks = ([...officeAgents, discordDesk] satisfies OfficeDesk[]).map(
    (desk) => ({ ...desk, screen: project(desk, chairSeatHeight) }),
  );

  protected readonly parking = computed(() => {
    const { fleet } = this.yard();
    return this.capacity().garage.flatMap(({ bays }) =>
      bays.flatMap((bay) => {
        const truck = fleet[bay.number - 1];
        if (!truck || truck.fleet_status === 'traveling') return [];
        return [{ truck, screen: project({ x: bay.center, y: bay.bumper }) }];
      }),
    );
  });

  protected readonly placement = computed(() => {
    const { homes } = this.capacity();
    const nextSlot = { crew: 0, garage: 0, admin: 0 };
    const anchors = this.people().map((person) => {
      const zone = employeeZone(person.room);
      const slot = nextSlot[zone]++;
      return { person, zone, slot, home: homes[zone][slot] };
    });
    const partners = new Map<string, ConversationPair>();
    for (const zone of ['crew', 'admin'] as const) {
      const present = anchors.filter(
        (anchor) => anchor.zone === zone && anchor.person.work_status !== 'on_delivery',
      );
      for (let index = 0; index + 1 < present.length; index += 2) {
        partners.set(present[index].person.id, { key: index / 2, side: 0 });
        partners.set(present[index + 1].person.id, { key: index / 2, side: 1 });
      }
    }
    return { anchors, partners };
  });

  protected readonly actors = computed(() => {
    const time = this.time();
    const visits = this.visits();
    const { anchors, partners } = this.placement();
    const { garageExtension, height } = this.capacity();
    return anchors.map((anchor) => {
      const { home, person, zone, slot } = anchor;
      const visit = visits[person.id];
      const pose: OfficePose =
        person.work_status === 'on_delivery'
          ? { ...home, posture: 'standing', label: 'On delivery', icon: '↗', walking: false }
          : officePose(
              home,
              zone,
              slot,
              time,
              visit?.station.zone === zone &&
                (zone !== 'garage' || visit.garageOffset === garageExtension)
                ? visit
                : undefined,
              partners.get(person.id),
              garageExtension,
            );
      const screen = project(pose, pose.posture === 'sleeping' ? bedCoverHeight : 0);
      const facing =
        pose.facing ??
        (pose.heading && project(pose.heading).x < project({ x: 0, y: 0 }).x
          ? ('left' as const)
          : ('right' as const));
      const atDesk =
        zone === 'admin' &&
        pose.posture === 'working' &&
        (slot < adminWorkstations.length || slot >= homePositions.admin.length);
      return {
        ...anchor,
        pose,
        screen,
        facing,
        atDesk,
        depth: 200 + Math.round((screen.y / height) * 1000),
      };
    });
  });

  protected readonly followed = computed(() =>
    this.actors().find((actor) => actor.person.id === this.followedId()),
  );

  constructor() {
    interval(140)
      .pipe(takeUntilDestroyed())
      .subscribe(() => {
        if (this.motion()) this.time.update((time) => time + 140);
      });

    effect(() => {
      this.capacity();
      const camera = this.camera();
      if (camera?.fitted())
        queueMicrotask(() => {
          if (camera.fitted()) camera.fit();
        });
    });

    effect(() => {
      const id = this.followedId();
      const viewport = this.viewport()?.nativeElement;
      if (!id || !viewport) return;

      queueMicrotask(() => {
        if (this.followedId() !== id || !viewport.isConnected) return;
        viewport.scrollIntoView({ block: 'nearest' });
        const actor = this.followed();
        if (actor) this.panTo(actor.pose, 'auto');
        viewport.focus({ preventScroll: true });
      });
    });

    effect(() => {
      const actor = this.followed();
      if (actor)
        this.camera()?.keepVisible(actor.screen.x / this.width(), actor.screen.y / this.height());
    });
  }

  protected toggleMotion(): void {
    this.motion.update((running) => !running);
    if (this.motion()) this.notice.set('');
  }

  protected visit(station: OfficeStation): void {
    const followed = this.followed();
    if (
      this.followedId() &&
      (!followed || followed.zone !== station.zone || followed.person.work_status === 'on_delivery')
    ) {
      this.notice.set(
        'Choose a station in the followed employee’s room while their avatar is in the office.',
      );
      return;
    }

    if (!followed) this.panTo(station);
    const eligible = this.actors().filter(
      (actor) => actor.zone === station.zone && actor.person.work_status !== 'on_delivery',
    );
    const idle = eligible.filter((actor) => !actor.pose.walking && !actor.pose.icon);
    const candidates = idle.length ? idle : eligible;
    const nearest =
      followed ??
      candidates.sort(
        (a, b) =>
          Math.hypot(a.pose.x - station.x, a.pose.y - station.y) -
          Math.hypot(b.pose.x - station.x, b.pose.y - station.y),
      )[0];

    if (!nearest) {
      this.notice.set('No employee avatars are available in this room.');
      return;
    }

    const partner =
      station.posture === 'talking'
        ? [...idle, ...eligible].find((actor) => actor.person.id !== nearest.person.id)
        : undefined;
    if (station.posture === 'talking' && !partner) {
      this.notice.set('A conversation needs two avatars in the room.');
      return;
    }

    const started = this.time();
    this.visits.update((visits) => {
      const next = {
        ...visits,
        [nearest.person.id]: {
          started,
          from: nearest.pose,
          station: stationForEmployee(station, nearest.slot, 0, nearest.home),
          garageOffset: this.capacity().garageExtension,
        },
      };
      if (partner)
        next[partner.person.id] = {
          started,
          from: partner.pose,
          station: stationForEmployee(station, partner.slot, 1, partner.home),
        };
      return next;
    });
    const names = partner
      ? nearest.person.name + ' and ' + partner.person.name
      : nearest.person.name;
    this.notice.set(
      this.motion()
        ? names + ' → ' + station.label
        : 'Resume movement to send ' + names + ' to ' + station.label + '.',
    );
  }

  protected panTo(
    point: FloorPoint,
    behavior: ScrollBehavior = this.motion() ? 'smooth' : 'auto',
  ): void {
    const screen = project(point);
    this.camera()?.center(screen.x / this.width(), screen.y / this.height(), behavior);
  }

  protected jumpTo(zone: Zone): void {
    const { crew, garage, admin } = this.capacity().fronts;
    const centers: Record<Zone, FloorPoint> = {
      crew: { x: 265, y: (150 + crew) / 2 },
      garage: { x: 748, y: (150 + garage) / 2 },
      admin: { x: 1140, y: (31 + admin) / 2 },
    };
    this.stopFollowing.emit();
    this.camera()?.inspect();
    this.panTo(centers[zone]);
  }
}
