import { DOCUMENT, DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Observable, Subject, filter, finalize, fromEvent, interval, merge, takeUntil } from 'rxjs';

import { IdentityService } from '../../services/identity/identity.service';
import { ApiService } from '../../services/shared/api.service';
import { Modal } from '../../shared/modal';
import { ErrorState } from '../../shared/states';
import { TripDialog } from '../../shared/trip-dialog';
import { fleetLabels, YardSnapshot } from './fleet-monitor';
import { OfficeAgent, OfficeDesk, officeAgents } from './office-agents';
import { OfficePerson, OfficeYard } from './office-yard';
import { Appearance, OperationsAvatar } from './operations-avatar';
type WorkStatus = OfficePerson['work_status'];

interface Room {
  id: string;
  name: string;
}

interface Position {
  key: string;
  name: string;
  room: string;
  default_room: string;
}

interface StaffMember extends OfficePerson {
  employee_no: string;
  position_key: string;
  department: string | null;
  employment_status: string;
  vehicle: string | null;
  delivery: string | null;
  photo_url: string | null;
}

interface OperationsSnapshot {
  rooms: Room[];
  positions: Position[];
  employees: StaffMember[];
  yard: YardSnapshot;
  dispatch: Record<'assigned' | 'in_transit' | 'overdue', number>;
  agent: {
    enabled: boolean;
    paired: boolean;
    chat_ready: boolean;
    state: 'online' | 'connecting' | 'no_token' | 'refused' | null;
    reported_at: string | null;
  };
}

