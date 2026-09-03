import { StatusTone } from '@/constants/status';

/** One row of the in-app feed — the same resource the web reads. */
export interface NotificationItem {
  id: string;
  /** A name from the shared icon set, never a URL. */
  icon: string;
  title: string;
  detail: string;
  /** ISO. The screen turns it into "8 min ago"; the API never formats. */
  at: string;
  tone: StatusTone;
  read: boolean;
}
