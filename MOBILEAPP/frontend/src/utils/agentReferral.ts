// Shared helpers for matching a job order's "Referred By" value to an agent account,
// and for classifying a job order's onsite status.

// Normalize a name for comparison: lowercase, strip punctuation (e.g. middle-initial dots),
// and collapse whitespace so " Raven  B. Ampere " => "raven b ampere".
export const normalizeName = (s?: string | null): string =>
  (s || '').toLowerCase().replace(/[.,]/g, ' ').replace(/\s+/g, ' ').trim();

// A job order is owned by the agent whose account name matches the Referred By value.
// Matching is tolerant of middle names / extra words: every word of the agent's
// "first_name + last_name" must appear (as a whole word) in Referred By. This covers
// values like "John Rusell Ampere" for an account named "John Ampere", while still
// rejecting unrelated names. Email exact-match is also accepted.
export const agentOwnsReferral = (referredByRaw: string, fullName: string, email: string): boolean => {
  const ref = normalizeName(referredByRaw);
  if (!ref) return false;

  const em = (email || '').toLowerCase().trim();
  if (em && ref === em) return true;

  const fn = normalizeName(fullName);
  if (!fn) return false;
  if (ref === fn) return true;

  const refTokens = new Set(ref.split(' '));
  const nameTokens = fn.split(' ').filter(t => t.length >= 2);
  return nameTokens.length > 0 && nameTokens.every(t => refTokens.has(t));
};

// Normalized onsite status of a job order.
export const getOnsiteStatus = (jo: any): string =>
  String(jo?.Onsite_Status || jo?.onsite_status || '').toLowerCase().trim();

// Active (still-in-the-field) job orders shown to agents on the Job Order page.
// Agents only see job orders that are in progress or rescheduled.
export const isActiveOnsiteStatus = (status: string): boolean =>
  status === 'inprogress' || status === 'in progress' || status === 'in-progress' ||
  status === 'reschedule' || status === 'rescheduled' || status === 're-schedule';

// Completed job orders shown to agents on the Agent History page.
export const isDoneOnsiteStatus = (status: string): boolean =>
  status === 'done' || status === 'completed';
