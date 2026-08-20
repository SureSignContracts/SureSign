import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';

export type NotificationStatus   = 'unread' | 'read' | 'dismissed' | 'resolved' | 'expired';
export type NotificationPriority = 'critical' | 'warning' | 'reminder' | 'info';
export type NotificationCategory =
  | 'commercial' | 'contract' | 'programme' | 'compliance'
  | 'payment' | 'variation' | 'retention' | 'deliverable'
  | 'notice' | 'risk' | 'communication' | 'general';

export interface SuresignNotification {
  id: number;
  type: string;
  category: NotificationCategory | null;
  priority: NotificationPriority | null;
  status: NotificationStatus;
  title: string;
  message: string;
  source_type: string | null;
  source_id: number | null;
  source_field: string | null;
  action_url: string | null;
  project_id: number | null;
  organization_id: number | null;
  data?: Record<string, unknown> | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface NotificationsResponse {
  data: SuresignNotification[];
  total: number;
  unread_count: number;
  current_page: number;
  last_page: number;
}

export type NotificationFilter =
  | 'active' | 'all' | 'unread' | 'read' | 'dismissed' | 'resolved' | 'expired'
  | 'critical' | 'warning' | 'reminder' | 'info'
  | 'commercial' | 'contract' | 'payment' | 'variation' | 'risk' | 'deliverable'
  | 'programme' | 'compliance' | 'notice' | 'retention' | 'communication' | 'general';

export function useNotifications(filter?: NotificationFilter, type?: string, options?: { enabled?: boolean }) {
  const { data, isLoading, error, refetch } = useQuery<NotificationsResponse>({
    queryKey: ['notifications', filter, type],
    queryFn: async () => {
      const params: Record<string, string> = {};
      if (filter) params.filter = filter;
      if (type) params.type = type;
      const response = await api.get('/notifications', { params });
      return response.data;
    },
    refetchInterval: 60000,
    // Defaults to true — every existing caller (NotificationBell) omits
    // this and is unaffected. Callers that need to gate the fetch on
    // readiness (e.g. the shell-level new-notification watcher, which must
    // not fire before auth/workspace context resolves) pass `enabled: false`
    // until then. React Query shares one underlying query per queryKey, so
    // this and NotificationBell's own call never cause a duplicate request.
    enabled: options?.enabled ?? true,
  });

  return {
    notifications: data?.data ?? [],
    unreadCount: data?.unread_count ?? 0,
    isLoading,
    error,
    refetch,
  };
}

export function useUnreadCount() {
  const { data, refetch } = useQuery<{ count: number }>({
    queryKey: ['notifications-count'],
    queryFn: async () => {
      const response = await api.get('/notifications/unread-count');
      return response.data;
    },
    refetchInterval: 60000,
  });

  return {
    count: data?.count ?? 0,
    refetch,
  };
}

export async function markNotificationRead(id: number): Promise<void> {
  await api.patch(`/notifications/${id}/read`);
}

export async function markAllNotificationsRead(): Promise<void> {
  await api.post('/notifications/mark-all-read');
}
