import { ProofOfDelivery } from '@/models/delivery/delivery.model';
import { InspectionItem } from '@/models/inspection/inspection.model';
import {
  CurrentTrip,
  DispatchAnswer,
  DispatchChecklistSection,
  Trip,
} from '@/models/trip/trip.model';
import { TruckerDriver } from '@/models/trucker/trucker.model';

import { proofForm } from '../delivery/delivery.service';
import { api } from '../shared/api.service';

/**
 * A trucker's driver, on their own login — `crew/*`.
 *
 * The same shape as the trip half of `truckerService`, so My Trips can run on
 * either, plus what the Cargo Rush driver's screens need: the pre-trip check
 * and the current run in the Tracking screen's shape. The API narrows all of
 * it to the runs the owner handed this driver.
 */
export const crewService = {
  me(): Promise<TruckerDriver> {
    return api.get<TruckerDriver>('crew/me');
  },

  trips(): Promise<Trip[]> {
    return api.get<Trip[]>('crew/trips');
  },

  /** The run they are on — the same shape `tripService.current()` gives a Cargo Rush driver. */
  async current(): Promise<CurrentTrip | null> {
    const response = await api.envelope<CurrentTrip>('crew/trips/current');

    return response.data ?? null;
  },

  history(): Promise<Trip[]> {
    return api.get<Trip[]>('crew/trips/history');
  },

  /** The seven-item pre-trip checklist, the same one a Cargo Rush driver answers. */
  checklist(): Promise<InspectionItem[]> {
    return api.get<InspectionItem[]>('crew/inspections/checklist');
  },

  /**
   * Their pre-trip check for a run that has not left. The verdict is the
   * API's: `good_to_go` and what failed come back beside the run.
   */
  async inspect(
    tripId: string,
    results: Record<string, boolean>,
  ): Promise<{ trip: Trip; good_to_go: boolean; failures: string[] }> {
    const response = await api.postEnvelope<Trip>(`crew/trips/${tripId}/inspection`, { results });

    return {
      trip: response.data,
      good_to_go: Boolean(response.meta?.['good_to_go']),
      failures: (response.meta?.['failures'] as string[] | undefined) ?? [],
    };
  },

  start(tripId: string, location?: string): Promise<Trip> {
    return api.post<Trip>(`crew/trips/${tripId}/start`, { location: location ?? null });
  },

  deliver(tripId: string, proof: ProofOfDelivery): Promise<Trip> {
    return api.postForm<Trip>(`crew/trips/${tripId}/deliver`, proofForm(proof));
  },

  attachProof(tripId: string, proof: ProofOfDelivery): Promise<Trip> {
    return api.postForm<Trip>(`crew/trips/${tripId}/proof`, proofForm(proof));
  },

  /** The dispatch checklist — the same form a Cargo Rush driver answers. */
  dispatchChecklist(): Promise<DispatchChecklistSection[]> {
    return api.get<DispatchChecklistSection[]>('crew/dispatch-checklist');
  },

  answerDispatchChecklist(
    tripId: string,
    answers: Record<string, DispatchAnswer>,
    remarks: string | null,
  ): Promise<Trip> {
    return api.post<Trip>(`crew/trips/${tripId}/dispatch-checklist`, { answers, remarks });
  },
};
