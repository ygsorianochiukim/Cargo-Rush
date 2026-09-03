import { StatusTone } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/** Notification Management — the in-app feed. */
export interface NotificationItem extends Timestamped {
  id: string;
  /** A name from the shared icon set. */
  icon: string;
  title: string;
  detail: string;
  /** ISO. The client turns it into "5 hrs ago". */
  at: string;
  tone: StatusTone;
  read: boolean;
}
