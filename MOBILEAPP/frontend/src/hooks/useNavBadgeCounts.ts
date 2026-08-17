import { useMemo } from 'react';
import { useJobOrderContext } from '../contexts/JobOrderContext';
import { useServiceOrderContext } from '../contexts/ServiceOrderContext';

/**
 * How much work is still open on a technician's own queue.
 *
 * Costs nothing to ask. Both contexts are already loaded and already scoped
 * server-side to the signed-in technician's assigned_email, so these counts are
 * derived from lists the app has in memory — no request, no endpoint, and no
 * risk of the badge disagreeing with the list it sits above.
 *
 * Only technicians get counts. Everyone else sees zeros, because for an
 * administrator "all open job orders" is a company-wide backlog rather than a
 * personal to-do list, and badging it would be noise. The web sidebar covers
 * that case properly with organization-scoped counters.
 */
export interface TechnicianNavBadgeCounts {
  jobOrder: number;
  serviceOrder: number;
  total: number;
}

const EMPTY: TechnicianNavBadgeCounts = { jobOrder: 0, serviceOrder: 0, total: 0 };

/** Anything in here is finished and must not be counted as outstanding. */
const CLOSED_STATUSES = ['done', 'completed', 'complete', 'failed', 'cancelled', 'canceled'];

const isClosed = (status?: string | null): boolean => {
  if (!status) return false; // No status yet means not started, which is open.
  return CLOSED_STATUSES.includes(String(status).trim().toLowerCase());
};

export const useNavBadgeCounts = (
  userRole?: string,
  roleId?: number | string
): TechnicianNavBadgeCounts => {
  const { jobOrders } = useJobOrderContext();
  const { serviceOrders } = useServiceOrderContext();

  const isTechnician =
    String(userRole || '').trim().toLowerCase() === 'technician' || String(roleId) === '2';

  return useMemo(() => {
    if (!isTechnician) return EMPTY;

    // A job order is still the technician's problem until the onsite work is
    // closed out. Billing status is deliberately ignored here — what happens
    // after the visit is not something the field can act on.
    const jobOrder = jobOrders.filter(order => !isClosed(order.Onsite_Status)).length;

    // Service orders close on either axis: the visit being done, or the ticket
    // being resolved without one.
    const serviceOrder = serviceOrders.filter(
      order => !isClosed(order.visitStatus) && !isClosed(order.supportStatus)
    ).length;

    return { jobOrder, serviceOrder, total: jobOrder + serviceOrder };
  }, [isTechnician, jobOrders, serviceOrders]);
};

export default useNavBadgeCounts;
