import { StatusValue } from '@/constants/status';

/** Delivery Logs — trip history and proof of delivery. */
export interface DeliveryLog {
  id: string;
  trip_id: string;
  reference: string | null;
  customer: string | null;
  destination: string | null;
  driver_name: string | null;
  helper_name: string | null;
  delivered_at: string | null;
  /** Assigned by the API (`POD-00001`), never typed in the cab. */
  pod_ref: string | null;
  /** The photograph taken at the door. Null when none was sent. */
  pod_image_url: string | null;
  receiver_name: string | null;
  status: StatusValue;
}

/**
 * A photograph the driver has picked or taken, in the shape `FormData` wants.
 *
 * React Native's `FormData` takes `{ uri, name, type }` rather than a `File` —
 * there is no `File` on a handset, and the bridge reads the local URI when it
 * writes the request body.
 */
export interface ProofPhoto {
  uri: string;
  name: string;
  type: string;
}

/**
 * What the driver posts when the consignee signs.
 *
 * No `pod_ref`. It used to be required and the driver had nowhere to get one
 * from, so it was invented at the door — the API assigns it now, the same way
 * it assigns a trip's reference.
 *
 * `receiver_name` is the signature, typed. A drawn one needs a canvas and a
 * stroke format; a typed name against a photograph is already better evidence
 * than a made-up number, and when the canvas arrives it replaces how this is
 * captured, not what it means.
 */
export interface ProofOfDelivery {
  receiver_name: string;
  /** Optional: a gate with no signal must not be a dead end. */
  photo?: ProofPhoto | null;
}