@Component({
  selector: 'app-virtual-operations',
  imports: [DatePipe, ErrorState, FormsModule, Modal, OfficeYard, OperationsAvatar, RouterLink],
  templateUrl: './virtual-operations.page.html',
  styleUrls: ['./virtual-operations.page.css', './operations-dialogs.css'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VirtualOperationsPage {
  private readonly api = inject(ApiService);
  private readonly identity = inject(IdentityService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly document = inject(DOCUMENT);
  private readonly tripDialog = inject(TripDialog);
  private readonly cancelQuestion = new Subject<void>();
  private pendingReload = false;

  protected readonly snapshot = signal<OperationsSnapshot | null>(null);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly loadError = signal('');
  protected readonly saveError = signal('');
  protected readonly search = signal('');
  protected readonly filter = signal('');
  protected readonly selected = signal<string | null>(null);
  protected readonly followedEmployee = signal<string | null>(null);
  protected readonly selectedTruck = signal<string | null>(null);
  protected readonly agentOpen = signal(false);
  protected readonly agentDesk = signal<OfficeAgent | null>(null);
  protected readonly agentQuestion = signal('');
  protected readonly agentAnswer = signal('');
  protected readonly agentError = signal('');
  protected readonly agentBusy = signal(false);
  protected readonly officeAgents = officeAgents;
  protected readonly fleetLabels = fleetLabels;
  protected readonly appearance = signal<Appearance | null>(null);
  protected readonly layout = signal<{ rooms: Room[]; positions: Position[] } | null>(null);
  protected readonly roomName = signal('');

  protected readonly canEdit = computed(() => this.identity.me()?.role === 'administrator');
  protected readonly canOpenProfile = computed(() => this.identity.can('hr.view'));
  protected readonly canOpenSettings = computed(() => this.identity.can('company.manage'));
  protected readonly canOpenDispatch = computed(() => this.identity.can('dispatch.view'));
  protected readonly canOpenTrips = computed(() => this.identity.can('trips.view'));
  protected readonly canOpenVehicles = computed(() => this.identity.can('vehicles.view'));
  protected readonly canOpenAutomation = computed(
    () => this.identity.me()?.permissions.includes('automation.manage') === true,
  );

  protected readonly labels: Record<WorkStatus, string> = {
    available: 'Available',
    assigned: 'Assigned',
    on_delivery: 'On delivery',
    unknown: 'Unknown',
  };

  protected readonly roomDetails: Record<string, string> = {
    drivers: 'Vehicle assignments',
    helpers: 'Loading dock',
    dispatch: 'Delivery monitoring',
    warehouse: 'Storage and inventory',
    administration: 'Personnel records',
    management: 'Operations review',
  };

  protected readonly appearanceOptions: {
    field: keyof Appearance;
    label: string;
    choices: string[];
  }[] = [
    { field: 'hair', label: 'Hairstyle', choices: ['Side part', 'Fringe', 'High top', 'Cropped'] },
    { field: 'skin', label: 'Skin tone', choices: ['Fair', 'Light', 'Medium', 'Tan', 'Deep'] },
    {
      field: 'outfit',
      label: 'Clothing',
      choices: [
        'Room uniform',
        'Navy jacket',
        'Workwear',
        'Office shirt',
        'Hi-vis vest',
        'Charcoal suit',
      ],
    },
    { field: 'accessory', label: 'Accessory', choices: ['None', 'Glasses', 'Cap', 'Headset'] },
  ];

  protected readonly filtered = computed(() => {
    const query = this.search().trim().toLocaleLowerCase();
    const position = this.filter();

    return (this.snapshot()?.employees ?? []).filter(
      (employee) =>
        (!position || employee.position_key === position) &&
        (!query ||
          employee.name.toLocaleLowerCase().includes(query) ||
          employee.employee_no.toLocaleLowerCase().includes(query)),
    );
  });

  protected readonly occupiedPositions = computed(() => {
    const snapshot = this.snapshot();
    const keys = new Set(snapshot?.employees.map((employee) => employee.position_key));

    return snapshot?.positions.filter((position) => keys.has(position.key)) ?? [];
  });

  protected readonly floor = computed(() => {
    const byRoom = new Map<string, StaffMember[]>();
    for (const employee of this.filtered()) {
      const group = byRoom.get(employee.room) ?? [];
      group.push(employee);
      byRoom.set(employee.room, group);
    }

    return (this.snapshot()?.rooms ?? []).map((room) => ({
      ...room,
      staff: byRoom.get(room.id) ?? [],
    }));
  });

  protected readonly employee = computed(
    () => this.snapshot()?.employees.find((person) => person.id === this.selected()) ?? null,
  );

  protected readonly truck = computed(
    () => this.snapshot()?.yard.fleet.find((truck) => truck.id === this.selectedTruck()) ?? null,
  );

  protected readonly deskStats = computed(() => {
    const data = this.snapshot();
    if (!data) return [];

    switch (this.agentDesk()?.id) {
      case 'dispatch':
        return [
          { label: 'Assigned trips', count: data.dispatch.assigned },
          { label: 'Trips in transit', count: data.dispatch.in_transit },
          { label: 'Overdue trips', count: data.dispatch.overdue },
        ];
      case 'fleet':
        return [
          { label: 'Parked trucks', count: data.yard.parked.length },
          { label: 'Traveling trucks', count: data.yard.traveling },
          {
            label: 'Active in yard',
            count: data.yard.fleet.filter((truck) => truck.fleet_status === 'active').length,
          },
          {
            label: 'Available trucks',
            count: data.yard.fleet.filter((truck) => truck.fleet_status === 'available').length,
          },
          {
            label: 'Unavailable trucks',
            count: data.yard.fleet.filter((truck) => truck.fleet_status === 'unavailable').length,
          },
        ];
      case 'office':
        return [
          { label: 'Active employees', count: data.employees.length },
          { label: 'Office areas', count: data.rooms.length },
        ];
      default:
        return [];
    }
  });

  protected readonly agentStatus = computed(() => {
    const agent = this.snapshot()?.agent;
    if (!agent?.enabled) return 'Disabled';
    if (!agent.paired) return 'Awaiting pairing';

    switch (agent.state) {
      case 'online':
        return 'Online';
      case 'connecting':
        return 'Connecting';
      case 'no_token':
        return 'Discord token missing';
      case 'refused':
        return 'Connection refused';
      default:
        return 'Status unknown';
    }
  });

  constructor() {
    this.reload();
    interval(15_000)
      .pipe(takeUntilDestroyed())
      .subscribe(() => {
        if (!this.document.hidden && !this.layout() && !this.appearance()) this.reload();
      });
    merge(
      this.tripDialog.saved,
      this.tripDialog.deleted,
      fromEvent(this.document, 'visibilitychange').pipe(filter(() => !this.document.hidden)),
    ).pipe(takeUntilDestroyed()).subscribe(() => this.reload(true));
  }

  protected reload(queueIfBusy = false): void {
    if (this.loading() || this.saving()) {
      this.pendingReload ||= queueIfBusy;
      return;
    }

    this.pendingReload = false;
    this.loading.set(true);
    this.loadError.set('');
    this.api
      .get<OperationsSnapshot>('virtual-operations')
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.loading.set(false);
          this.reloadIfPending();
        }),
      )
      .subscribe({
        next: (snapshot) => this.applySnapshot(snapshot),
        error: () => this.loadError.set('Could not load the operations floor. Try refreshing.'),
      });
  }

  private reloadIfPending(): void {
    if (this.pendingReload && !this.destroyRef.destroyed) this.reload();
  }

  protected select(id: string | null): void {
    this.selected.set(id);
    this.appearance.set(null);
    this.saveError.set('');
  }

  protected followEmployee(id: string): void {
    this.followedEmployee.set(id);
    this.select(null);
  }

  protected openDesk(desk: OfficeDesk): void {
    if (desk.id === 'discord') {
      this.agentOpen.set(true);
      return;
    }

    this.closeDesk();
    this.agentDesk.set(desk);
    this.agentQuestion.set(desk.question);
  }

  protected closeDesk(): void {
    this.cancelQuestion.next();
    this.agentDesk.set(null);
    this.agentAnswer.set('');
    this.agentError.set('');
  }

  protected askAgent(): void {
    const desk = this.agentDesk();
    const question = this.agentQuestion().trim();
    if (!desk || !question || this.agentBusy() || !this.snapshot()?.agent.chat_ready) return;

    this.agentAnswer.set('');
    this.agentError.set('');
    this.agentBusy.set(true);
    this.api
      .post<{ answer: string }>('virtual-operations/ask', { agent: desk.id, question })
      .pipe(
        takeUntil(this.cancelQuestion),
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.agentBusy.set(false)),
      )
      .subscribe({
        next: ({ answer }) => this.agentAnswer.set(answer),
        error: (error: HttpErrorResponse) => {
          this.agentError.set(
            error.error?.errors?.question?.[0] ??
              error.error?.message ??
              'Could not reach the agent. Try again.',
          );
        },
      });
  }

  protected roomLabel(id: string): string {
    return this.snapshot()?.rooms.find((room) => room.id === id)?.name ?? '';
  }

  protected setAppearance(field: keyof Appearance, value: number): void {
    this.appearance.update((draft) => draft && { ...draft, [field]: value });
  }

  protected closeLayout(): void {
    this.layout.set(null);
    this.saveError.set('');
  }

  protected saveAppearance(employee: StaffMember, appearance: Appearance): void {
    this.save(
      this.api.put<Appearance>(
        `virtual-operations/employees/${employee.id}/appearance`,
        appearance,
      ),
      (saved) => {
        this.snapshot.update(
          (data) =>
            data && {
              ...data,
              employees: data.employees.map((person) =>
                person.id === employee.id ? { ...person, appearance: saved } : person,
              ),
            },
        );
        this.appearance.set(null);
      },
    );
  }

  protected openLayout(): void {
    const data = this.snapshot();
    if (!data) return;

    this.select(null);
    this.roomName.set('');
    this.layout.set({ rooms: data.rooms, positions: data.positions });
  }

  protected addRoom(): void {
    const name = this.roomName().trim();
    if (!name) return;

    const id = `custom-${Date.now().toString(36)}`;
    this.layout.update((draft) => draft && { ...draft, rooms: [...draft.rooms, { id, name }] });
    this.roomName.set('');
  }

  protected moveRoom(index: number, direction: number): void {
    this.layout.update((draft) => {
      if (!draft) return draft;
      const rooms = [...draft.rooms];
      const [room] = rooms.splice(index, 1);
      rooms.splice(index + direction, 0, room);

      return { ...draft, rooms };
    });
  }

  protected removeRoom(id: string): void {
    this.layout.update(
      (draft) =>
        draft && {
          rooms: draft.rooms.filter((room) => room.id !== id),
          positions: draft.positions.map((position) =>
            position.room === id ? { ...position, room: position.default_room } : position,
          ),
        },
    );
  }

  protected assignRoom(key: string, room: string): void {
    this.layout.update(
      (draft) =>
        draft && {
          ...draft,
          positions: draft.positions.map((position) =>
            position.key === key ? { ...position, room } : position,
          ),
        },
    );
  }

  protected saveLayout(draft: { rooms: Room[]; positions: Position[] }): void {
    const rules = draft.positions
      .filter((position) => position.room !== position.default_room)
      .map(({ key, room }) => ({ key, room }));

    this.save(
      this.api.put<OperationsSnapshot>('virtual-operations/layout', { rooms: draft.rooms, rules }),
      (snapshot) => {
        this.applySnapshot(snapshot);
        this.layout.set(null);
      },
    );
  }

  private applySnapshot(snapshot: OperationsSnapshot): void {
    this.snapshot.set(snapshot);
    if (
      this.filter() &&
      !this.occupiedPositions().some((position) => position.key === this.filter())
    )
      this.filter.set('');
    if (this.selected() && !this.employee()) this.select(null);
    if (
      this.followedEmployee() &&
      !snapshot.employees.some((person) => person.id === this.followedEmployee())
    ) {
      this.followedEmployee.set(null);
    }
    if (this.selectedTruck() && !this.truck()) this.selectedTruck.set(null);
  }

  private save<T>(request: Observable<T>, onSuccess: (data: T) => void): void {
    if (this.saving() || this.loading()) return;

    this.saving.set(true);
    this.saveError.set('');
    request
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.saving.set(false);
          this.reloadIfPending();
        }),
      )
      .subscribe({
        next: onSuccess,
        error: (error: HttpErrorResponse) => {
          this.saveError.set(error.error?.message ?? 'Could not save changes. Try again.');
        },
      });
  }
}
