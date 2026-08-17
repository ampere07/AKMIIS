import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle, CheckCircle2, CreditCard, Loader2, RefreshCw, Search, Send,
  ShieldAlert, Wallet, X, XCircle,
} from 'lucide-react';
import {
  xenditReconcileService,
  type XenditAuditList,
  type XenditFilter,
  type XenditReconcileRow,
} from '../services/xenditReconcileService';

interface XenditReconcileToolProps {
  isDarkMode?: boolean;
}

/**
 * The four views, ordered so the ones that owe a customer something come first.
 *
 * "Confirmed Paid (Unposted)" is the tab this screen exists for: Xendit has taken
 * the money and billing has not applied it, which is a customer sitting
 * disconnected after paying.
 */
const FILTERS: Array<{ id: XenditFilter; label: string }> = [
  { id: 'all', label: 'All' },
  { id: 'pending', label: 'Pending Verification' },
  { id: 'unposted', label: 'Confirmed Paid (Unposted)' },
  { id: 'expired', label: 'Expired / Failed' },
];

const PER_PAGE = 50;

/** Selectable lookback windows, in days. */
const WINDOWS = [7, 30, 60, 90];

/** Billing-pipeline status colouring. */
const BILLING_TONES: Record<string, string> = {
  PAID: 'bg-emerald-500/15 text-emerald-500 border-emerald-500/30',
  QUEUED: 'bg-blue-500/15 text-blue-400 border-blue-500/30',
  PROCESSING: 'bg-blue-500/15 text-blue-400 border-blue-500/30',
  API_RETRY: 'bg-amber-500/15 text-amber-500 border-amber-500/30',
  PENDING: 'bg-gray-500/15 text-gray-400 border-gray-500/30',
  EXPIRED: 'bg-gray-500/15 text-gray-400 border-gray-500/30',
  FAILED: 'bg-red-500/15 text-red-500 border-red-500/30',
};

const peso = (value: number): string =>
  '₱' + value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const stamp = (value: string | null): string => (value ? new Date(value).toLocaleString() : '—');

