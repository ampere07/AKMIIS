import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { RefreshCcw, RefreshCw, Loader2, Timer } from 'lucide-react';
import { agentPortalService } from '../services/commissionService';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { useJobOrderStore } from '../store/jobOrderStore';
import {
    ACHIEVEMENT_TIERS,
    AchievementTier,
    AgentIdentity,
    agentOwnsReferral,
    clockSkewFrom,
    formatCountdown,
    getOnsiteStatus,
    getStoredAgentIdentity,
    isDoneOnsiteStatus,
    isFailedOnsiteStatus,
    isInProgressOnsiteStatus,
    isOnOrAfterAgentStartDate,
    isRescheduleOnsiteStatus,
    millisUntilReset,
    parseResetsAt
} from '../utils/agentReferral';

/**
 * One achievement tier as the server reports it.
 *
 * The count is bounded to the current week or month server side — the dashboard
 * cannot work that out from the job order list alone, because a referral's
 * completion date decides which period it belongs to.
 */
interface ServerTier {
    key: 'weekly' | 'monthly';
    label: string;
    target: number;
    reward: number;
    onboarded: number;
    reached: boolean;
    claimed: boolean;
    claimable: boolean;
    /** When this tier's count returns to zero, in epoch milliseconds. */
    resetsAt: number | null;
    /**
     * True when a claim set this cycle going rather than the calendar.
     *
     * Claiming early ends the cycle there and starts a fresh one of the same
     * length, so the cycle no longer runs Monday to Sunday and calling it "this
     * week" would be wrong.
     */
    anchored: boolean;
}

/**
 * How long after a reset falls due before asking the server for the new period.
 *
 * Also the shortest gap between those attempts, so a small clock difference
 * turns into one or two quiet retries rather than a burst of requests.
 */
const RESET_RELOAD_GRACE_MS = 3000;

interface DashboardAgentProps {
    onNavigate?: (section: string, extra?: string) => void;
}

interface Cashout {
    id: number | string;
    ref_number?: string;
    total_amount?: number | string;
    created_at?: string;
}

// The agent view is presented in light mode in both clients so the gradient balance
// card renders identically on the web and in the mobile app.
const PAGE_BG = '#f9fafb';

const formatCurrency = (amount: number): string => {
    const isNegative = amount < 0;
    const formatted = Math.abs(amount).toFixed(0).replace(/\d(?=(\d{3})+$)/g, '$&,');
    return `₱${isNegative ? '-' : ''}${formatted}`;
};

const formatDate = (dateStr?: string): string => {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return '-';
    return date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
};

// Three-quarter-arc speedometer matching the mobile achievement gauge.
const AchievementGauge: React.FC<{ value: number; target: number; color: string }> = ({ value, target, color }) => {
    const size = 220;
    const radius = (size - 30) / 2;
    const center = size / 2;
    const circumference = 2 * Math.PI * radius;
    const arcLength = circumference * 0.75;
    const progress = target > 0 ? Math.min(Math.max(value / target, 0), 1) : 0;

    return (
        <div className="relative" style={{ width: size, height: size }}>
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                <g transform={`rotate(135 ${center} ${center})`}>
                    <circle
                        cx={center}
                        cy={center}
                        r={radius}
                        stroke="#e2e8f0"
                        strokeWidth={30}
                        strokeDasharray={`${arcLength} ${circumference}`}
                        fill="none"
                        strokeLinecap="round"
                    />
                    <circle
                        cx={center}
                        cy={center}
                        r={radius}
                        stroke={color}
                        strokeWidth={30}
                        strokeDasharray={`${arcLength} ${circumference}`}
                        strokeDashoffset={arcLength * (1 - progress)}
                        fill="none"
                        strokeLinecap="round"
                        style={{ transition: 'stroke-dashoffset 1.2s ease-out' }}
                    />
                </g>
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-5xl font-extrabold leading-none text-slate-900">{value}</span>
                <span className="mt-1 text-xs font-bold uppercase tracking-widest text-slate-500">Onboarded</span>
            </div>
        </div>
    );
};

