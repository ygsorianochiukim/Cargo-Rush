import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { NotificationItem } from '../../models/notification/notification.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

@Injectable({ providedIn: 'root' })
export class NotificationService {
  private readonly api = inject(ApiService);

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
