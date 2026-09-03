import { NotificationItem } from '@/models/notification/notification.model';

import { api } from '../shared/api.service';

/**
 * The feed. A driver sees what is addressed to them plus anything sent to the
 * whole fleet — the API decides which, from the token.
 */
export const notificationService = {
  list(limit = 10): Promise<NotificationItem[]> {
    return api.get<NotificationItem[]>('notifications', { per_page: limit });
  },

  markRead(id: string): Promise<NotificationItem> {
    return api.post<NotificationItem>(`notifications/${id}/read`, {});
  },
};
