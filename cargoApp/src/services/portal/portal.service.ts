import {
  DeliveryRequestPayload,
  PortalInvoice,
  PortalSummary,
} from '@/models/portal/portal.model';
import { Hauler } from '@/models/portal/hauler.model';
import { Trip } from '@/models/trip/trip.model';

import { api } from '../shared/api.service';

/**
 * The customer's own work and money.
 *
 * These endpoints take no customer id — they are scoped to whoever is holding
 * the handset, exactly as the driver's are, so one firm cannot read another's
 * deliveries by changing something in a URL.
 *
 * A request comes back as the `Trip` it created, not as a receipt: the
 * customer needs its reference to quote on the phone and its price to know
 * what it will cost, and both are on the trip already.
 */
export const portalService = {
  /** Counts and the two money figures the home screen leads with. */
  summary(): Promise<PortalSummary> {
    return api.get<PortalSummary>('portal/summary');
  },

  /** Everything this customer has asked for, newest first. */
  requests(): Promise<Trip[]> {
    return api.get<Trip[]>('portal/requests');
  },

  /** One of their own deliveries. */
  request(id: string): Promise<Trip> {
    return api.get<Trip>(`portal/requests/${id}`);
  },

  /**
   * Ask for a pickup.
   *
   * Lands as `pending` — a request the desk has to confirm — and comes back
   * priced, so the customer leaves the form knowing what it costs rather than
   * waiting to be rung back.
   */
  submit(payload: DeliveryRequestPayload): Promise<Trip> {
    return api.post<Trip>('portal/requests', payload);
  },

  /**
   * Who could carry this load — the fleet, and the truckers near the pickup.
   *
   * Distinct from `carrierService.nearby()`, which lists *companies* across the
   * platform and is how a shipper finds a haulier at all. This is inside one
   * haulier: its own fleet, plus the vetted contractors close enough to turn
   * up. The fleet is always first and never filtered out by distance.
   *
   * The position is the pickup's, not the handset's — the question is who is
   * near the *load*, and a customer booking a collection from their warehouse
   * while sitting at home would otherwise be shown the truckers near their
   * sofa.
   */
  haulers(position?: { lat: number; lng: number }): Promise<Hauler[]> {
    return api.get<Hauler[]>('portal/haulers', position);
  },

  /** Their receivables: what is owed, and what has been settled. */
  invoices(): Promise<PortalInvoice[]> {
    return api.get<PortalInvoice[]>('portal/invoices');
  },
};
