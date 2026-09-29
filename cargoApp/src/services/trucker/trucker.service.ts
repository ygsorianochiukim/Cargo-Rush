import { ProofOfDelivery, ProofPhoto } from '@/models/delivery/delivery.model';
import { Trip } from '@/models/trip/trip.model';
import {
  Job,
  Trucker,
  TruckerDriver,
  TruckerDriverInput,
  TruckerVehicle,
  TruckPhotoSlot,
  Wallet,
} from '@/models/trucker/trucker.model';

import { proofForm } from '../delivery/delivery.service';
import { api } from '../shared/api.service';
import { appendPhoto } from '../shared/form-file';

/**
 * A partner trucker's own screens.
 *
 * The third of the three module services that are scoped to whoever is holding
 * the handset — `tripService` is the driver's, `portalService` is the
 * customer's, and this is the owner-operator's.
 *
 * **No call here takes an id that identifies the caller.** The partner record,
 * the wallet and the queue all resolve from the token, so one trucker cannot
 * read another's money by changing a number. The two ids that do appear are a
 * trip's, on an accept or a hand-off, and the API checks the run is theirs
 * before it acts — exactly as it does for a driver.
 */
export const truckerService = {
  /**
   * The partner's own record.
   *
   * `GET /me` names the account; this names the record, with the standing and
   * the switch on it. Read on open, because the app has three screens to
   * choose between: waiting for approval, offline, and the board.
   */
  me(): Promise<Trucker> {
    return api.get<Trucker>('partner/me');
  },

  /**
   * What they could take right now, nearest first.
   *
   * The handset's own position is sent when it has one, because it is better
   * than the partner's last reported pin for the obvious reason: it is where
   * they are now rather than where they said they were.
   *
   * The envelope rather than the payload, deliberately. An empty board is not
   * the same answer as a board with nothing on it — `meta.can_take_work` and
   * `meta.status` are what let the screen say *why*, and a screen that could
   * only show "no jobs" to somebody waiting on an approval would look broken.
   */
  async jobs(position?: { lat: number; lng: number }): Promise<{
    jobs: Job[];
    canTakeWork: boolean;
    status: string;
    commissionBp: number;
    /** False for an approved partner with no truck on the road yet. */
    hasTruck: boolean;
  }> {
    const response = await api.envelope<Job[]>(
      'partner/jobs',
      position ? { lat: position.lat, lng: position.lng } : undefined,
    );

    return {
      jobs: response.data ?? [],
      canTakeWork: Boolean(response.meta?.['can_take_work']),
      status: String(response.meta?.['status'] ?? 'pending'),
      commissionBp: Number(response.meta?.['commission_bp'] ?? 0),
      hasTruck: response.meta?.['has_truck'] !== false,
    };
  },

  /**
   * Take a job.
   *
   * First press wins, and the loser gets a 409 rather than a surprise at the
   * pickup. The screen is expected to say so in words and reload the board.
   */
  accept(tripId: string): Promise<Trip> {
    return api.post<Trip>(`partner/jobs/${tripId}/accept`, {});
  },

  /** Everything taken and not yet closed out. */
  trips(): Promise<Trip[]> {
    return api.get<Trip[]>('partner/trips');
  },

  /** The run they are on right now, or null between jobs. */
  async current(): Promise<Trip | null> {
    const response = await api.envelope<Trip>('partner/trips/current');

    // A 204 has no body. Being between jobs is an answer, not a gap.
    return response.data ?? null;
  },

  /** What they have already closed out. */
  history(): Promise<Trip[]> {
    return api.get<Trip[]>('partner/trips/history');
  },

  /**
   * Roll out.
   *
   * No pre-trip check, unlike the driver's start. That gate is the fleet
   * inspecting the fleet's own unit, and a partner's truck is not the fleet's
   * to clear — so this screen has no checklist to open and the button leaves
   * on the run.
   */
  start(tripId: string, location?: string): Promise<Trip> {
    return api.post<Trip>(`partner/trips/${tripId}/start`, { location: location ?? null });
  },

  /**
   * Hand over, with the proof.
   *
   * Multipart for the same reason the driver's is: the photograph taken at the
   * door is the substance of the write, and base64 in a JSON body would inflate
   * a phone photo by a third over a connection that is often a warehouse's
   * worth of concrete away from a mast.
   *
   * This is also what moves the money — the API credits or charges the wallet
   * from inside the same transaction that closes the run.
   */
  deliver(tripId: string, proof: ProofOfDelivery): Promise<Trip> {
    return api.postForm<Trip>(`partner/trips/${tripId}/deliver`, proofForm(proof));
  },

  /**
   * The photograph for a run already handed over — the gate had no signal.
   *
   * Files the picture and nothing else; the money moved at the hand-off.
   */
  attachProof(tripId: string, proof: ProofOfDelivery): Promise<Trip> {
    return api.postForm<Trip>(`partner/trips/${tripId}/proof`, proofForm(proof));
  },

  /**
   * The switch, and the position that goes with it.
   *
   * Sent together because they are the same act: going online is saying "I am
   * here, and available", and a partner who went online yesterday two hundred
   * kilometres away should not be top of today's board.
   *
   * Refused — 403 — for anybody the fleet has not approved yet. The screen
   * hides the switch rather than offering one the server will turn down.
   */
  setOnline(isOnline: boolean, position?: { lat: number; lng: number }): Promise<Trucker> {
    return api.post<Trucker>('partner/availability', {
      is_online: isOnline,
      ...(position ? { lat: position.lat, lng: position.lng } : {}),
    });
  },

  /** Where they are. Sent whenever the handset has a fix worth reporting. */
  reportPosition(lat: number, lng: number): Promise<Trucker> {
    return api.post<Trucker>('partner/position', { lat, lng });
  },

  /**
   * Their money: the balance, the figures and the statement in one call.
   *
   * Read-only from the handset. A partner watches the balance and the office
   * moves it — there is no endpoint here that writes a peso, which is
   * deliberate rather than unfinished.
   */
  wallet(range?: { from?: string; to?: string }): Promise<Wallet> {
    return api.get<Wallet>('partner/wallet', range);
  },

  /** Their trucks. */
  vehicles(): Promise<TruckerVehicle[]> {
    return api.get<TruckerVehicle[]>('partner/vehicles');
  },

  /** Their own drivers. Refused (403) until the office has approved them. */
  drivers(): Promise<TruckerDriver[]> {
    return api.get<TruckerDriver[]>('partner/drivers');
  },

  /** Add a driver with a login of their own. */
  addDriver(driver: TruckerDriverInput): Promise<TruckerDriver> {
    return api.post<TruckerDriver>('partner/drivers', driver);
  },

  /** Stand a driver down, or back on. */
  setDriverStatus(driverId: string, status: 'active' | 'inactive'): Promise<TruckerDriver> {
    return api.patch<TruckerDriver>(`partner/drivers/${driverId}`, { status });
  },

  /**
   * Hand a run to one of their drivers, or take it back with `null` — and,
   * when `vehicleId` is given, send it out on that truck instead.
   */
  assignDriver(tripId: string, driverId: string | null, vehicleId?: string | null): Promise<Trip> {
    return api.post<Trip>(`partner/trips/${tripId}/driver`, {
      trucker_driver_id: driverId,
      ...(vehicleId ? { trucker_vehicle_id: vehicleId } : {}),
    });
  },

  /** Correct a driver's name, phone or licence. */
  updateDriver(
    driverId: string,
    changes: { name?: string; phone?: string | null; licence_no?: string },
  ): Promise<TruckerDriver> {
    return api.patch<TruckerDriver>(`partner/drivers/${driverId}`, changes);
  },

  /** Take a driver off the books. Their runs come back; their login stops. */
  removeDriver(driverId: string): Promise<unknown> {
    return api.delete(`partner/drivers/${driverId}`);
  },

  /** Add a truck, or take one off the road while it is in the shop. */
  saveVehicle(
    vehicle: Partial<TruckerVehicle> & { plate?: string },
    vehicleId?: string,
  ): Promise<TruckerVehicle> {
    return vehicleId
      ? api.patch<TruckerVehicle>(`partner/vehicles/${vehicleId}`, vehicle)
      : api.post<TruckerVehicle>('partner/vehicles', vehicle);
  },

  /**
   * A new truck, with its photographs — multipart, like the proof of delivery.
   *
   * Front, both sides, back and plate are required; the engine is optional.
   * The truck lands waiting for Cargo Rush to verify it.
   */
  async addVehicle(
    vehicle: { plate: string; model: string; capacity_kg: number },
    photos: Partial<Record<TruckPhotoSlot, ProofPhoto>>,
  ): Promise<TruckerVehicle> {
    const body = await photoForm(photos);

    body.append('plate', vehicle.plate);
    body.append('model', vehicle.model);
    body.append('capacity_kg', String(vehicle.capacity_kg));

    return api.postForm<TruckerVehicle>('partner/vehicles', body);
  },

  /** New photos for a truck — usually after Cargo Rush turned it down. */
  resendPhotos(vehicleId: string, photos: Partial<Record<TruckPhotoSlot, ProofPhoto>>): Promise<TruckerVehicle> {
    return api.postForm<TruckerVehicle>(`partner/vehicles/${vehicleId}/photos`, photoForm(photos));
  },
};

async function photoForm(photos: Partial<Record<TruckPhotoSlot, ProofPhoto>>): Promise<FormData> {
  const body = new FormData();

  for (const [slot, photo] of Object.entries(photos)) {
    if (photo) await appendPhoto(body, `photo_${slot}`, photo);
  }

  return body;
}
