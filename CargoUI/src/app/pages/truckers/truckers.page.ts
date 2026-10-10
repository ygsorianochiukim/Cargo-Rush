import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';

import { TRUCK_PHOTO_SLOTS, Trucker, TruckerVehicle } from '../../models/trucker/trucker.model';
import { TruckerService } from '../../services/trucker/trucker.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { Modal } from '../../shared/modal';
import { EmptyState, ErrorState, SkeletonRows } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';
import { WalletPanel } from '../../shared/wallet-panel';

type Tab = 'pending' | 'trucks' | 'active' | 'inactive' | 'all';

@Component({
  selector: 'app-truckers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Icon, Modal, StatusPill, SkeletonRows, EmptyState, ErrorState, WalletPanel],
  templateUrl: './truckers.page.html',
})
export class TruckersPage {
  private readonly truckersApi = inject(TruckerService);
  private readonly confirm = inject(Confirm);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly fmt = fmt;

  protected readonly truckers = signal<Trucker[] | null>(null);
  protected readonly loadError = signal<string | null>(null);

  protected readonly tab = signal<Tab>('pending');

  protected readonly openTrucker = signal<Trucker | null>(null);

  protected readonly formError = signal<string | null>(null);

  constructor() {
    this.refresh();

    const settle = this.route.snapshot.queryParamMap.get('settle');

    if (settle !== null) this.openById(settle);
  }

  private openById(id: string): void {
    this.truckersApi.find(id).subscribe({
      next: (trucker) => {
        this.tab.set('all');
        this.open(trucker);
      },
      error: (error: HttpErrorResponse) =>
        this.loadError.set(
          error.status === 404
            ? 'That trucker is no longer on the roster.'
            : this.messageFor(error),
        ),
    });
  }

  protected refresh(): void {
    this.truckersApi.list({ per_page: 100 }).subscribe({
      next: (res) => {
        this.truckers.set(res.data);
        this.loadError.set(null);
      },
      error: () => {
        this.truckers.set(null);
        this.loadError.set('Could not load the roster. Check the connection and try again.');
      },
    });
  }

  protected readonly rows = computed(() => {
    const all = this.truckers();

    if (all === null) return null;

    return all.filter((t) => this.inTab(t, this.tab()));
  });

  protected countOf(tab: Tab): number {
    return (this.truckers() ?? []).filter((t) => this.inTab(t, tab)).length;
  }

  private inTab(trucker: Trucker, tab: Tab): boolean {
    if (tab === 'all') return true;
    if (tab === 'trucks') return this.trucksToCheck(trucker) > 0;

    return trucker.status === tab;
  }

  protected trucksToCheck(trucker: Trucker): number {
    return (trucker.vehicles ?? []).filter((v) => v.verification === 'pending').length;
  }

  protected readonly photoSlots = TRUCK_PHOTO_SLOTS;

  protected readonly checking = signal<{ trucker: Trucker; vehicle: TruckerVehicle } | null>(null);

  protected readonly enlarged = signal<{ url: string; label: string } | null>(null);

  protected readonly rejecting = signal<string | null>(null);
  protected readonly reason = signal('');

  protected readonly truckQueue = computed(() =>
    (this.truckers() ?? [])
      .flatMap((trucker) =>
        (trucker.vehicles ?? [])
          .filter((vehicle) => vehicle.verification === 'pending')
          .map((vehicle) => ({ trucker, vehicle })),
      )
      .sort((a, b) => (a.vehicle.created_at ?? '').localeCompare(b.vehicle.created_at ?? '')),
  );

  protected photoCount(vehicle: TruckerVehicle): number {
    return Object.values(vehicle.photos ?? {}).filter(Boolean).length;
  }

  protected review(trucker: Trucker, vehicle: TruckerVehicle): void {
    this.checking.set({ trucker, vehicle });
    this.enlarged.set(null);
    this.rejecting.set(null);
    this.formError.set(null);
  }

  protected closeCheck(): void {
    this.checking.set(null);
    this.enlarged.set(null);
    this.rejecting.set(null);
    this.formError.set(null);
  }

  protected verifyTruck(trucker: Trucker, vehicle: TruckerVehicle): void {
    this.truckersApi.verifyVehicle(trucker.id, vehicle.id).subscribe({
      next: () => {
        this.closeCheck();
        this.reopen(trucker.id);
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  protected startReject(vehicle: TruckerVehicle): void {
    this.rejecting.set(vehicle.id);
    this.reason.set('');
    this.formError.set(null);
  }

  protected rejectTruck(trucker: Trucker, vehicle: TruckerVehicle): void {
    const reason = this.reason().trim();

    if (reason === '') {
      this.formError.set('Say what is wrong, so the trucker knows what to send again.');

      return;
    }

    this.truckersApi.rejectVehicle(trucker.id, vehicle.id, reason).subscribe({
      next: () => {
        this.closeCheck();
        this.reopen(trucker.id);
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  private reopen(id: string): void {
    this.refresh();
    this.truckersApi.find(id).subscribe({
      next: (fresh) => {
        if (this.openTrucker()?.id === id) this.openTrucker.set(fresh);
      },
    });
  }

  protected approve(trucker: Trucker): void {
    this.truckersApi.approve(trucker.id).subscribe({
      next: (updated) => {
        this.refresh();

        if (this.openTrucker()?.id === trucker.id) this.openTrucker.set(updated);
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  protected async suspend(trucker: Trucker): Promise<void> {
    const ok = await this.confirm.ask({
      title: `Put ${trucker.name} on hold?`,
      body: 'They stop seeing jobs straight away. Runs they are already on are left alone, and their wallet is untouched.',
      confirmLabel: 'Put on hold',
      danger: true,
    });

    if (!ok) return;

    this.truckersApi.suspend(trucker.id).subscribe({
      next: (updated) => {
        this.refresh();

        if (this.openTrucker()?.id === trucker.id) this.openTrucker.set(updated);
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  private fail(error: HttpErrorResponse): void {
    const message = this.messageFor(error);

    if (this.openTrucker() !== null || this.checking() !== null) {
      this.formError.set(message);

      return;
    }

    this.loadError.set(message);
  }

  protected open(trucker: Trucker): void {
    this.openTrucker.set(trucker);
    this.formError.set(null);
    this.rejecting.set(null);
  }

  protected close(): void {
    this.openTrucker.set(null);

    if (this.route.snapshot.queryParamMap.has('settle')) {
      void this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });
    }
  }

  protected rate(bp: number): string {
    return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : 1)}%`;
  }

  protected plate(trucker: Trucker): string {
    const units = trucker.vehicles ?? [];
    const unit = units[0];

    if (!unit) return 'No truck on file';

    const first = `${unit.plate} · ${unit.capacity_kg.toLocaleString()} kg`;

    return units.length > 1 ? `${first} +${units.length - 1} more` : first;
  }

  protected initials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  }

  private messageFor(error: HttpErrorResponse): string {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    if (error.status === 422 && errors) {
      return Object.values(errors)[0]?.[0] ?? 'Check the figures and try again.';
    }

    return error.error?.message ?? 'Could not do that. Check the connection and try again.';
  }
}
