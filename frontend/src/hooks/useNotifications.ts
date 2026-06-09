import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';

export interface SuresignNotification {
  id: number;
  type: string;
  title: string;
  message: string;
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

export function useNotifications(filter?: 'all' | 'unread', type?: string) {
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