const XenditReconcileTool: React.FC<XenditReconcileToolProps> = ({ isDarkMode: isDarkModeProp }) => {
  const [isDarkMode, setIsDarkMode] = useState<boolean>(() => {
    if (typeof isDarkModeProp === 'boolean') return isDarkModeProp;
    const theme = localStorage.getItem('theme');
    return theme === 'dark' || theme === null;
  });

  const [data, setData] = useState<XenditAuditList | null>(null);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null);

  const [filter, setFilter] = useState<XenditFilter>('unposted');
  const [search, setSearch] = useState('');
  const [days, setDays] = useState(30);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Set<number>>(new Set());

  const [postTarget, setPostTarget] = useState<XenditReconcileRow | null>(null);
  const [expireTarget, setExpireTarget] = useState<XenditReconcileRow | null>(null);
  const [expireReason, setExpireReason] = useState('');

  useEffect(() => {
    if (typeof isDarkModeProp === 'boolean') {
      setIsDarkMode(isDarkModeProp);
      return;
    }
    const check = () => {
      const theme = localStorage.getItem('theme');
      setIsDarkMode(theme === 'dark' || theme === null);
    };
    check();
    const observer = new MutationObserver(check);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
  }, [isDarkModeProp]);

  // ---- Data --------------------------------------------------------------

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const result = await xenditReconcileService.getAudit({
        filter,
        search: search.trim() || undefined,
        days,
        page,
        per_page: PER_PAGE,
      });
      setData(result);
      setSelected(new Set());
    } catch (error: any) {
      setNotice({ tone: 'error', text: error?.response?.data?.message || 'Could not read the payment worklist.' });
    } finally {
      setLoading(false);
    }
  }, [filter, search, days, page]);

  useEffect(() => { load(); }, [load]);

  // Memoized so the identity is stable: `data?.rows ?? []` would build a fresh array
  // every render and invalidate everything downstream of it.
  const rows = useMemo(() => data?.rows ?? [], [data]);
  const summary = data?.summary;
  const totalPages = Math.max(1, Math.ceil((data?.total ?? 0) / PER_PAGE));

  const selectedRows = useMemo(() => rows.filter((row) => selected.has(row.id)), [rows, selected]);

  // ---- Actions -----------------------------------------------------------

  const runAction = useCallback(
    async (key: string, action: () => Promise<{ success: boolean; skipped: boolean; message: string }>) => {
      setBusy(key);
      try {
        const result = await action();
        setNotice({
          tone: result.success ? (result.skipped ? 'info' : 'success') : 'error',
          text: result.message,
        });
        await load();
      } finally {
        setBusy(null);
      }
    },
    [load]
  );

  const confirmForcePost = useCallback(async () => {
    if (!postTarget) return;
    const target = postTarget;
    setPostTarget(null);
    await runAction(`post:${target.id}`, () => xenditReconcileService.forcePost(target.id));
  }, [postTarget, runAction]);

  const confirmMarkExpired = useCallback(async () => {
    if (!expireTarget) return;
    const target = expireTarget;
    const reason = expireReason.trim();
    setExpireTarget(null);
    setExpireReason('');
    await runAction(`exp:${target.id}`, () => xenditReconcileService.markExpired(target.id, reason || undefined));
  }, [expireTarget, expireReason, runAction]);

  /** Verify every selected row, one call each so a single failure is attributable. */
  const verifySelected = useCallback(async () => {
    if (selectedRows.length === 0) return;
    setBusy('bulk:verify');
    try {
      let confirmed = 0;
      let unchanged = 0;
      let failed = 0;

      for (const row of selectedRows) {
        const result = await xenditReconcileService.verify(row.id);
        if (!result.success) failed++;
        else if (result.outcome === 'queued') confirmed++;
        else unchanged++;
      }

      setNotice({
        tone: failed > 0 ? 'error' : 'success',
        text: `Verified ${selectedRows.length} payment(s): ${confirmed} confirmed and queued, ${unchanged} unchanged, ${failed} failed.`,
      });
      await load();
    } finally {
      setBusy(null);
    }
  }, [selectedRows, load]);

  // ---- Theme tokens ------------------------------------------------------

  const card = isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200';
  const text = isDarkMode ? 'text-gray-100' : 'text-gray-900';
  const muted = isDarkMode ? 'text-gray-400' : 'text-gray-500';
  const input = isDarkMode
    ? 'bg-gray-950 border-gray-800 text-gray-100 placeholder-gray-600'
    : 'bg-white border-gray-300 text-gray-900 placeholder-gray-400';
  const rowHover = isDarkMode ? 'hover:bg-gray-800/60' : 'hover:bg-gray-50';
  const headRow = isDarkMode ? 'bg-gray-950/60 text-gray-400' : 'bg-gray-50 text-gray-600';

  const statCards: Array<{ label: string; value: number; tone: string; filter: XenditFilter; hint: string }> = summary
    ? [
        {
          label: 'Unreconciled (Pending)',
          value: summary.unreconciled,
          tone: 'text-amber-500',
          filter: 'pending',
          hint: 'Created at Xendit, no verdict yet. The cron re-checks these on a widening backoff.',
        },
        {
          label: 'Confirmed Paid (Unposted)',
          value: summary.unposted,
          tone: 'text-blue-400',
          filter: 'unposted',
          hint: 'Xendit has the money and billing has not applied it. This is the number that means a paying customer is still cut off.',
        },
        {
          label: 'Fully Settled',
          value: summary.settled,
          tone: 'text-emerald-500',
          filter: 'settled',
          hint: 'Posted to billing: balance applied, invoice cleared, receipt issued.',
        },
        {
          label: 'Expired / Failed',
          value: summary.expired,
          tone: 'text-gray-400',
          filter: 'expired',
          hint: 'Abandoned checkouts and payments the gateway rejected.',
        },
        {
          label: 'Missing in DB',
          value: summary.missing_in_db,
          tone: 'text-red-500',
          filter: 'all',
          hint: 'No billing account carries the payment’s account number, so there is nothing to credit. Needs a person.',
        },
      ]
    : [];

  return (
    <div className={`p-4 md:p-6 min-h-full ${isDarkMode ? 'bg-gray-950' : 'bg-gray-50'}`}>
      {/* Header */}
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
        <div className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/25">
            <Wallet className="w-5 h-5 text-white" />
          </div>
          <div>
            <h1 className={`text-xl font-bold ${text}`}>Xendit Payment Reconciliation</h1>
            <p className={`text-sm ${muted}`}>
              Verify unposted, pending and missed-webhook transactions against the live Xendit API, and settle what the
              gateway has confirmed.
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* Window filter. A payment older than the window is invisible here, so the
              choice is explicit rather than a free-text box nobody sets correctly. */}
          <div className={`flex items-center rounded-lg border overflow-hidden ${card}`}>
            {WINDOWS.map((option) => (
              <button
                key={option}
                onClick={() => { setDays(option); setPage(1); }}
                className={`px-3 py-2 text-xs font-medium transition-colors ${
                  days === option
                    ? 'bg-emerald-600 text-white'
                    : `${text} hover:bg-emerald-500/10`
                }`}
              >
                {option}d
              </button>
            ))}
          </div>

          <button
            onClick={load}
            disabled={loading}
            className="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50"
          >
            {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
            Refresh
          </button>
        </div>
      </div>

      {/* Notice */}
      {notice && (
        <div
          className={`mb-4 px-4 py-3 rounded-lg border text-sm flex items-start justify-between gap-3 ${
            notice.tone === 'success'
              ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-500'
              : notice.tone === 'error'
              ? 'bg-red-500/10 border-red-500/30 text-red-500'
              : 'bg-blue-500/10 border-blue-500/30 text-blue-500'
          }`}
        >
          <span className="flex-1">{notice.text}</span>
          <button onClick={() => setNotice(null)} className="shrink-0 opacity-70 hover:opacity-100">
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Stat cards */}
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
        {statCards.map((stat) => (
          <button
            key={stat.label}
            onClick={() => { setFilter(stat.filter); setPage(1); }}
            title={stat.hint}
            className={`rounded-xl border p-3 text-left transition-colors hover:border-emerald-500/50 ${card}`}
          >
            <div className={`text-xs font-medium ${muted}`}>{stat.label}</div>
            <div className={`text-2xl font-bold mt-1 ${stat.tone}`}>{stat.value}</div>
          </button>
        ))}
      </div>

      {/* Filters + search */}
      <div className={`rounded-xl border p-3 mb-4 ${card}`}>
        <div className="flex flex-wrap items-center gap-2 mb-3">
          {FILTERS.map((tab) => (
            <button
              key={tab.id}
              onClick={() => { setFilter(tab.id); setPage(1); }}
              className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
                filter === tab.id
                  ? 'bg-emerald-600 border-emerald-600 text-white'
                  : isDarkMode
                  ? 'bg-gray-950 border-gray-800 text-gray-300 hover:border-emerald-500/50'
                  : 'bg-gray-50 border-gray-200 text-gray-700 hover:border-emerald-500/50'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <div className="relative">
          <Search className={`w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 ${muted}`} />
          <input
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            placeholder="Search by reference, invoice id, account number or subscriber name…"
            className={`w-full pl-9 pr-3 py-2 rounded-lg border text-sm ${input}`}
          />
        </div>
      </div>

      {/* Bulk bar */}
      {selectedRows.length > 0 && (
        <div className="rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-3 mb-4 flex flex-wrap items-center gap-2">
          <span className={`text-sm font-medium ${text}`}>{selectedRows.length} selected</span>
          <div className="flex-1" />
          <button
            onClick={verifySelected}
            disabled={busy !== null}
            className={`px-3 py-1.5 rounded-lg border text-xs font-medium flex items-center gap-1.5 disabled:opacity-50 ${card} ${text}`}
          >
            {busy === 'bulk:verify' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />}
            Verify with Xendit
          </button>
          <button
            onClick={() => setSelected(new Set())}
            className={`px-3 py-1.5 rounded-lg border text-xs font-medium ${card} ${muted}`}
          >
            Clear
          </button>
        </div>
      )}

      {/* Table */}
      <div className={`rounded-xl border overflow-hidden ${card}`}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className={`text-xs uppercase tracking-wide ${headRow}`}>
              <tr>
                <th className="px-3 py-2.5 w-10">
                  <input
                    type="checkbox"
                    checked={rows.length > 0 && rows.every((r) => selected.has(r.id))}
                    onChange={(e) => {
                      const next = new Set(selected);
                      rows.forEach((r) => { e.target.checked ? next.add(r.id) : next.delete(r.id); });
                      setSelected(next);
                    }}
                    className="rounded"
                  />
                </th>
                <th className="px-3 py-2.5 text-left font-semibold">Reference / Invoice ID</th>
                <th className="px-3 py-2.5 text-left font-semibold">Account No</th>
                <th className="px-3 py-2.5 text-left font-semibold">Subscriber Name</th>
                <th className="px-3 py-2.5 text-right font-semibold">Amount</th>
                <th className="px-3 py-2.5 text-left font-semibold">Channel</th>
                <th className="px-3 py-2.5 text-left font-semibold">Xendit Status</th>
                <th className="px-3 py-2.5 text-left font-semibold">Billing Status</th>
                <th className="px-3 py-2.5 text-left font-semibold">Settled At</th>
                <th className="px-3 py-2.5 text-right font-semibold">Actions</th>
              </tr>
            </thead>
            <tbody className={isDarkMode ? 'divide-y divide-gray-800' : 'divide-y divide-gray-100'}>
              {loading && (
                <tr>
                  <td colSpan={10} className={`px-4 py-12 text-center ${muted}`}>
                    <Loader2 className="w-6 h-6 animate-spin mx-auto" />
                  </td>
                </tr>
              )}

              {!loading && rows.length === 0 && (
                <tr>
                  <td colSpan={10} className={`px-4 py-12 text-center ${muted}`}>
                    No payment matches this filter in the last {days} days.
                  </td>
                </tr>
              )}

              {!loading && rows.map((row) => (
                <tr key={row.id} className={rowHover}>
                  <td className="px-3 py-2.5">
                    <input
                      type="checkbox"
                      checked={selected.has(row.id)}
                      onChange={(e) => {
                        const next = new Set(selected);
                        e.target.checked ? next.add(row.id) : next.delete(row.id);
                        setSelected(next);
                      }}
                      className="rounded"
                    />
                  </td>

                  <td className="px-3 py-2.5 text-xs">
                    <div className={`font-mono font-medium ${text}`}>{row.reference_no}</div>
                    <div className={`font-mono opacity-70 ${muted}`}>{row.invoice_id || '—'}</div>
                  </td>

                  <td className={`px-3 py-2.5 text-xs font-mono ${text}`}>
                    {row.account_no}
                    {!row.account_exists && (
                      <span
                        className="ml-1.5 text-[10px] px-1.5 py-0.5 rounded border font-medium bg-red-500/15 text-red-500 border-red-500/30"
                        title="No billing account carries this account number, so this payment cannot be posted."
                      >
                        no account
                      </span>
                    )}
                  </td>

                  <td className={`px-3 py-2.5 text-xs ${row.subscriber_name ? text : muted}`}>
                    {row.subscriber_name ?? '—'}
                  </td>

                  <td className={`px-3 py-2.5 text-xs text-right font-mono font-medium ${text}`}>
                    {peso(row.amount)}
                  </td>

                  <td className={`px-3 py-2.5 text-xs ${muted}`}>{row.channel}</td>

                  <td className="px-3 py-2.5 text-xs">
                    {row.xendit_status
                      ? <span className={text}>{row.xendit_status}</span>
                      : <span className={muted} title="The gateway has not reported on this payment yet.">not reported</span>}
                  </td>

                  <td className="px-3 py-2.5">
                    <span
                      className={`text-[11px] px-2 py-0.5 rounded border font-medium ${
                        BILLING_TONES[row.billing_status] ?? 'bg-gray-500/15 text-gray-400 border-gray-500/30'
                      }`}
                    >
                      {row.billing_status}
                    </span>
                  </td>

                  <td className={`px-3 py-2.5 text-xs ${muted}`}>{stamp(row.settled_at)}</td>

                  <td className="px-3 py-2.5">
                    <div className="flex items-center justify-end gap-1 flex-wrap">
                      <button
                        onClick={() => runAction(`ver:${row.id}`, () => xenditReconcileService.verify(row.id))}
                        disabled={busy !== null || !row.invoice_id}
                        title={row.invoice_id
                          ? 'Ask Xendit for this payment’s current status'
                          : 'This payment carries no gateway id, so there is nothing to look up'}
                        className="px-2 py-1 rounded text-[11px] font-medium bg-blue-500/15 text-blue-400 border border-blue-500/30 hover:bg-blue-500/25 disabled:opacity-40"
                      >
                        {busy === `ver:${row.id}` ? <Loader2 className="w-3 h-3 animate-spin" /> : 'Verify with Xendit'}
                      </button>

                      {row.can_force_post && (
                        <button
                          onClick={() => setPostTarget(row)}
                          disabled={busy !== null}
                          title="Apply the balance, clear the invoice and issue the receipt now"
                          className="px-2 py-1 rounded text-[11px] font-medium bg-emerald-500/15 text-emerald-500 border border-emerald-500/30 hover:bg-emerald-500/25 disabled:opacity-40"
                        >
                          {busy === `post:${row.id}` ? <Loader2 className="w-3 h-3 animate-spin" /> : 'Force Post Payment'}
                        </button>
                      )}

                      {row.can_mark_expired && (
                        <button
                          onClick={() => { setExpireTarget(row); setExpireReason(''); }}
                          disabled={busy !== null}
                          title="Write off this abandoned checkout"
                          className="px-2 py-1 rounded text-[11px] font-medium bg-gray-500/15 text-gray-400 border border-gray-500/30 hover:bg-gray-500/25 disabled:opacity-40"
                        >
                          {busy === `exp:${row.id}` ? <Loader2 className="w-3 h-3 animate-spin" /> : 'Mark Expired'}
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {!loading && (data?.total ?? 0) > PER_PAGE && (
          <div className={`flex items-center justify-between px-4 py-3 border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-100'}`}>
            <span className={`text-xs ${muted}`}>
              Showing {(page - 1) * PER_PAGE + 1}–{Math.min(page * PER_PAGE, data?.total ?? 0)} of {data?.total ?? 0}
            </span>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page === 1}
                className={`px-3 py-1 rounded border text-xs disabled:opacity-40 ${card} ${text}`}
              >
                Previous
              </button>
              <span className={`text-xs ${muted}`}>Page {page} of {totalPages}</span>
              <button
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page >= totalPages}
                className={`px-3 py-1 rounded border text-xs disabled:opacity-40 ${card} ${text}`}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Force-post confirmation */}
      {postTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className={`w-full max-w-md rounded-xl border p-5 ${card}`}>
            <h3 className={`text-base font-bold mb-2 flex items-center gap-2 ${text}`}>
              <Send className="w-4 h-4 text-emerald-500" /> Post this payment to billing?
            </h3>

            <p className={`text-sm mb-3 ${muted}`}>
              This applies the payment to the account balance, settles the open invoices it covers, issues the receipt,
              and reconnects the subscriber if the balance clears. It runs through the payment worker&rsquo;s own claim,
              so posting the same payment twice is not possible.
            </p>

            <div className={`rounded-lg border p-3 mb-4 text-xs space-y-1 ${isDarkMode ? 'bg-gray-950 border-gray-800' : 'bg-gray-50 border-gray-200'}`}>
              <div className="flex justify-between gap-3">
                <span className={muted}>Reference</span>
                <span className={`font-mono ${text}`}>{postTarget.reference_no}</span>
              </div>
              <div className="flex justify-between gap-3">
                <span className={muted}>Account</span>
                <span className={`font-mono ${text}`}>{postTarget.account_no}</span>
              </div>
              <div className="flex justify-between gap-3">
                <span className={muted}>Subscriber</span>
                <span className={text}>{postTarget.subscriber_name ?? '—'}</span>
              </div>
              <div className="flex justify-between gap-3">
                <span className={muted}>Amount</span>
                <span className={`font-mono font-semibold ${text}`}>{peso(postTarget.amount)}</span>
              </div>
              <div className="flex justify-between gap-3">
                <span className={muted}>Xendit says</span>
                <span className="text-emerald-500 font-medium">{postTarget.xendit_status}</span>
              </div>
            </div>

            {!postTarget.account_exists && (
              <div className="mb-4 px-3 py-2 rounded-lg border border-red-500/30 bg-red-500/10 text-xs text-red-500 flex items-start gap-2">
                <ShieldAlert className="w-3.5 h-3.5 shrink-0 mt-0.5" />
                No billing account carries this account number. The post will be refused — there is nothing to credit.
              </div>
            )}

            <div className="flex items-center justify-end gap-2">
              <button onClick={() => setPostTarget(null)} className={`px-4 py-2 rounded-lg border text-sm ${card} ${text}`}>
                Cancel
              </button>
              <button
                onClick={confirmForcePost}
                disabled={busy !== null}
                className="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium disabled:opacity-50 flex items-center gap-2"
              >
                <CheckCircle2 className="w-4 h-4" /> Post payment
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Mark-expired confirmation */}
      {expireTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className={`w-full max-w-md rounded-xl border p-5 ${card}`}>
            <h3 className={`text-base font-bold mb-2 flex items-center gap-2 ${text}`}>
              <XCircle className="w-4 h-4 text-gray-400" /> Mark this checkout expired?
            </h3>

            <p className={`text-sm mb-3 ${muted}`}>
              Records {expireTarget.reference_no} ({peso(expireTarget.amount)}) as an abandoned checkout and stops the
              reconciliation cron re-checking it. Refused automatically if Xendit has in fact confirmed payment — verify
              first if you are unsure.
            </p>

            <div className="mb-4 px-3 py-2 rounded-lg border border-amber-500/30 bg-amber-500/10 text-xs text-amber-500 flex items-start gap-2">
              <AlertTriangle className="w-3.5 h-3.5 shrink-0 mt-0.5" />
              This does not tell Xendit anything. It only records our side.
            </div>

            <label className={`block text-xs mb-1 ${muted}`}>Reason (optional, recorded in the log)</label>
            <input
              value={expireReason}
              onChange={(e) => setExpireReason(e.target.value)}
              maxLength={255}
              placeholder="e.g. customer paid over the counter instead"
              className={`w-full px-3 py-2 rounded-lg border text-sm mb-4 ${input}`}
            />

            <div className="flex items-center justify-end gap-2">
              <button
                onClick={() => { setExpireTarget(null); setExpireReason(''); }}
                className={`px-4 py-2 rounded-lg border text-sm ${card} ${text}`}
              >
                Cancel
              </button>
              <button
                onClick={confirmMarkExpired}
                disabled={busy !== null}
                className="px-4 py-2 rounded-lg bg-gray-600 hover:bg-gray-500 text-white text-sm font-medium disabled:opacity-50 flex items-center gap-2"
              >
                <CreditCard className="w-4 h-4" /> Mark expired
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default XenditReconcileTool;
