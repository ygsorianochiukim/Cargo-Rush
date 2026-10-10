import { StatusTone } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

export interface NotificationItem extends Timestamped {
  id: string;
  icon: string;
  title: string;
  detail: string;
  at: string;
  tone: StatusTone;
  read: boolean;
}
