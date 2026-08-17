import apiClient from '../config/api';
import { requestCache } from '../utils/requestCache';

/**
 * Open-task counts behind the sidebar pills and the header bell.
 *
 * Keys match the backend one for one; `total` is their sum and is what the bell
 * adds to its unread count.
 */
export interface NavBadgeCounts {
  application: number;
  job_order: number;
  service_order: number;
  work_order: number;
  transaction: number;
  total: number;
}

export interface NavBadgeResponse {
  success: boolean;
  data: NavBadgeCounts;
}

export const EMPTY_NAV_BADGES: NavBadgeCounts = {
  application: 0,
  job_order: 0,
  service_order: 0,
  work_order: 0,
  transaction: 0,
  total: 0,
};

/**
 * Which sidebar entry each counter decorates.
 *
 * Sidebar ids, so a badge can be looked up by menu item without the menu
 * needing to know anything about the shape of the counts.
 */
export const NAV_BADGE_SECTIONS: Record<string, keyof NavBadgeCounts> = {
  'application-management': 'application',
  'job-order': 'job_order',
  'service-order': 'service_order',
  'work-order': 'work_order',
  'transaction-list': 'transaction',
};

export const navBadgeService = {
  async getCounts(): Promise<NavBadgeCounts> {
    try {
      // Short cache: the sidebar and the header both ask, and with several
      // panels mounted this would otherwise be one request per consumer per
      // poll rather than one per poll.
      return await requestCache.get(
        'nav_badge_counts',
        async () => {
          const response = await apiClient.get<NavBadgeResponse>(`/notifications/nav-badges?t=${Date.now()}`);
          return response.data?.data || EMPTY_NAV_BADGES;
        },
        5000
      );
    } catch (error) {
      // Zeros render no badges at all, which is the right way for this to
      // fail — the navigation itself must not depend on the decoration.
      console.error('Failed to fetch navigation badge counts:', error);
      return EMPTY_NAV_BADGES;
    }
  },
};
