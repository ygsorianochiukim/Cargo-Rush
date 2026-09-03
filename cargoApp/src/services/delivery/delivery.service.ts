import { DeliveryLog, ProofOfDelivery } from '@/models/delivery/delivery.model';

import { api } from '../shared/api.service';

/**
 * Turn the proof into a multipart body.
 *
 * Shared between the two writes that send one — the driver's hand-off and a
 * late attachment — because a photograph appended under a different field name
 * by one of them is a photograph the API quietly ignores.
 */
export function proofForm(proof: ProofOfDelivery): FormData {
  const body = new FormData();

  body.append('receiver_name', proof.receiver_name);

  if (proof.photo) {
    // React Native's FormData takes this shape rather than a `File`; the
    // bridge reads the local URI when it writes the request body.
    body.append('photo', proof.photo as unknown as Blob);
  }

  return body;
}

/** Delivery Logs — trip history and proof of delivery. */
export const deliveryService = {
  /** This driver's history. The API scopes it from the token. */
  history(driverId: string): Promise<DeliveryLog[]> {
    return api.get<DeliveryLog[]>('delivery-logs', { driver_id: driverId });
  },

  /**
   * Proof of delivery, captured at the door.
   *
   * Multipart, because the photograph is the substance of the write.
   */
  attachProof(logId: string, proof: ProofOfDelivery): Promise<DeliveryLog> {
    return api.postForm<DeliveryLog>(`delivery-logs/${logId}/proof`, proofForm(proof));
  },
};
