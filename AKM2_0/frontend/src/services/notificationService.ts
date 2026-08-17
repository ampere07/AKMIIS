import apiClient from '../config/api';
import { requestCache } from '../utils/requestCache';

// Not named NotificationType: this module already exports that name as an
// alias of the Notification interface, and something may still import it.
export type NotificationKind =
  | 'application'
  | 'job_order_done'
  | 'service_order_done'
  | 'transaction_revert';

export interface Notification {
  id: number;
  customer_name: string;
  plan_name: string;
  status?: string;
  created_at?: string;
  formatted_date: string;
  // Consolidated fields
  type?: NotificationKind;
  title?: string;
  message?: string;
  timestamp?: number;
  raw_date?: string;
}

/**
 * Where a click on each kind of notification goes.
 *
 * The id on every entry is the id of the underlying record, so the section and
 * the id together are enough to open the right row. Kept here rather than in
 * Header so the mapping has one home if another surface starts routing them.
 */
export const NOTIFICATION_TARGET_SECTION: Record<NotificationKind, string> = {
  application: 'application-management',
  job_order_done: 'job-order',
  service_order_done: 'service-order',
  transaction_revert: 'transactions-revert',
};

export interface NotificationResponse {
  success: boolean;
  data: Notification[];
}

export interface UnreadCountResponse {
  success: boolean;
  count: number;
}

interface AxiosErrorType {
  response?: {
    status: number;
    data: any;
  };
  message: string;
  isAxiosError: boolean;
}

const isAxiosError = (error: any): error is AxiosErrorType => {
  return error && error.isAxiosError === true;
};

export const notificationService = {
  async getRecentApplications(limit: number = 10): Promise<Notification[]> {
    try {
      return await requestCache.get(
        `notifications_${limit}`,
        async () => {
          const response = await apiClient.get<NotificationResponse>(`/notifications/recent-applications?limit=${limit}&t=${Date.now()}`);
          return response.data.data || [];
        },
        2000
      );
    } catch (error) {
      if (isAxiosError(error)) {
        console.error('Failed to fetch notifications:', {
          status: error.response?.status,
          data: error.response?.data,
          message: error.message
        });
      } else {
        console.error('Failed to fetch notifications:', error);
      }
      return [];
    }
  },

  async getUnreadCount(): Promise<number> {
    try {
      return await requestCache.get(
        'unread_count',
        async () => {
          const response = await apiClient.get<UnreadCountResponse>(`/notifications/unread-count?t=${Date.now()}`);
          return response.data.count || 0;
        },
        2000
      );
    } catch (error) {
      if (isAxiosError(error)) {
        console.error('Failed to fetch unread count:', {
          status: error.response?.status,
          data: error.response?.data,
          message: error.message
        });
      } else {
        console.error('Failed to fetch unread count:', error);
      }
      return 0;
    }
  },

  async getConsolidatedStream(limit: number = 15): Promise<Notification[]> {
    try {
      // Use shorter cache for stream to feel more "real-time"
      return await requestCache.get(
        `consolidated_stream_${limit}`,
        async () => {
          const response = await apiClient.get<{ success: boolean, data: Notification[] }>(`/notifications/consolidated?limit=${limit}&t=${Date.now()}`);
          return response.data.data || [];
        },
        1000
      );
    } catch (error) {
      console.error('Failed to fetch consolidated stream:', error);
      return [];
    }
  }
};

export type { Notification as NotificationType };
