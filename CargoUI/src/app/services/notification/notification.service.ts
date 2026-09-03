import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { NotificationItem } from '../../models/notification/notification.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** Notification Management — the feed, and marking it read. */
@Injectable({ providedIn: 'root' })
export class NotificationService {
  private readonly api = inject(ApiService);

  /** The envelope: `meta.unread` drives the header dot and the nav badge. */
  list(query?: ListQuery): Observable<Envelope<NotificationItem[]>> {
    return this.api.envelope<NotificationItem[]>('notifications', query);
  }

  markRead(id: string): Observable<NotificationItem> {
    return this.api.post<NotificationItem>(`notifications/${id}/read`, {});
  }

  markAllRead(): Observable<{ marked: number }> {
    return this.api.post<{ marked: number }>('notifications/read-all', {});
  }
}
