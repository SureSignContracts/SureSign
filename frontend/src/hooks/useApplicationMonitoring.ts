import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';

export interface OnlineUser {
  user_id: number;
  name: string | null;
  email: string | null;
  role: string | null;
  organization_id: number | null;
  organization_name: string | null;
  module_key: string | null;
  module_label: string | null;
  last_active_at: string | null;
}

export interface PresenceBlock {
  available: boolean;
  online_count: number | null;
  active_organizations_count: number | null;
  authenticated_activity_last_15_min: number | null;
  online_users: OnlineUser[];
}

export interface ActiveUsersBlock {
  dau: number | null;
  wau: number | null;
  mau: number | null;
  daily_trend: { date: string; active_users: number }[];
}

export interface ModuleUsageRow {
  module_key: string;
  label: string;
  total_visits: number;
  /**
   * Sum of each day's distinct-user count over the selected period, not a
   * true period-distinct count — a user active on 3 different days
   * contributes 3, not 1. Named "user-days" rather than "unique users" for
   * exactly that reason. See `active_user_days_definition` below.
   */
  active_user_days: number;
}

export interface ModuleUsageBlock {
  today: ModuleUsageRow[];
  last_7_days: ModuleUsageRow[];
  last_30_days: ModuleUsageRow[];
  active_user_days_definition: string | null;
}

export interface QueueBlock {
  pending_jobs: number | null;
  failed_jobs_total: number | null;
  failed_jobs_24h: number | null;
  oldest_pending_job_age_seconds: number | null;
  status: 'healthy' | 'attention' | 'unknown';
}

export interface AiBlock {
  pending: number | null;
  processing: number | null;
  started_today: number | null;
  completed_today: number | null;
  failed_today: number | null;
  stuck_count: number | null;
  oldest_processing_started_at: string | null;
  timestamp_limitation: string | null;
}

export interface DocumentsBlock {
  uploaded_today: number | null;
  generated_today: number | null;
}

export interface NotificationsBlock {
  created_today: number | null;
  unread_total: number | null;
}

export interface ApplicationMonitoringSummary {
  generated_at: string;
  timezone: string;
  presence_definition: string;
  presence: PresenceBlock;
  active_users: ActiveUsersBlock;
  module_usage: ModuleUsageBlock;
  application_actions: {
    last_15_minutes: number | null;
    last_hour: number | null;
    today: number | null;
  };
  queue: QueueBlock;
  ai: AiBlock;
  documents: DocumentsBlock;
  notifications: NotificationsBlock;
  warnings: string[];
  unavailable_sources: string[];
}

/**
 * Super Admin Application Monitoring — polls the summary endpoint at a
 * sensible interval and pauses while the tab is hidden (React Query's
 * default `refetchIntervalInBackground: false`), matching the 30-60s
 * cadence used elsewhere on the platform (see useNotifications.ts).
 */
export function useApplicationMonitoring() {
  return useQuery<ApplicationMonitoringSummary>({
    queryKey: ['application-monitoring'],
    queryFn: () => api.get('/admin/application-monitoring').then(r => r.data),
    refetchInterval: 60000,
    staleTime: 20000,
  });
}
