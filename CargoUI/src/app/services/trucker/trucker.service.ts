import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { Trip } from '../../models/trip/trip.model';
import {
  Trucker,
  TruckerVehicle,
  Wallet,
  WalletEntry,
  WalletEntryPayload,
} from '../../models/trucker/trucker.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { ApiService } from '../shared/api.service';

/**
 * Truckers — the partner roster, and the money that goes with it.
 *
 * The office's half of the module. The partner's own screens live in
 * `cargoApp` behind `partner/*`, and nothing here is reachable from a handset:
 * approving somebody, moving their rate and paying them out are all decisions a
 * named person at the fleet makes about somebody outside it.
 *
 * The permission split follows the drivers module. `truckers.view` is reading —
 * the roster, the trucks, the wallet. `truckers.manage` is acting, and it is
 * the heavier half of any pair on this API: approving hands a stranger a
 * customer's cargo, a rate decides what every future run splits at, and a
 * payout moves money out of the business.
 */
@Injectable({ providedIn: 'root' })
export class TruckerService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Trucker[]>> {
    return this.api.envelope<Trucker[]>('truckers', query);
  }

  find(id: string): Observable<Trucker> {
    return this.api.get<Trucker>(`truckers/${id}`);
  }

  /**
   * Who could be handed a load right now.
   *
   * Not the roster filtered by status: it also requires a truck that is not in
   * the shop, which is a question about the rows underneath. The assign dialog
   * reads this rather than filtering the list itself.
   */
  available(): Observable<Trucker[]> {
    return this.api.get<Trucker[]>('truckers/available');
  }

  /** Approve a registration — the moment somebody becomes usable. */
  approve(id: string): Observable<Trucker> {
    return this.api.post<Trucker>(`truckers/${id}/approve`, {});
  }

  /** Put somebody on hold, with a reason they can read on their handset. */
  suspend(id: string, reason?: string): Observable<Trucker> {
    return this.api.post<Trucker>(`truckers/${id}/suspend`, { reason: reason ?? null });
  }

  /** One partner's running account: the figures and the statement. */
  wallet(id: string, query?: ListQuery): Observable<Wallet> {
    return this.api.get<Wallet>(`truckers/${id}/wallet`, query);
  }

  /**
   * Settle up.
   *
   * One endpoint for a payout, a remittance and a correction, because it is one
   * form at the desk. Which of them it is decides the direction and what it may
   * not exceed, and the API enforces both — they depend on the current balance,
   * which is a question about the database rather than about the payload.
   */
  settle(id: string, payload: WalletEntryPayload): Observable<WalletEntry> {
    return this.api.post<WalletEntry>(`truckers/${id}/wallet`, payload);
  }

  /**
   * Confirm a payment has landed — the moment the balance finally falls.
   *
   * Its own call rather than a flag on the settle, because they are two facts
   * on two different days: a transfer goes out on Monday and clears on
   * Tuesday. Collapsing them told the partner on Monday that the money had
   * arrived.
   */
  confirmPayment(truckerId: string, entryId: string): Observable<WalletEntry> {
    return this.api.post<WalletEntry>(`truckers/${truckerId}/wallet/${entryId}/confirm`, {});
  }

  vehicles(id: string): Observable<TruckerVehicle[]> {
    return this.api.get<TruckerVehicle[]>(`truckers/${id}/vehicles`);
  }

  /**
   * Hand a run to a partner.
   *
   * The desk's alternative to the open board, for the case the board cannot
   * serve: nobody nearby took it, or it is too far out for the fleet's own
   * units to be worth sending.
   *
   * **This is also the line that decides the money.** A run assigned here stays
   * the fleet's to invoice and collect, so the partner is credited their share
   * at delivery. A run they took off the board themselves is their own
   * business, and the fleet's cut is charged to their wallet instead.
   */
  assign(tripId: string, truckerId: string, vehicleId?: string): Observable<Trip> {
    return this.api.post<Trip>(`trips/${tripId}/assign-trucker`, {
      trucker_id: truckerId,
      trucker_vehicle_id: vehicleId ?? null,
    });
  }

  /**
   * Take a partner off a run, returning it to the board as unclaimed work.
   *
   * `deleteItem` rather than `delete`: this answers with the run it changed
   * rather than a 204, because the board wants the new status back without a
   * second fetch. Refused once the run has been closed out — a delivered run
   * has a wallet entry and possibly an invoice against it, and unpicking those
   * is a correction the office makes deliberately.
   */
  release(tripId: string): Observable<Trip> {
    return this.api.deleteItem<Trip>(`trips/${tripId}/assign-trucker`);
  }
}
