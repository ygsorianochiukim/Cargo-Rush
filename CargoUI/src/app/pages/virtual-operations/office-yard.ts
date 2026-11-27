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
import { garageBays } from './garage-layout';
import { GarageTruck, truckBounds } from './garage-truck';
import {
  FloorPoint,
  ConversationPair,
  OfficePose,
  OfficeStation,
  StationVisit,
  employeeZone,
  homePositions,
  officePose,
  stationForEmployee,
  stations,
} from './office-activity';
import { OfficeDesk, discordDesk, officeAgents } from './office-agents';
import { OfficeCamera } from './office-camera';
import { bedCoverHeight, chairSeatHeight } from './office-furniture';
import { adminWorkstations } from './office-furniture-layout';
import { OfficePlan } from './office-plan';
import { project, sceneHeight, sceneWidth } from './office-projection';
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
  protected readonly width = sceneWidth;
  protected readonly height = sceneHeight;
  protected readonly stations = stations.map((station) => ({
    ...station,
    screen: project(station),
  }));
  protected readonly fleetLabels = fleetLabels;
  protected readonly garageSize = garageBays.length;
  protected readonly garagePageCount = computed(() =>
    Math.max(1, Math.ceil(this.yard().fleet.length / this.garageSize)),
  );
  private readonly requestedGaragePage = signal(0);
  protected readonly garagePage = computed(() =>
    Math.min(this.requestedGaragePage(), this.garagePageCount() - 1),
  );
  protected readonly truckWidth = (truckBounds.width / sceneWidth) * 100;
  protected readonly truckAnchor = `translate(${(-truckBounds.left / truckBounds.width) * 100}%, ${(-truckBounds.top / truckBounds.height) * 100}%)`;
  protected readonly desks = ([...officeAgents, discordDesk] satisfies OfficeDesk[]).map(
    (desk) => ({ ...desk, screen: project(desk, chairSeatHeight) }),
  );

  protected readonly parking = computed(() => {
    const offset = this.garagePage() * this.garageSize;
    return this.yard().fleet.slice(offset, offset + this.garageSize).flatMap((truck, index) => {
      if (truck.fleet_status === 'traveling') return [];

      const bay = garageBays[index];
      return [{ truck, screen: project({ x: bay.center, y: bay.bumper }) }];
    });
  });

  protected readonly placement = computed(() => {
    const nextSlot = { crew: 0, garage: 0, admin: 0 };
    const anchors = this.people().map((person) => {
      const zone = employeeZone(person.room);
      const slot = nextSlot[zone]++;
      return { person, zone, slot, home: homePositions[zone][slot] as FloorPoint | undefined };
    });
    const partners = new Map<string, ConversationPair>();
    for (const zone of ['crew', 'admin'] as const) {
      const present = anchors.filter(
        (anchor) =>
          anchor.zone === zone && anchor.home && anchor.person.work_status !== 'on_delivery',
      );
      for (let index = 0; index + 1 < present.length; index += 2) {
        partners.set(present[index].person.id, { key: index / 2, side: 0 });
        partners.set(present[index + 1].person.id, { key: index / 2, side: 1 });
      }
    }
    return { anchors, partners, overflow: anchors.filter((anchor) => !anchor.home) };
  });

  protected readonly actors = computed(() => {
    const time = this.time();
    const visits = this.visits();
    const { anchors, partners } = this.placement();
    return anchors.flatMap((anchor) => {
      const { home, person, zone, slot } = anchor;
      if (!home) return [];
      const visit = visits[person.id];
      const pose: OfficePose =
        person.work_status === 'on_delivery'
          ? { ...home, posture: 'standing', label: 'On delivery', icon: '↗', walking: false }
          : officePose(
              home,
              zone,
              slot,
              time,
              visit?.station.zone === zone ? visit : undefined,
              partners.get(person.id),
            );
      const screen = project(pose, pose.posture === 'sleeping' ? bedCoverHeight : 0);
      const facing =
        pose.facing ??
        (pose.heading && project(pose.heading).x < project({ x: 0, y: 0 }).x
          ? ('left' as const)
          : ('right' as const));
      const atDesk =
        zone === 'admin' && pose.posture === 'working' && slot < adminWorkstations.length;
      return [{ ...anchor, pose, screen, facing, atDesk, depth: Math.round(screen.y) }];
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
        this.camera()?.keepVisible(actor.screen.x / sceneWidth, actor.screen.y / sceneHeight);
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
          station: stationForEmployee(station, nearest.slot, 0),
        },
      };
      if (partner)
        next[partner.person.id] = {
          started,
          from: partner.pose,
          station: stationForEmployee(station, partner.slot, 1),
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
    this.camera()?.center(screen.x / sceneWidth, screen.y / sceneHeight, behavior);
  }

  protected jumpTo(point: FloorPoint): void {
    this.stopFollowing.emit();
    this.panTo(point);
  }

  protected pageGarage(delta: number): void {
    this.requestedGaragePage.set(
      Math.max(0, Math.min(this.garagePageCount() - 1, this.garagePage() + delta)),
    );
    this.jumpTo({ x: 748, y: 300 });
  }
}
