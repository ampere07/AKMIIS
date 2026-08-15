// Shared helpers for the Agent point of view: matching a job order's "Referred By"
// value to an agent account, classifying a job order's onsite status, and detecting
// agent users.
//
// Kept intentionally in sync with MOBILEAPP/frontend/src/utils/agentReferral.ts so the
// web portal and the mobile app resolve the exact same set of records for an agent.

export const AGENT_ROLE_ID = 4;

// Roles that are allowed to see every record regardless of referral ownership.
const SUPER_ROLE_IDS = ['1', '7', '8'];
const SUPER_ROLE_NAMES = ['administrator', 'superadmin', 'headtech'];

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

  // Compare the email against the RAW referral, not the normalized one: normalizeName
  // turns dots into spaces, so "juan@x.com" would become "juan@x com" and could never
  // equal the address it came from.
  const em = (email || '').toLowerCase().trim();
  if (em && (referredByRaw || '').toLowerCase().trim() === em) return true;

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

// Completed job orders — these count towards commissions and achievements.
export const isDoneOnsiteStatus = (status: string): boolean =>
  status === 'done' || status === 'completed';

// Job orders that did not result in an installation.
export const isFailedOnsiteStatus = (status: string): boolean =>
  status === 'failed' || status === 'cancelled' || status === 'suspended' || status === 'disapproved';

// Job orders awaiting another visit.
export const isRescheduleOnsiteStatus = (status: string): boolean =>
  status === 'reschedule' || status === 'rescheduled' || status === 're-schedule';

// Job orders that have not been visited yet.
export const isInProgressOnsiteStatus = (status: string): boolean =>
  status === 'in progress' || status === 'inprogress' || status === 'in-progress' || status === 'pending';

export const isAgentUser = (role?: string | null, roleId?: number | string | null): boolean =>
  (role || '').toLowerCase().trim() === 'agent' || String(roleId ?? '') === String(AGENT_ROLE_ID);

export const isSuperUser = (role?: string | null, roleId?: number | string | null): boolean =>
  SUPER_ROLE_NAMES.includes((role || '').toLowerCase().trim()) ||
  SUPER_ROLE_IDS.includes(String(roleId ?? ''));

export interface AgentIdentity {
  id: number | null;
  fullName: string;
  email: string;
  username: string;
  role: string;
  roleId: number | string | null;
  isAgent: boolean;
}

// Reads the logged-in user out of localStorage. Mirrors the mobile app, which reads the
// same payload out of AsyncStorage.
export const getStoredAgentIdentity = (): AgentIdentity => {
  const empty: AgentIdentity = {
    id: null, fullName: '', email: '', username: '', role: '', roleId: null, isAgent: false
  };

  try {
    const raw = localStorage.getItem('authData');
    if (!raw) return empty;

    const user = JSON.parse(raw);

    // The login payload exposes full_name; fall back to the name parts for older sessions.
    const fullName: string = user.full_name || [
      user.first_name,
      user.middle_initial ? `${String(user.middle_initial).trim()}.` : '',
      user.last_name
    ].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();

    return {
      id: user.id ?? user.user_id ?? null,
      fullName,
      email: user.email || user.email_address || '',
      username: user.username || '',
      role: user.role || '',
      roleId: user.role_id ?? null,
      isAgent: isAgentUser(user.role, user.role_id)
    };
  } catch (err) {
    console.error('[agentReferral] Failed to parse auth data:', err);
    return empty;
  }
};

// Agents only see job orders raised on or after this date. Everyone else sees
// the full history — this cut-off applies to the agent view alone.
//
// Change the date here to move the cut-off; it is read by both the web and the
// mobile Job Order pages so the two can never disagree.
export const AGENT_JOB_ORDER_START_DATE = '2026-08-10';

/**
 * The date a job order belongs to, for the purposes of the agent cut-off.
 *
 * Uses the job order's own timestamp — when the record was raised — falling
 * back to the installation date and then the created date, so a record with a
 * missing timestamp is still placed rather than silently dropped.
 */
export const jobOrderDate = (jo: any): Date | null => {
  const raw = jo?.Timestamp || jo?.timestamp
    || jo?.Date_Installed || jo?.date_installed
    || jo?.created_at || jo?.Created_At;

  if (!raw) return null;

  const parsed = new Date(raw);
  return isNaN(parsed.getTime()) ? null : parsed;
};

/**
 * Is this job order on or after the agent cut-off?
 *
 * A record with no usable date is kept rather than hidden: losing a referral
 * from an agent's own list is worse than showing one that is slightly old.
 */
export const isOnOrAfterAgentStartDate = (jo: any): boolean => {
  const date = jobOrderDate(jo);
  if (!date) return true;

  // Compared at day resolution so the whole of the start date is included,
  // whatever time of day the record carries.
  const start = new Date(`${AGENT_JOB_ORDER_START_DATE}T00:00:00`);
  return date.getTime() >= start.getTime();
};

// Onboarding achievements. Each tier rewards a number of onboarded referrals
// reached within a period, and resets when that period rolls over — so the
// weekly reward can be earned again next week.
//
// These mirror the server's config/achievements.php. The dashboard prefers the
// figures the API returns and falls back to these, so the two never disagree
// for long, and both clients read the same defaults.
export interface AchievementTier {
  key: 'weekly' | 'monthly';
  label: string;
  target: number;
  reward: number;
}

export const ACHIEVEMENT_TIERS: AchievementTier[] = [
  { key: 'weekly',  label: 'Weekly Achievement',  target: 25,  reward: 1000 },
  { key: 'monthly', label: 'Monthly Achievement', target: 100, reward: 15000 },
];

// -- Reset countdown ---------------------------------------------------------
//
// Each tier resets when its period rolls over: the weekly count returns to zero
// at the start of a new week, the monthly count at the start of a new month.
// The two run on independent clocks and are counted independently.
//
// The server decides when a period ends and sends that instant back as an
// absolute time. The dashboards only count down to it — they never work the
// boundary out themselves, because a device in another timezone (or with a
// wrong clock) would land on a different moment than the server resets on.

/** How long the countdown has left, in milliseconds. Never negative. */
export const millisUntilReset = (resetsAt: number | null, serverNow: number): number => {
  if (resetsAt === null || !isFinite(resetsAt)) return 0;
  return Math.max(0, resetsAt - serverNow);
};

/**
 * A countdown for display: "6d 04:13:56" once a day or more is left, and
 * "04:13:56" below that, so the final day reads as a plain clock.
 */
export const formatCountdown = (ms: number): string => {
  const total = Math.max(0, Math.floor(ms / 1000));
  const days = Math.floor(total / 86400);
  const hours = Math.floor((total % 86400) / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const seconds = total % 60;

  const pad = (n: number) => String(n).padStart(2, '0');
  const clock = `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
  return days > 0 ? `${days}d ${clock}` : clock;
};

/** Parses the reset instant the server sent. Returns null if it is unusable. */
export const parseResetsAt = (raw: unknown): number | null => {
  if (typeof raw !== 'string' || !raw) return null;
  const ms = new Date(raw).getTime();
  return isNaN(ms) ? null : ms;
};

/**
 * How far the device clock is behind the server's, in milliseconds.
 *
 * Added to the device time to get the server's view of "now", so a phone set to
 * the wrong time still counts down to the right moment. Zero when the server
 * did not say, which leaves the device clock trusted as before.
 */
export const clockSkewFrom = (serverTime: unknown, deviceNow: number): number => {
  const server = parseResetsAt(serverTime);
  return server === null ? 0 : server - deviceNow;
};
