import { ProofOfDelivery } from '@/models/delivery/delivery.model';
import { proofForm } from '../delivery/delivery.service';
import { CargoDetail, CurrentTrip, Trip } from '@/models/trip/trip.model';

import { api } from '../shared/api.service';

/**
 * The driver's own work.
 *
 * These endpoints take no id — they are scoped to whoever is holding the
 * handset, so one driver cannot read another's run by changing a number.
 */
export const tripService = {
  /** The trip they are on right now. Null when they are between runs. */
  async current(): Promise<CurrentTrip | null> {
    const response = await api.envelope<CurrentTrip>('trips/current');

    // A 204 comes back with no body: no current trip is an answer, not a gap.
    return response.data ?? null;
  },

  /** Assigned work that has not started. */
  pending(): Promise<Trip[]> {
    return api.get<Trip[]>('trips/pending');
  },

  /** Work booked for a later day. */
  upcoming(): Promise<Trip[]> {
    return api.get<Trip[]>('trips/upcoming');
  },

  /** Cargo Details for the current run. Null when there is no run. */
  async cargo(): Promise<CargoDetail | null> {
    const response = await api.envelope<CargoDetail>('trips/cargo');

    return response.data ?? null;
  },

  find(id: string): Promise<Trip> {
    return api.get<Trip>(`trips/${id}`);
  },

  /** Leaving the depot. This is what opens the dispatch record. */
  dispatch(id: string, location: string): Promise<Trip> {
    return api.post<Trip>(`trips/${id}/dispatch`, { location });
  },

  /**
   * Leaving on a run: pending → in transit, which opens the dispatch record.
   *
   * This one names a trip, because a driver can have several waiting and has
   * to say which they are leaving on. The API checks it is theirs before it
   * acts, so the id being in the URL is not the app's problem to police.
   *
   * `location` is where they are departing from. Omitted when the handset has
   * no fix yet — the API falls back to the booked pickup place rather than
   * refusing to open the record.
   */
  start(id: string, location?: string): Promise<Trip> {
    return api.post<Trip>(`trips/${id}/start`, { location: location ?? null });
  },

  /**
   * Hand the current run over: in transit → delivered, with the proof.
   *
   * Multipart rather than JSON, because the proof is a photograph. The
   * reference the driver used to have to type is the API's to assign.
   *
   * Takes no id, like the reads above. The office closes a trip through
   * `trips/{id}/complete`; that path is not this app's to call, because an id
   * in the URL is an id a handset could change to somebody else's run.
   */
  deliver(proof: ProofOfDelivery): Promise<Trip> {
    return api.postForm<Trip>('trips/current/deliver', proofForm(proof));
  },
};