const DashboardAgent: React.FC<DashboardAgentProps> = ({ onNavigate }) => {
    const [identity] = useState<AgentIdentity>(() => getStoredAgentIdentity());
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);

    const { jobOrders, silentRefresh } = useJobOrderStore();

    const [cashouts, setCashouts] = useState<Cashout[]>([]);
    const [agentBalance, setAgentBalance] = useState(0);
    /** Commission earned from approved job orders — agent_balance.commission_value. */
    const [agentCommission, setAgentCommission] = useState(0);
    const [agentIncentives, setAgentIncentives] = useState(0);
    const [agentBonus, setAgentBonus] = useState(0);
    const [agentAchievement, setAgentAchievement] = useState(0);

    /** Tier state as the server reports it: period-bounded counts and claim state. */
    const [serverTiers, setServerTiers] = useState<ServerTier[]>([]);
    /**
     * Ticks once a second to drive the reset countdowns.
     *
     * The countdown is derived from this and the reset instant the server sent,
     * never accumulated — so reopening the dashboard picks the clock back up
     * where it genuinely stands instead of starting the period again.
     */
    const [nowTs, setNowTs] = useState<number>(() => Date.now());
    /** Device-to-server clock difference, applied before every comparison. */
    const clockSkew = useRef(0);
    /** When a reload was last triggered by a rollover, to space out retries. */
    const lastResetReload = useRef(0);
    /** Which achievement card is showing. 0 is Weekly, the default on load. */
    const [activeTier, setActiveTier] = useState(0);
    const [swipeOffset, setSwipeOffset] = useState(0);
    const [isSwiping, setIsSwiping] = useState(false);
    const swipeStartX = useRef<number | null>(null);

    const [isClaiming, setIsClaiming] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [isCardFlipped, setIsCardFlipped] = useState(false);

    const primaryColor = colorPalette?.primary || '#ef4444';

    useEffect(() => {
        const fetchColorPalette = async () => {
            try {
                setColorPalette(await settingsColorPaletteService.getActive());
            } catch (err) {
                console.error('[DashboardAgent] Failed to fetch color palette:', err);
            }
        };
        fetchColorPalette();

        const handlePaletteUpdate = () => fetchColorPalette();
        window.addEventListener('palette-updated', handlePaletteUpdate);
        return () => window.removeEventListener('palette-updated', handlePaletteUpdate);
    }, []);

    const fetchHistory = useCallback(async () => {
        try {
            const response = await agentPortalService.getCommissionHistory();
            if (response?.success) {
                setCashouts(response.data || []);
                setAgentBalance(Number(response.balance ?? 0));
                // What the agent has earned in commission from approved job
                // orders. Its own column, separate from the spendable balance.
                setAgentCommission(Number(response.commission_value ?? 0));
                setAgentIncentives(Number(response.incentives ?? 0));
                setAgentBonus(Number(response.bonus ?? 0));
                setAgentAchievement(Number(response.achievement ?? 0));
            }
        } catch (err) {
            console.error('[DashboardAgent] Failed to fetch cashout history:', err);
        }
    }, []);

    const fetchAchievements = useCallback(async (agentId: number) => {
        try {
            const response = await agentPortalService.getAchievements(agentId);

            // The server returns each tier already scoped to the current period,
            // with its target, reward, progress and whether it has been claimed.
            const tiersFromServer = (response as any)?.tiers;
            if (tiersFromServer && typeof tiersFromServer === 'object') {
                // Trust the server's clock over the device's for the countdown.
                clockSkew.current = clockSkewFrom((response as any)?.server_time, Date.now());

                setServerTiers(
                    Object.values(tiersFromServer).map((t: any) => ({
                        key: t.key,
                        label: t.label,
                        target: Number(t.target ?? 0),
                        reward: Number(t.reward ?? 0),
                        onboarded: Number(t.onboarded ?? 0),
                        reached: Boolean(t.reached),
                        claimed: Boolean(t.claimed),
                        claimable: Boolean(t.claimable),
                        resetsAt: parseResetsAt(t.resets_at),
                        anchored: Boolean(t.anchored),
                    }))
                );
            }
        } catch (err) {
            console.error('[DashboardAgent] Failed to fetch achievements:', err);
        }
    }, []);

    const loadAll = useCallback(async () => {
        const agentId = identity.id;
        await Promise.all([
            fetchHistory(),
            agentId ? fetchAchievements(agentId) : Promise.resolve(),
            silentRefresh()
        ]);
    }, [identity.id, fetchHistory, fetchAchievements, silentRefresh]);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            await loadAll();
            if (!cancelled) setIsLoading(false);
        })();
        return () => { cancelled = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [identity.id]);

    const handleRefresh = useCallback(async () => {
        setIsRefreshing(true);
        try {
            await loadAll();
        } finally {
            setIsRefreshing(false);
        }
    }, [loadAll]);

    // Drives the reset countdowns.
    useEffect(() => {
        const tick = window.setInterval(() => setNowTs(Date.now()), 1000);
        return () => window.clearInterval(tick);
    }, []);

    /**
     * Reloads the moment a period rolls over, so the count returns to zero and
     * the next countdown begins without anyone refreshing the page.
     *
     * Driven by the tick rather than a timer set for the boundary: a monthly
     * period can be more than 24 days out, which overflows a browser timeout
     * and would fire it immediately. If the server has not moved on yet — its
     * clock being a moment behind — the spacing below turns that into a quiet
     * retry every few seconds until it has.
     */
    useEffect(() => {
        if (!serverTiers.length) return;

        const serverNow = nowTs + clockSkew.current;
        const rolledOver = serverTiers.some(t => t.resetsAt !== null && serverNow >= t.resetsAt);
        if (!rolledOver) return;

        if (nowTs - lastResetReload.current < RESET_RELOAD_GRACE_MS) return;
        lastResetReload.current = nowTs;

        loadAll();
    }, [nowTs, serverTiers, loadAll]);

    /**
     * Time left before each tier resets, keyed by tier.
     *
     * Weekly and monthly are read from their own reset instants, so the two
     * countdowns run independently — the weekly one rolling over leaves the
     * monthly one untouched.
     */
    const countdowns = useMemo(() => {
        const serverNow = nowTs + clockSkew.current;
        const out: Record<string, string> = {};

        serverTiers.forEach(tier => {
            if (tier.resetsAt === null) return;
            out[tier.key] = formatCountdown(millisUntilReset(tier.resetsAt, serverNow));
        });

        return out;
    }, [serverTiers, nowTs]);

    // Referral funnel counts, derived from the agent's own job orders exactly as the
    // mobile dashboard derives them.
    const { inProgressCount, onboardedCount, failedCount, rescheduleCount } = useMemo(() => {
        if (!identity.fullName && !identity.email) {
            return { inProgressCount: 0, onboardedCount: 0, failedCount: 0, rescheduleCount: 0 };
        }

        // Scoped to the programme start date, exactly as the Job Order page is.
        // Without this the tiles would count an agent's whole history while the
        // list below shows only what the programme covers, and neither those
        // figures nor the achievement count would reconcile with the other.
        const mine = jobOrders.filter((jo: any) =>
            agentOwnsReferral(jo.Referred_By || jo.referred_by || '', identity.fullName, identity.email)
            && isOnOrAfterAgentStartDate(jo)
        );

        const countBy = (predicate: (status: string) => boolean) =>
            mine.filter((jo: any) => predicate(getOnsiteStatus(jo))).length;

        return {
            inProgressCount: countBy(isInProgressOnsiteStatus),
            onboardedCount: countBy(isDoneOnsiteStatus),
            failedCount: countBy(isFailedOnsiteStatus),
            rescheduleCount: countBy(isRescheduleOnsiteStatus)
        };
    }, [jobOrders, identity.fullName, identity.email]);

    // The tiers on offer. The server is the authority on targets and rewards, so
    // its figures win; the shared defaults cover the moment before they arrive.
    const tiers: AchievementTier[] = useMemo(() => {
        if (!serverTiers.length) return ACHIEVEMENT_TIERS;

        return ACHIEVEMENT_TIERS.map(fallback => {
            const fromServer = serverTiers.find(t => t.key === fallback.key);
            return fromServer
                ? { ...fallback, label: fromServer.label, target: fromServer.target, reward: fromServer.reward }
                : fallback;
        });
    }, [serverTiers]);

    /**
     * Progress toward one tier.
     *
     * The count comes from the server where it is available, because only the
     * server can bound it to the current week or month. Before that arrives —
     * and for an agent whose tiers have not loaded — it falls back to the same
     * onboarded figure the rest of this dashboard already shows, so the card is
     * never blank.
     */
    const tierProgress = useCallback((tier: AchievementTier) => {
        const fromServer = serverTiers.find(t => t.key === tier.key);
        const onboarded = fromServer ? fromServer.onboarded : onboardedCount;
        const capped = Math.min(onboarded, tier.target);
        const ratio = tier.target > 0 ? Math.min(onboarded / tier.target, 1) : 0;

        return {
            onboarded: capped,
            ratio,
            reached: fromServer ? fromServer.reached : onboarded >= tier.target,
            claimed: fromServer ? fromServer.claimed : false,
            claimable: fromServer ? fromServer.claimable : false,
            anchored: fromServer ? fromServer.anchored : false,
        };
    }, [serverTiers, onboardedCount]);

    /**
     * What to call the current cycle in the card's copy.
     *
     * A cycle that began when a reward was claimed no longer lines up with the
     * calendar, so "this week" would be wrong — the countdown beside it is the
     * honest answer in that case.
     */
    const cycleNoun = (tier: AchievementTier, anchored: boolean): string =>
        anchored ? 'in this cycle' : (tier.key === 'weekly' ? 'this week' : 'this month');

    const handleClaimReward = async (tier: AchievementTier) => {
        if (!identity.id || isClaiming) return;
        setIsClaiming(true);
        try {
            const response = await agentPortalService.claimAchievement({
                agent_id: identity.id,
                type: tier.key,
                milestone: tier.target
            });

            if (response?.success) {
                window.alert(
                    `Reward claimed! ${formatCurrency(tier.reward)} has been added to your balance ` +
                    `for reaching ${tier.target} onboards.`
                );
                // Reload both the balances and the tier state, so the card moves
                // straight to "Claimed" rather than offering the reward again.
                await loadAll();
            } else {
                window.alert(response?.message || 'Failed to claim reward.');
            }
        } catch (err: any) {
            window.alert(err?.response?.data?.message || 'An unexpected error occurred.');
        } finally {
            setIsClaiming(false);
        }
    };

    // ── Swipe between the two achievement cards ──────────────────────────────
    // Only one card is ever in view; dragging moves the track with the pointer
    // and settles on the nearer card when released.
    const SWIPE_THRESHOLD = 60;

    const onSwipeStart = (e: React.TouchEvent | React.MouseEvent) => {
        const x = 'touches' in e ? e.touches[0].clientX : e.clientX;
        swipeStartX.current = x;
        setIsSwiping(true);
    };

    const onSwipeMove = (e: React.TouchEvent | React.MouseEvent) => {
        if (!isSwiping || swipeStartX.current === null) return;
        const x = 'touches' in e ? e.touches[0].clientX : e.clientX;
        const delta = x - swipeStartX.current;

        // Resist dragging past the first or last card, so the edges feel solid.
        const atStart = activeTier === 0 && delta > 0;
        const atEnd = activeTier === tiers.length - 1 && delta < 0;
        setSwipeOffset(atStart || atEnd ? delta * 0.25 : delta);
    };

    const onSwipeEnd = () => {
        if (!isSwiping) return;

        if (swipeOffset <= -SWIPE_THRESHOLD && activeTier < tiers.length - 1) {
            setActiveTier(activeTier + 1);          // swipe left  → Monthly
        } else if (swipeOffset >= SWIPE_THRESHOLD && activeTier > 0) {
            setActiveTier(activeTier - 1);          // swipe right → Weekly
        }

        setIsSwiping(false);
        setSwipeOffset(0);
        swipeStartX.current = null;
    };

    const latestCashouts = useMemo(() => cashouts.slice(0, 5), [cashouts]);
    // Every bucket that can actually be cashed out, in the same order the payout
    // screen drains them. `achievement` is deliberately absent: a claimed reward
    // is already paid into `balance`, and that figure is only a lifetime
    // "rewards earned" total for the tile below — adding it would count the
    // reward twice.
    const totalBalance = agentCommission + agentBalance + agentIncentives + agentBonus;

    const displayName = identity.fullName || 'Agent';
    const initials = displayName.split(' ').filter(Boolean).map(n => n[0]).join('').substring(0, 2).toUpperCase();

    const balanceTiles = [
        { label: 'Incentives', value: agentIncentives },
        // Commission earned from approved job orders, not the spendable
        // balance — those are separate columns and pay out separately.
        { label: 'Commission', value: agentCommission },
        { label: 'Bonus', value: agentBonus },
        { label: 'Achievement', value: agentAchievement }
    ];

    const funnelTiles = [
        { label: 'In Progress', value: inProgressCount },
        { label: 'Done', value: onboardedCount },
        { label: 'Failed', value: failedCount },
        { label: 'Reschedule', value: rescheduleCount }
    ];

    if (isLoading) {
        return (
            <div className="flex h-full items-center justify-center" style={{ backgroundColor: PAGE_BG }}>
                <Loader2 className="h-8 w-8 animate-spin" style={{ color: primaryColor }} />
            </div>
        );
    }

    return (
        <div className="h-full overflow-y-auto" style={{ backgroundColor: PAGE_BG }}>
            <div className="mx-auto max-w-5xl space-y-8 px-4 py-6 md:px-8 md:py-8">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-bold text-slate-900 md:text-2xl">Agent Dashboard</h1>
                    <button
                        onClick={handleRefresh}
                        disabled={isRefreshing}
                        className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                        title="Refresh"
                    >
                        <RefreshCw size={16} className={isRefreshing ? 'animate-spin' : ''} style={{ color: primaryColor }} />
                        <span className="hidden sm:inline">Refresh</span>
                    </button>
                </div>

                {/* Balance / referral card — the icon flips between the two faces. */}
                <div
                    className="overflow-hidden rounded-3xl transition-transform duration-300"
                    style={{
                        background: isCardFlipped
                            ? `linear-gradient(135deg, #000000 0%, ${primaryColor} 100%)`
                            : `linear-gradient(135deg, ${primaryColor} 0%, #000000 100%)`
                    }}
                >
                    <div className="px-6 py-8 md:px-8">
                        <div className="mb-8 flex items-center justify-between gap-3">
                            <div className="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full border border-white/30 bg-white/15 text-lg font-bold text-white">
                                {initials}
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-lg font-bold capitalize text-white">{displayName}</div>
                                <div className="truncate text-xs text-gray-200 opacity-90">
                                    Agent ID: {identity.username || 'N/A'}
                                </div>
                                {isCardFlipped && (
                                    <div className="truncate text-xs text-gray-200 opacity-90">
                                        {identity.email || 'N/A'}
                                    </div>
                                )}
                            </div>
                            <button
                                onClick={() => setIsCardFlipped(prev => !prev)}
                                className="flex-shrink-0 rounded-full p-2 text-white transition hover:bg-white/10"
                                title={isCardFlipped ? 'Show balances' : 'Show referrals'}
                            >
                                <RefreshCcw size={20} />
                            </button>
                        </div>

                        <div className="grid grid-cols-2 gap-x-4 gap-y-5">
                            {(isCardFlipped ? funnelTiles : balanceTiles).map((tile, index) => (
                                <div key={tile.label} className={index % 2 === 1 ? 'text-right' : 'text-left'}>
                                    <div className="mb-1 truncate text-xs text-gray-200">{tile.label}</div>
                                    <div className="truncate text-2xl font-bold text-white md:text-3xl">
                                        {isCardFlipped ? tile.value : formatCurrency(tile.value)}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Achievements — one card at a time, swipe between Weekly and Monthly */}
                <div
                    className="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-slate-100"
                    onTouchStart={onSwipeStart}
                    onTouchMove={onSwipeMove}
                    onTouchEnd={onSwipeEnd}
                    onMouseDown={onSwipeStart}
                    onMouseMove={onSwipeMove}
                    onMouseUp={onSwipeEnd}
                    onMouseLeave={onSwipeEnd}
                >
                    {/* The track holds both cards side by side and slides; only the
                        active one is ever within the frame above. */}
                    <div
                        className="flex"
                        style={{
                            width: `${tiers.length * 100}%`,
                            transform: `translateX(calc(${-activeTier * (100 / tiers.length)}% + ${swipeOffset}px))`,
                            transition: isSwiping ? 'none' : 'transform 350ms cubic-bezier(0.22, 1, 0.36, 1)',
                        }}
                    >
                        {tiers.map((tier, index) => {
                            const progress = tierProgress(tier);
                            const percent = Math.round(progress.ratio * 100);

                            return (
                                <div
                                    key={tier.key}
                                    // shrink-0 keeps every page exactly one frame wide.
                                    // Without it a page whose content is wider than the
                                    // frame would be squeezed, and the slide would stop
                                    // landing squarely on each card.
                                    className="flex shrink-0 flex-col items-center p-6 select-none"
                                    style={{ width: `${100 / tiers.length}%` }}
                                    aria-hidden={index !== activeTier}
                                >
                                    <h2 className="text-center text-lg font-bold text-slate-800">{tier.label}</h2>
                                    <p className="mt-1 max-w-md text-center text-sm text-slate-500">
                                        Onboard {tier.target} referrals {cycleNoun(tier, progress.anchored)} to earn {formatCurrency(tier.reward)}.
                                    </p>

                                    {/* Time left in this period. Each tier counts down on its
                                        own clock, and the count returns to zero when it ends. */}
                                    {countdowns[tier.key] && (
                                        <div className="mb-6 mt-3 flex items-center gap-2 rounded-full bg-slate-50 px-3.5 py-1.5 ring-1 ring-slate-100">
                                            <Timer size={14} className="shrink-0 text-slate-400" />
                                            <span className="text-xs text-slate-500">Resets in</span>
                                            <span className="font-mono text-xs font-bold tabular-nums text-slate-700">
                                                {countdowns[tier.key]}
                                            </span>
                                        </div>
                                    )}
                                    {!countdowns[tier.key] && <div className="mb-6" />}

                                    <AchievementGauge value={progress.onboarded} target={tier.target} color={primaryColor} />

                                    {/* Progress bar with the exact figures beside it */}
                                    <div className="mt-6 w-full">
                                        <div className="mb-1.5 flex items-baseline justify-between text-sm">
                                            <span className="font-semibold text-slate-700">
                                                {progress.onboarded} of {tier.target} onboards
                                            </span>
                                            <span className="font-bold" style={{ color: primaryColor }}>{percent}%</span>
                                        </div>
                                        <div className="h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                className="h-full rounded-full transition-all duration-700 ease-out"
                                                style={{ width: `${percent}%`, backgroundColor: primaryColor }}
                                            />
                                        </div>
                                        <div className="mt-1.5 flex items-baseline justify-between text-xs text-slate-500">
                                            <span>
                                                {progress.reached
                                                    ? 'Target reached'
                                                    : `${tier.target - progress.onboarded} more to go`}
                                            </span>
                                            <span className="font-semibold text-slate-600">Reward {formatCurrency(tier.reward)}</span>
                                        </div>
                                    </div>

                                    {progress.claimable && (
                                        <button
                                            onClick={() => handleClaimReward(tier)}
                                            disabled={isClaiming}
                                            className="mt-4 flex w-full items-center justify-center rounded-xl py-3.5 text-base font-bold text-white transition hover:opacity-90 disabled:opacity-60"
                                            style={{ backgroundColor: primaryColor }}
                                        >
                                            {isClaiming
                                                ? <Loader2 size={20} className="animate-spin" />
                                                : `Get Reward (${formatCurrency(tier.reward)})`}
                                        </button>
                                    )}

                                    {progress.claimed && (
                                        <p className="mt-4 w-full rounded-xl bg-emerald-50 py-3 text-center text-sm font-semibold text-emerald-700">
                                            Claimed {cycleNoun(tier, progress.anchored)}
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {/* Which card is showing, and a way to move between them without swiping */}
                    <div className="flex items-center justify-center gap-2 pb-5">
                        {tiers.map((tier, index) => (
                            <button
                                key={tier.key}
                                onClick={() => setActiveTier(index)}
                                aria-label={`Show ${tier.label}`}
                                aria-current={index === activeTier}
                                className="h-2 rounded-full transition-all"
                                style={{
                                    width: index === activeTier ? 22 : 8,
                                    backgroundColor: index === activeTier ? primaryColor : '#cbd5e1',
                                }}
                            />
                        ))}
                    </div>
                </div>

                {/* Total balance + application entry point */}
                <div
                    className="flex flex-col gap-6 overflow-hidden rounded-3xl px-6 py-7 md:flex-row md:items-center md:justify-between md:px-8"
                    style={{ background: `linear-gradient(135deg, ${primaryColor} 0%, #000000 100%)` }}
                >
                    <div className="min-w-0">
                        <div className="mb-2 text-sm font-semibold text-gray-200">Total Balance</div>
                        <div className="truncate text-4xl font-bold text-white md:text-5xl">
                            {formatCurrency(totalBalance)}
                        </div>
                    </div>
                    <button
                        onClick={() => onNavigate?.('agent-application')}
                        className="flex-shrink-0 rounded-xl border-2 border-white px-4 py-2.5 text-sm font-bold text-white transition hover:bg-white/10"
                    >
                        Application Form
                    </button>
                </div>

                {/* Cashout history */}
                <div className="space-y-4">
                    <h2 className="text-lg font-bold text-slate-900">Cashout History</h2>
                    <div className="rounded-2xl bg-white px-5 shadow-sm ring-1 ring-slate-100">
                        {latestCashouts.length > 0 ? (
                            latestCashouts.map(cashout => (
                                <div
                                    key={cashout.id}
                                    className="flex items-center justify-between gap-4 border-b border-slate-100 py-4 last:border-0"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-bold text-slate-800">
                                            Ref: {cashout.ref_number || `#${cashout.id}`}
                                        </div>
                                        <div className="mt-0.5 text-xs text-slate-500">{formatDate(cashout.created_at)}</div>
                                    </div>
                                    <div className="flex-shrink-0 text-right">
                                        <div className="text-sm font-extrabold text-slate-800">
                                            {formatCurrency(Number(cashout.total_amount ?? 0))}
                                        </div>
                                        <div className="mt-0.5 text-[10px] font-extrabold text-green-600">POSTED</div>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="py-10 text-center text-sm text-gray-500">No cashouts found</div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default DashboardAgent;
