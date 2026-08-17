import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Activity, AlertTriangle, Download, Gauge, History, Loader2, Network, PauseCircle,
  RefreshCw, Router, Search, Signal, Tag, Trash2, Undo2, UserCog, X, XCircle
} from 'lucide-react';
import {
  smartOltReconciliationService,
  DELETE_CONFIRMATION,
  type AlignmentPreview,
  type CleanupPreview,
  type JobType,
  type MacAlignmentPreview,
  type MacAlignState,
  type OnuRow,
  type ProfilePreview,
  type SignalBand,
  type SmartOltLog,
  type SmartOltState,
  type ToolJob,
} from '../services/smartOltReconciliationService';

interface SmartOltToolProps {
  isDarkMode?: boolean;
}

type TabId = 'inventory' | 'mac_alignment' | 'alignment' | 'profile' | 'cleanup' | 'logs';

const TABS: Array<{ id: TabId; label: string; icon: React.ElementType }> = [
  { id: 'inventory', label: 'ONU Inventory & Signal', icon: Signal },
  { id: 'mac_alignment', label: 'MAC Alignment', icon: Router },
  { id: 'alignment', label: 'Name Alignment', icon: Tag },
  { id: 'profile', label: 'Profile Sync', icon: UserCog },
  { id: 'cleanup', label: 'Inactive ONU Cleanup', icon: Trash2 },
  { id: 'logs', label: 'Operation Logs & Undo', icon: History },
];

/** How each MAC-alignment verdict is badged in the STATE column. */
const MAC_STATE_BADGES: Record<MacAlignState, { label: string; classes: string }> = {
  rename_needed: { label: 'Rename', classes: 'bg-amber-500/15 text-amber-500 border-amber-500/30' },
  aligned: { label: 'Aligned', classes: 'bg-emerald-500/15 text-emerald-500 border-emerald-500/30' },
  unmatched: { label: 'Unmatched', classes: 'bg-purple-500/15 text-purple-500 border-purple-500/30' },
  no_mac: { label: 'No MAC', classes: 'bg-gray-500/15 text-gray-400 border-gray-500/30' },
};

const SIGNAL_STYLES: Record<SignalBand, { label: string; classes: string }> = {
  optimal: { label: 'Optimal', classes: 'bg-emerald-500/15 text-emerald-500 border-emerald-500/30' },
  warning: { label: 'Warning', classes: 'bg-amber-500/15 text-amber-500 border-amber-500/30' },
  critical: { label: 'Critical', classes: 'bg-red-500/15 text-red-500 border-red-500/30' },
  offline: { label: 'Offline', classes: 'bg-gray-500/15 text-gray-400 border-gray-500/30' },
};

const PAGE_SIZE = 100;

/** How often a running job is advanced. Each tick does a bounded slice of work server-side. */
const JOB_TICK_MS = 700;

/**
 * How often a rate-limit-paused job is re-offered to the server.
 *
 * The server refuses until its own cooldown elapses and answers `skipped`, so this
 * poll is cheap; it exists so the sweep resumes on its own rather than waiting for
 * the operator to come back and press something.
 */
const PAUSED_TICK_MS = 60_000;

const SmartOltTool: React.FC<SmartOltToolProps> = ({ isDarkMode: isDarkModeProp }) => {
  const [isDarkMode, setIsDarkMode] = useState<boolean>(() => {
    if (typeof isDarkModeProp === 'boolean') return isDarkModeProp;
    const theme = localStorage.getItem('theme');
    return theme === 'dark' || theme === null;
  });

  const [tab, setTab] = useState<TabId>('inventory');
  const [state, setState] = useState<SmartOltState | null>(null);
  const [macAlignment, setMacAlignment] = useState<MacAlignmentPreview | null>(null);
  const [alignment, setAlignment] = useState<AlignmentPreview | null>(null);
  const [profile, setProfile] = useState<ProfilePreview | null>(null);
  const [cleanup, setCleanup] = useState<CleanupPreview | null>(null);
  const [logs, setLogs] = useState<SmartOltLog[]>([]);

  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [offlineDays, setOfflineDays] = useState(30);

  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [job, setJob] = useState<ToolJob | null>(null);
  const [jobLog, setJobLog] = useState<string[]>([]);
  const [jobPaused, setJobPaused] = useState(false);

  const [deleteConfirm, setDeleteConfirm] = useState('');
  const [deleteModalOpen, setDeleteModalOpen] = useState(false);
  const [undoTarget, setUndoTarget] = useState<SmartOltLog | null>(null);

  const jobTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      if (jobTimer.current) clearTimeout(jobTimer.current);
    };
  }, []);

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

  // ---- Loaders -----------------------------------------------------------

  const loadState = useCallback(async (includeRows = true) => {
    setLoading(true);
    try {
      const result = await smartOltReconciliationService.getState(includeRows);
      if (!mounted.current) return;
      setState(result);
      // A paused job is adopted too: it is a rate-limit stop with a checkpoint, and
      // reopening the page is exactly when the operator needs to see it resume.
      if (result.active_job && (result.active_job.status === 'running' || result.active_job.status === 'paused')) {
        setJob(result.active_job);
      }
      if (!result.configured) {
        setNotice({ tone: 'error', text: 'SmartOLT is not configured. Set the sub-domain and token in Configurations → SmartOLT Config.' });
      }
    } catch (error: any) {
      if (mounted.current) setNotice({ tone: 'error', text: error?.response?.data?.message || 'Could not read the SmartOLT state.' });
    } finally {
      if (mounted.current) setLoading(false);
    }
  }, []);

  const loadTabData = useCallback(async (target: TabId) => {
    setLoading(true);
    try {
      if (target === 'mac_alignment') setMacAlignment(await smartOltReconciliationService.getMacAlignment());
      if (target === 'alignment') setAlignment(await smartOltReconciliationService.getAlignmentPreview());
      if (target === 'profile') setProfile(await smartOltReconciliationService.getProfilePreview());
      if (target === 'cleanup') setCleanup(await smartOltReconciliationService.getCleanupPreview(offlineDays));
      if (target === 'logs') setLogs(await smartOltReconciliationService.getLogs(100));
    } catch (error: any) {
      setNotice({ tone: 'error', text: error?.response?.data?.message || 'Could not load this view.' });
    } finally {
      if (mounted.current) setLoading(false);
    }
  }, [offlineDays]);

  useEffect(() => { loadState(true); }, [loadState]);
  useEffect(() => {
    setSelected(new Set());
    setPage(1);
    if (tab !== 'inventory') loadTabData(tab);
  }, [tab, loadTabData]);

  // ---- Job engine --------------------------------------------------------

  const appendJobLog = useCallback((line: string) => {
    setJobLog((prev) => [...prev.slice(-200), `${new Date().toLocaleTimeString()}  ${line}`]);
  }, []);

  /**
   * Advance the running job one slice at a time.
   *
   * The backend caps how much work a single call does, so this loop is what carries
   * a multi-thousand-ONU sweep to completion without any one request timing out.
   */
  const tickJob = useCallback(
    async (jobId: number) => {
      const result = await smartOltReconciliationService.processJob(jobId);
      if (!mounted.current) return;

      if (result.job) {
        setJob(result.job);

        if (result.job.status === 'running') {
          appendJobLog(result.job.message);
          jobTimer.current = setTimeout(() => tickJob(jobId), JOB_TICK_MS);
          return;
        }

        // SmartOLT refused on quota. The job kept its checkpoint, so keep offering
        // it back on a slow poll — the server resumes it the moment its cooldown
        // elapses and nothing has to be restarted.
        if (result.job.status === 'paused') {
          if (!result.skipped) appendJobLog(result.job.message);
          jobTimer.current = setTimeout(() => tickJob(jobId), PAUSED_TICK_MS);
          return;
        }

        appendJobLog(result.job.message);
        appendJobLog(`Job ${result.job.status}: ${result.job.message}`);
        setNotice({
          tone: result.job.status === 'completed' ? 'success' : result.job.status === 'aborted' ? 'info' : 'error',
          text: result.job.message,
        });

        // Refresh whatever the finished job just changed.
        await loadState(true);
        if (tab !== 'inventory') await loadTabData(tab);
      } else {
        appendJobLog(result.message);
        setNotice({ tone: 'error', text: result.message });
        setJob(null);
      }
    },
    [appendJobLog, loadState, loadTabData, tab]
  );

  const startJob = useCallback(
    async (type: JobType, options: Record<string, any> = {}) => {
      setJobLog([]);
      setJobPaused(false);
      const result = await smartOltReconciliationService.startJob(type, options);

      if (!result.success || !result.job) {
        setNotice({ tone: 'error', text: result.message });
        return;
      }

      setJob(result.job);
      appendJobLog(`Started ${type}.`);
      jobTimer.current = setTimeout(() => tickJob(result.job!.id), JOB_TICK_MS);
    },
    [appendJobLog, tickJob]
  );

  const pauseJob = useCallback(() => {
    if (jobTimer.current) clearTimeout(jobTimer.current);
    jobTimer.current = null;
    setJobPaused(true);
    appendJobLog('Paused — the job is holding at its current step.');
  }, [appendJobLog]);

  const resumeJob = useCallback(() => {
    if (!job) return;
    if (jobTimer.current) clearTimeout(jobTimer.current);
    setJobPaused(false);
    appendJobLog('Resumed.');
    // Immediate retry rather than waiting out the slow poll. If the server-side
    // cooldown has not elapsed it simply answers `skipped` and the poll resumes.
    jobTimer.current = setTimeout(() => tickJob(job.id), 0);
  }, [job, appendJobLog, tickJob]);

  const cancelJob = useCallback(async () => {
    if (!job) return;
    if (jobTimer.current) clearTimeout(jobTimer.current);
    jobTimer.current = null;
    const result = await smartOltReconciliationService.abortJob(job.id);
    setJob(result.job);
    appendJobLog(result.message);
    await loadState(true);
  }, [job, appendJobLog, loadState]);

  // ---- Derived -----------------------------------------------------------

  const filterRows = <T extends Record<string, any>>(rows: T[], fields: string[]): T[] => {
    const needle = search.trim().toLowerCase();
    if (needle === '') return rows;
    return rows.filter((row) => fields.some((field) => String(row[field] ?? '').toLowerCase().includes(needle)));
  };

  const inventoryRows = useMemo(
    () => filterRows(state?.rows ?? [], ['external_id', 'sn', 'name', 'zone_name', 'olt_name', 'status']),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [state?.rows, search]
  );

  const macAlignmentRows = useMemo(
    () => filterRows(macAlignment?.rows ?? [], [
      'external_id', 'sn', 'radius_username', 'calling_station_id', 'current_name', 'target_name', 'status',
    ]),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [macAlignment?.rows, search]
  );

  const alignmentRows = useMemo(
    () => filterRows(alignment?.rows ?? [], ['external_id', 'sn', 'current_name', 'proposed_name', 'account_no', 'customer_name']),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [alignment?.rows, search]
  );

  const profileRows = useMemo(
    () => filterRows(profile?.rows ?? [], ['external_id', 'sn', 'name', 'account_no', 'customer_name']),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [profile?.rows, search]
  );

  const cleanupRows = useMemo(
    () => filterRows(cleanup?.rows ?? [], ['external_id', 'sn', 'name', 'zone_name', 'status']),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [cleanup?.rows, search]
  );

  const activeRows: any[] =
    tab === 'inventory' ? inventoryRows
      : tab === 'mac_alignment' ? macAlignmentRows
      : tab === 'alignment' ? alignmentRows
      : tab === 'profile' ? profileRows
      : tab === 'cleanup' ? cleanupRows
      : [];

  const pagedRows = useMemo(() => activeRows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE), [activeRows, page]);
  const totalPages = Math.max(1, Math.ceil(activeRows.length / PAGE_SIZE));

  const toggle = (id: string, checked: boolean) => {
    const next = new Set(selected);
    checked ? next.add(id) : next.delete(id);
    setSelected(next);
  };

  const confirmUndo = useCallback(async () => {
    if (!undoTarget) return;
    const result = await smartOltReconciliationService.undo(undoTarget.log_id);
    setNotice({ tone: result.success ? (result.skipped ? 'info' : 'success') : 'error', text: result.message });
    setUndoTarget(null);
    await loadTabData('logs');
    if (result.success) await loadState(true);
  }, [undoTarget, loadTabData, loadState]);

  // ---- Theme tokens ------------------------------------------------------

  const card = isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200';
  const text = isDarkMode ? 'text-gray-100' : 'text-gray-900';
  const muted = isDarkMode ? 'text-gray-400' : 'text-gray-500';
  const input = isDarkMode
    ? 'bg-gray-950 border-gray-800 text-gray-100 placeholder-gray-600'
    : 'bg-white border-gray-300 text-gray-900 placeholder-gray-400';
  const rowHover = isDarkMode ? 'hover:bg-gray-800/60' : 'hover:bg-gray-50';
  const headRow = isDarkMode ? 'bg-gray-950/60 text-gray-400' : 'bg-gray-50 text-gray-600';

  // A paused job still owns the single active-job slot server-side, so it must keep
  // the start buttons disabled exactly as a running one does.
  const jobRunning = job !== null && (job.status === 'running' || job.status === 'paused');
  const jobRateLimited = job !== null && job.status === 'paused';

  /** The backend export only knows these datasets; MAC alignment has no CSV of its own. */
  const exportDataset: 'inventory' | 'alignment' | 'profile' | 'cleanup' =
    tab === 'alignment' || tab === 'profile' || tab === 'cleanup' ? tab : 'inventory';

  return (
    <div className={`p-4 md:p-6 min-h-full ${isDarkMode ? 'bg-gray-950' : 'bg-gray-50'}`}>
      {/* Header */}
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
        <div className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-lg shadow-cyan-500/25">
            <Network className="w-5 h-5 text-white" />
          </div>
          <div>
            <h1 className={`text-xl font-bold ${text}`}>SmartOLT Tool</h1>
            <p className={`text-sm ${muted}`}>
              ONU inventory, live optical power, name alignment, profile push and safe decommissioning.
              {state?.inventory_synced_at && (
                <> Inventory synced {new Date(state.inventory_synced_at).toLocaleString()}.</>
              )}
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => startJob('smartolt_sync')}
            disabled={jobRunning || !state?.configured}
            className="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50"
          >
            <RefreshCw className="w-4 h-4" /> Sync Inventory
          </button>
          <button
            onClick={() => startJob('radius_scan')}
            disabled={jobRunning || !state?.configured}
            className={`px-3 py-2 rounded-lg border text-sm font-medium flex items-center gap-2 disabled:opacity-50 ${card} ${text}`}
          >
            <Activity className="w-4 h-4" /> Sync Statuses
          </button>
          {/* Background optical-power / MAC crawl. One API call per ONU against a
              hard quota, so it runs as a bounded background job and never inline.
              Default queues only ONUs never read; hold Shift to force a full rescan. */}
          <button
            onClick={(e) => startJob('optical_scan', { rescan: e.shiftKey })}
            disabled={jobRunning || !state?.configured}
            title="Scan optical power and bridge MACs in the background. Shift-click to re-read every ONU."
            className={`px-3 py-2 rounded-lg border text-sm font-medium flex items-center gap-2 disabled:opacity-50 ${card} ${text}`}
          >
            <Gauge className="w-4 h-4" /> Scan Optical Power
          </button>
          <button
            onClick={() => smartOltReconciliationService.exportCsv(exportDataset)}
            disabled={loading}
            className={`px-3 py-2 rounded-lg border text-sm font-medium flex items-center gap-2 disabled:opacity-50 ${card} ${text}`}
          >
            <Download className="w-4 h-4" /> Export CSV
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

      {/* Rate-limit pause banner — the job is parked, not broken, and resumes itself */}
      {jobRateLimited && job && (
        <div className="mb-4 px-4 py-3 rounded-lg border border-amber-500/30 bg-amber-500/10 text-sm text-amber-500">
          <div className="flex items-start gap-2">
            <PauseCircle className="w-4 h-4 shrink-0 mt-0.5" />
            <div className="flex-1">
              <div className="font-semibold">SmartOLT API rate limit reached — the job is paused, not lost.</div>
              <div className="mt-1 opacity-90">
                {job.message}
                {job.context?.resume_at && (
                  <> It resumes automatically at {new Date(job.context.resume_at).toLocaleTimeString()}.</>
                )}
              </div>
              <div className="mt-1 text-xs opacity-75">
                Progress is checkpointed at {job.current} of {job.total}
                {typeof job.context?.rate_limit_hits === 'number' && job.context.rate_limit_hits > 0 && (
                  <> · {job.context.rate_limit_hits} quota stop{job.context.rate_limit_hits === 1 ? '' : 's'} this run</>
                )}
                . The nightly automation also picks up parked jobs from this checkpoint.
              </div>
            </div>
            <div className="shrink-0 flex items-center gap-1.5">
              <button
                onClick={resumeJob}
                title="Retry now. If SmartOLT's quota has not cleared the job stays paused."
                className="px-2 py-1 rounded border border-amber-500/40 text-xs font-medium hover:bg-amber-500/10"
              >
                Resume
              </button>
              <button
                onClick={cancelJob}
                className="px-2 py-1 rounded border border-amber-500/40 text-xs font-medium hover:bg-amber-500/10"
              >
                Abort
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Live slice progress for any running background job */}
      {job && job.status === 'running' && (
        <div className={`mb-4 rounded-lg border p-3 ${card}`}>
          <div className="flex items-center justify-between gap-3 mb-2">
            <span className={`text-sm font-medium ${text} flex items-center gap-2`}>
              <Loader2 className="w-4 h-4 animate-spin text-cyan-500" />
              {job.message}
            </span>
            <div className="flex items-center gap-2 shrink-0">
              <span className={`text-xs font-mono ${muted}`}>
                {job.current} / {job.total || '?'}
              </span>
              <button
                onClick={jobPaused ? resumeJob : pauseJob}
                className={`px-2 py-1 rounded border text-xs font-medium ${card} ${text}`}
              >
                {jobPaused ? 'Resume' : 'Pause'}
              </button>
              <button
                onClick={cancelJob}
                className="px-2 py-1 rounded border border-red-500/40 text-red-500 text-xs font-medium hover:bg-red-500/10"
              >
                Abort
              </button>
            </div>
          </div>

          <div className={`h-1.5 rounded-full overflow-hidden ${isDarkMode ? 'bg-gray-800' : 'bg-gray-200'}`}>
            <div
              className="h-full bg-cyan-500 transition-all duration-300"
              style={{ width: job.total > 0 ? `${Math.min(100, (job.current / job.total) * 100)}%` : '100%' }}
            />
          </div>
        </div>
      )}

      {/* Signal summary */}
      {state && (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
          <div className={`rounded-xl border p-3 ${card}`}>
            <div className={`text-xs font-medium ${muted}`}>Total ONUs</div>
            <div className={`text-2xl font-bold mt-1 ${text}`}>{state.inventory_count}</div>
          </div>
          {(Object.keys(SIGNAL_STYLES) as SignalBand[]).map((band) => (
            <div key={band} className={`rounded-xl border p-3 ${card}`}>
              <div className={`text-xs font-medium ${muted}`}>
                {SIGNAL_STYLES[band].label}
                {band === 'optimal' && <span className="opacity-60"> &gt; {state.thresholds.optimal_above} dBm</span>}
                {band === 'critical' && <span className="opacity-60"> &lt; {state.thresholds.critical_below} dBm</span>}
              </div>
              <div
                className={`text-2xl font-bold mt-1 ${
                  band === 'optimal' ? 'text-emerald-500'
                    : band === 'warning' ? 'text-amber-500'
                    : band === 'critical' ? 'text-red-500'
                    : 'text-gray-400'
                }`}
              >
                {state.signal_counts[band] ?? 0}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Tabs */}
      <div className="flex flex-wrap items-center gap-2 mb-4">
        {TABS.map(({ id, label, icon: Icon }) => (
          <button
            key={id}
            onClick={() => setTab(id)}
            className={`px-3 py-2 rounded-lg text-sm font-medium border flex items-center gap-2 transition-colors ${
              tab === id ? 'bg-cyan-600 border-cyan-600 text-white' : `${card} ${text} hover:border-cyan-500/50`
            }`}
          >
            <Icon className="w-4 h-4" /> {label}
          </button>
        ))}
      </div>

      {/* Search + per-tab controls */}
      {tab !== 'logs' && (
        <div className={`rounded-xl border p-3 mb-4 flex flex-col md:flex-row md:items-center gap-3 ${card}`}>
          <div className="relative flex-1">
            <Search className={`w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 ${muted}`} />
            <input
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
              placeholder="Search by serial, name, account number or zone…"
              className={`w-full pl-9 pr-3 py-2 rounded-lg border text-sm ${input}`}
            />
          </div>

          {tab === 'cleanup' && (
            <div className="flex items-center gap-2">
              <label className={`text-xs ${muted}`}>Offline for at least</label>
              <input
                type="number"
                min={1}
                value={offlineDays}
                onChange={(e) => setOfflineDays(Math.max(1, Number(e.target.value)))}
                className={`w-20 px-2 py-2 rounded-lg border text-sm ${input}`}
              />
              <span className={`text-xs ${muted}`}>days</span>
              <button
                onClick={() => loadTabData('cleanup')}
                className={`px-3 py-2 rounded-lg border text-xs font-medium ${card} ${text}`}
              >
                Re-evaluate
              </button>
            </div>
          )}

          {tab === 'mac_alignment' && (
            <div className="flex items-center gap-2">
              <button
                onClick={() => loadTabData('mac_alignment')}
                disabled={loading}
                className={`px-3 py-2 rounded-lg border text-xs font-medium disabled:opacity-50 ${card} ${text}`}
              >
                Re-match
              </button>

              {/* Batch actions. `Align All Matched` deliberately ignores the checkbox
                  selection and takes every eligible row — including ones on pages the
                  operator has not scrolled to — which is the whole point of "All". */}
              <select
                value=""
                disabled={jobRunning}
                onChange={(e) => {
                  const action = e.target.value;
                  e.target.value = '';

                  if (action === 'align_all') {
                    const items = (macAlignment?.rows ?? [])
                      .filter((row) => row.eligible)
                      .map((row) => ({ external_id: row.external_id, new_name: row.target_name }));

                    if (items.length === 0) {
                      setNotice({ tone: 'info', text: 'Every matched ONU already carries its RADIUS username.' });
                      return;
                    }
                    startJob('rename', { items });
                    return;
                  }

                  if (action === 'align_selected') {
                    const items = (macAlignment?.rows ?? [])
                      .filter((row) => row.eligible && selected.has(row.external_id))
                      .map((row) => ({ external_id: row.external_id, new_name: row.target_name }));

                    if (items.length === 0) {
                      setNotice({ tone: 'info', text: 'Select at least one ONU whose name differs from its RADIUS username.' });
                      return;
                    }
                    startJob('rename', { items });
                    return;
                  }

                  if (action === 'unprovision_selected') {
                    if (selected.size === 0) {
                      setNotice({ tone: 'info', text: 'Select at least one ONU to unprovision.' });
                      return;
                    }
                    // Same confirmation gate the cleanup tab uses — permanent removal
                    // must never be one dropdown click away.
                    setDeleteConfirm('');
                    setDeleteModalOpen(true);
                  }
                }}
                className={`px-3 py-2 rounded-lg border text-sm font-medium disabled:opacity-50 ${input}`}
              >
                <option value="">Batch actions…</option>
                <option value="align_all">
                  Align All Matched ({(macAlignment?.rows ?? []).filter((r) => r.eligible).length})
                </option>
                <option value="align_selected">Align Selected ({selected.size})</option>
                <option value="unprovision_selected">Unprovision Selected ({selected.size})</option>
              </select>
            </div>
          )}

          {tab === 'alignment' && (
            <button
              onClick={() => {
                const items = (alignment?.rows ?? [])
                  .filter((row) => row.eligible && selected.has(row.external_id))
                  .map((row) => ({ external_id: row.external_id, new_name: row.proposed_name }));
                if (items.length === 0) {
                  setNotice({ tone: 'info', text: 'Select at least one ONU that needs renaming.' });
                  return;
                }
                startJob('rename', { items });
              }}
              disabled={jobRunning}
              className="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium disabled:opacity-50"
            >
              Rename {selected.size > 0 ? `${selected.size} ` : ''}selected
            </button>
          )}

          {tab === 'profile' && (
            <button
              onClick={() => {
                const items = (profile?.rows ?? [])
                  .filter((row) => row.eligible && selected.has(row.external_id))
                  .map((row) => ({
                    external_id: row.external_id,
                    new_address: row.new_address,
                    new_contact: row.new_contact,
                    new_latitude: row.new_latitude,
                    new_longitude: row.new_longitude,
                    address_changed: row.address_changed,
                    contact_changed: row.contact_changed,
                    coords_changed: row.coords_changed,
                  }));
                if (items.length === 0) {
                  setNotice({ tone: 'info', text: 'Select at least one ONU with a pending change.' });
                  return;
                }
                startJob('profile_sync', { items });
              }}
              disabled={jobRunning}
              className="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium disabled:opacity-50"
            >
              Push {selected.size > 0 ? `${selected.size} ` : ''}selected
            </button>
          )}

          {tab === 'cleanup' && (
            <button
              onClick={() => {
                if (selected.size === 0) {
                  setNotice({ tone: 'info', text: 'Select at least one eligible ONU.' });
                  return;
                }
                setDeleteConfirm('');
                setDeleteModalOpen(true);
              }}
              disabled={jobRunning}
              className="px-3 py-2 rounded-lg bg-red-600 hover:bg-red-500 text-white text-sm font-medium disabled:opacity-50 flex items-center gap-2"
            >
              <Trash2 className="w-4 h-4" /> Decommission {selected.size > 0 ? selected.size : ''}
            </button>
          )}
        </div>
      )}

      {/* MAC alignment summary — and any device that did not answer */}
      {tab === 'mac_alignment' && macAlignment && (
        <div className="mb-4 space-y-2">
          <div className={`px-4 py-3 rounded-lg border text-sm ${card} ${muted}`}>
            Matched <strong className={text}>{macAlignment.summary.matched}</strong> of{' '}
            <strong className={text}>{macAlignment.summary.total}</strong> ONU(s) against{' '}
            <strong className={text}>{macAlignment.summary.sessions}</strong> live RADIUS session(s) —{' '}
            <span className="text-amber-500">{macAlignment.summary.rename_needed} need renaming</span>,{' '}
            <span className="text-emerald-500">{macAlignment.summary.aligned} already aligned</span>,{' '}
            {macAlignment.summary.unmatched} unmatched, {macAlignment.summary.no_mac} awaiting MAC discovery.
            The target name is the matched RADIUS username exactly.
          </div>

          {macAlignment.errors.length > 0 && (
            <div className="px-4 py-3 rounded-lg border border-amber-500/30 bg-amber-500/10 text-sm text-amber-500 flex items-start gap-2">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
              <span>
                Some RADIUS devices did not answer, so this match is incomplete — an ONU shown as
                unmatched may simply belong to a device that was unreachable. {macAlignment.errors.join(' · ')}
              </span>
            </div>
          )}
        </div>
      )}

      {/* VLAN advisory on the profile tab */}
      {tab === 'profile' && profile && (
        <div className="mb-4 px-4 py-3 rounded-lg border border-amber-500/30 bg-amber-500/10 text-sm text-amber-500 flex items-start gap-2">
          <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
          <span>
            {profile.vlan_note}
            {profile.summary.vlan_drift > 0 && ` ${profile.summary.vlan_drift} ONU(s) currently show a VLAN difference.`}
          </span>
        </div>
      )}

      {/* Content table */}
      <div className={`rounded-xl border overflow-hidden ${card}`}>
        <div className="overflow-x-auto">
          {tab === 'inventory' && (
            <table className="w-full text-sm">
              <thead className={`text-xs uppercase tracking-wide ${headRow}`}>
                <tr>
                  <th className="px-3 py-2.5 text-left font-semibold">Serial</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Name</th>
                  <th className="px-3 py-2.5 text-left font-semibold">OLT / Board / Port</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Zone</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Status</th>
                  <th className="px-3 py-2.5 text-left font-semibold">RX (dBm)</th>
                  <th className="px-3 py-2.5 text-left font-semibold">TX (dBm)</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Signal</th>
                </tr>
              </thead>
              <tbody className={isDarkMode ? 'divide-y divide-gray-800' : 'divide-y divide-gray-100'}>
                {loading && <tr><td colSpan={8} className={`px-4 py-12 text-center ${muted}`}><Loader2 className="w-6 h-6 animate-spin mx-auto" /></td></tr>}
                {!loading && pagedRows.length === 0 && (
                  <tr><td colSpan={8} className={`px-4 py-12 text-center ${muted}`}>
                    No ONU in the cache. Run <strong>Sync Inventory</strong> to download it from SmartOLT.
                  </td></tr>
                )}
                {!loading && (pagedRows as OnuRow[]).map((row) => (
                  <tr key={row.external_id} className={rowHover}>
                    <td className={`px-3 py-2.5 font-mono text-xs ${text}`}>{row.sn || '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${text}`}>{row.name || <span className={muted}>not set</span>}</td>
                    <td className={`px-3 py-2.5 text-xs ${muted}`}>{[row.olt_name, row.board, row.port].filter(Boolean).join(' / ') || '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${muted}`}>{row.zone_name || '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${text}`}>
                      {row.status}
                      {row.days_offline !== null && row.status !== 'online' && (
                        <span className={`ml-1 ${muted}`}>({row.days_offline}d)</span>
                      )}
                    </td>
                    <td className={`px-3 py-2.5 text-xs font-mono ${text}`}>{row.rx_power ?? '—'}</td>
                    <td className={`px-3 py-2.5 text-xs font-mono ${text}`}>{row.tx_power ?? '—'}</td>
                    <td className="px-3 py-2.5">
                      <span className={`text-[11px] px-2 py-0.5 rounded border font-medium ${SIGNAL_STYLES[row.signal].classes}`}>
                        {SIGNAL_STYLES[row.signal].label}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {tab === 'mac_alignment' && (
            <table className="w-full text-sm">
              <thead className={`text-xs uppercase tracking-wide ${headRow}`}>
                <tr>
                  <th className="px-3 py-2.5 w-10">
                    <input
                      type="checkbox"
                      checked={pagedRows.length > 0 && pagedRows.every((r: any) => !r.eligible || selected.has(r.external_id))}
                      onChange={(e) => {
                        const next = new Set(selected);
                        pagedRows.forEach((r: any) => {
                          if (!r.eligible) return;
                          e.target.checked ? next.add(r.external_id) : next.delete(r.external_id);
                        });
                        setSelected(next);
                      }}
                      className="rounded"
                    />
                  </th>
                  <th className="px-3 py-2.5 text-left font-semibold">State</th>
                  <th className="px-3 py-2.5 text-left font-semibold">RADIUS Username</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Calling-Station-Id (MAC)</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Current SmartOLT Name</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Target Name</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Serial</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Status</th>
                  <th className="px-3 py-2.5 text-right font-semibold">Actions</th>
                </tr>
              </thead>
              <tbody className={isDarkMode ? 'divide-y divide-gray-800' : 'divide-y divide-gray-100'}>
                {loading && <tr><td colSpan={9} className={`px-4 py-12 text-center ${muted}`}><Loader2 className="w-6 h-6 animate-spin mx-auto" /></td></tr>}
                {!loading && pagedRows.length === 0 && (
                  <tr><td colSpan={9} className={`px-4 py-12 text-center ${muted}`}>
                    Nothing matched. Run <strong>Sync Inventory</strong>, then <strong>Scan Optical Power</strong> to
                    discover the bridge MACs this pass matches against.
                  </td></tr>
                )}
                {!loading && pagedRows.map((row: any) => (
                  <tr key={row.external_id} className={rowHover}>
                    <td className="px-3 py-2.5">
                      <input
                        type="checkbox"
                        disabled={!row.eligible}
                        checked={selected.has(row.external_id)}
                        onChange={(e) => toggle(row.external_id, e.target.checked)}
                        className="rounded disabled:opacity-30"
                      />
                    </td>
                    <td className="px-3 py-2.5">
                      <span className={`text-[11px] px-2 py-0.5 rounded border font-medium ${MAC_STATE_BADGES[row.state as MacAlignState].classes}`}>
                        {MAC_STATE_BADGES[row.state as MacAlignState].label}
                      </span>
                    </td>
                    <td className={`px-3 py-2.5 text-xs font-mono ${row.radius_username ? text : muted}`}>
                      {row.radius_username || '—'}
                    </td>
                    <td className={`px-3 py-2.5 text-xs font-mono ${muted}`}>{row.calling_station_id || '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${row.current_name === 'not set' ? muted : text}`}>{row.current_name}</td>
                    <td className={`px-3 py-2.5 text-xs font-mono font-medium ${row.eligible ? 'text-cyan-500' : muted}`}>
                      {row.target_name || '—'}
                    </td>
                    <td className={`px-3 py-2.5 font-mono text-xs ${text}`}>{row.sn || '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${muted}`}>{row.status}</td>
                    <td className="px-3 py-2.5 text-right">
                      {row.eligible ? (
                        <button
                          onClick={() => startJob('rename', {
                            items: [{ external_id: row.external_id, new_name: row.target_name }],
                          })}
                          disabled={jobRunning}
                          title={`Rename this ONU to "${row.target_name}"`}
                          className="px-2 py-1 rounded border border-cyan-500/40 text-cyan-500 text-xs font-medium hover:bg-cyan-500/10 disabled:opacity-40"
                        >
                          Rename
                        </button>
                      ) : (
                        <span className={`text-xs ${muted}`} title={row.reason}>—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {tab === 'alignment' && (
            <table className="w-full text-sm">
              <thead className={`text-xs uppercase tracking-wide ${headRow}`}>
                <tr>
                  <th className="px-3 py-2.5 w-10">
                    <input
                      type="checkbox"
                      checked={pagedRows.length > 0 && pagedRows.every((r: any) => selected.has(r.external_id))}
                      onChange={(e) => {
                        const next = new Set(selected);
                        pagedRows.forEach((r: any) => {
                          if (!r.eligible) return;
                          e.target.checked ? next.add(r.external_id) : next.delete(r.external_id);
                        });
                        setSelected(next);
                      }}
                      className="rounded"
                    />
                  </th>
                  <th className="px-3 py-2.5 text-left font-semibold">Serial</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Current Name</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Proposed Name</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Matched By</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Status</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Verdict</th>
                </tr>
              </thead>
              <tbody className={isDarkMode ? 'divide-y divide-gray-800' : 'divide-y divide-gray-100'}>
                {loading && <tr><td colSpan={7} className={`px-4 py-12 text-center ${muted}`}><Loader2 className="w-6 h-6 animate-spin mx-auto" /></td></tr>}
                {!loading && pagedRows.length === 0 && (
                  <tr><td colSpan={7} className={`px-4 py-12 text-center ${muted}`}>Nothing to align.</td></tr>
                )}
                {!loading && pagedRows.map((row: any) => (
                  <tr key={row.external_id} className={rowHover}>
                    <td className="px-3 py-2.5">
                      <input
                        type="checkbox"
                        disabled={!row.eligible}
                        checked={selected.has(row.external_id)}
                        onChange={(e) => toggle(row.external_id, e.target.checked)}
                        className="rounded disabled:opacity-30"
                      />
                    </td>
                    <td className={`px-3 py-2.5 font-mono text-xs ${text}`}>{row.sn || '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${row.current_name === 'not set' ? muted : text}`}>{row.current_name}</td>
                    <td className={`px-3 py-2.5 text-xs font-medium ${row.rename_needed ? 'text-cyan-500' : muted}`}>
                      {row.proposed_name || '—'}
                    </td>
                    <td className={`px-3 py-2.5 text-xs ${muted}`}>{row.matched_by ?? '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${muted}`}>{row.status}</td>
                    <td className={`px-3 py-2.5 text-xs ${row.rename_needed ? 'text-amber-500' : muted}`}>{row.reason}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {tab === 'profile' && (
            <table className="w-full text-sm">
              <thead className={`text-xs uppercase tracking-wide ${headRow}`}>
                <tr>
                  <th className="px-3 py-2.5 w-10">
                    <input
                      type="checkbox"
                      checked={pagedRows.length > 0 && pagedRows.every((r: any) => selected.has(r.external_id))}
                      onChange={(e) => {
                        const next = new Set(selected);
                        pagedRows.forEach((r: any) => {
                          if (!r.eligible) return;
                          e.target.checked ? next.add(r.external_id) : next.delete(r.external_id);
                        });
                        setSelected(next);
                      }}
                      className="rounded"
                    />
                  </th>
                  <th className="px-3 py-2.5 text-left font-semibold">Serial / Account</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Address</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Contact</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Coordinates</th>
                  <th className="px-3 py-2.5 text-left font-semibold">VLAN</th>
                </tr>
              </thead>
              <tbody className={isDarkMode ? 'divide-y divide-gray-800' : 'divide-y divide-gray-100'}>
                {loading && <tr><td colSpan={6} className={`px-4 py-12 text-center ${muted}`}><Loader2 className="w-6 h-6 animate-spin mx-auto" /></td></tr>}
                {!loading && pagedRows.length === 0 && (
                  <tr><td colSpan={6} className={`px-4 py-12 text-center ${muted}`}>No matched ONU has a pending profile change.</td></tr>
                )}
                {!loading && pagedRows.map((row: any) => (
                  <tr key={row.external_id} className={rowHover}>
                    <td className="px-3 py-2.5">
                      <input
                        type="checkbox"
                        disabled={!row.eligible}
                        checked={selected.has(row.external_id)}
                        onChange={(e) => toggle(row.external_id, e.target.checked)}
                        className="rounded disabled:opacity-30"
                      />
                    </td>
                    <td className={`px-3 py-2.5 text-xs ${text}`}>
                      <div className="font-mono">{row.sn || '—'}</div>
                      <div className={muted}>{row.account_no ?? '—'} {row.customer_name ? `· ${row.customer_name}` : ''}</div>
                    </td>
                    <td className="px-3 py-2.5 text-xs">
                      {row.address_changed ? (
                        <>
                          <div className={`line-through ${muted}`}>{row.old_address || '(empty)'}</div>
                          <div className="text-cyan-500">{row.new_address}</div>
                        </>
                      ) : <span className={muted}>{row.old_address || '—'}</span>}
                    </td>
                    <td className="px-3 py-2.5 text-xs">
                      {row.contact_changed ? (
                        <>
                          <div className={`line-through ${muted}`}>{row.old_contact || '(empty)'}</div>
                          <div className="text-cyan-500">{row.new_contact}</div>
                        </>
                      ) : <span className={muted}>{row.old_contact || '—'}</span>}
                    </td>
                    <td className="px-3 py-2.5 text-xs">
                      {row.coords_changed ? (
                        <>
                          <div className={`line-through ${muted}`}>{row.old_latitude || '—'}, {row.old_longitude || '—'}</div>
                          <div className="text-cyan-500">{row.new_latitude}, {row.new_longitude}</div>
                        </>
                      ) : <span className={muted}>{row.old_latitude ? `${row.old_latitude}, ${row.old_longitude}` : '—'}</span>}
                    </td>
                    <td className="px-3 py-2.5 text-xs">
                      <span className={row.vlan_drift ? 'text-amber-500' : muted}>
                        {row.olt_vlan || '—'}{row.vlan_drift ? ` → ${row.billing_vlan}` : ''}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {tab === 'cleanup' && (
            <table className="w-full text-sm">
              <thead className={`text-xs uppercase tracking-wide ${headRow}`}>
                <tr>
                  <th className="px-3 py-2.5 w-10">
                    <input
                      type="checkbox"
                      checked={pagedRows.length > 0 && pagedRows.filter((r: any) => r.eligible).every((r: any) => selected.has(r.external_id))}
                      onChange={(e) => {
                        const next = new Set(selected);
                        pagedRows.forEach((r: any) => {
                          if (!r.eligible) return;
                          e.target.checked ? next.add(r.external_id) : next.delete(r.external_id);
                        });
                        setSelected(next);
                      }}
                      className="rounded"
                    />
                  </th>
                  <th className="px-3 py-2.5 text-left font-semibold">Serial</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Name</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Zone / OLT</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Status</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Days Offline</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Verdict</th>
                </tr>
              </thead>
              <tbody className={isDarkMode ? 'divide-y divide-gray-800' : 'divide-y divide-gray-100'}>
                {loading && <tr><td colSpan={7} className={`px-4 py-12 text-center ${muted}`}><Loader2 className="w-6 h-6 animate-spin mx-auto" /></td></tr>}
                {!loading && pagedRows.length === 0 && (
                  <tr><td colSpan={7} className={`px-4 py-12 text-center ${muted}`}>
                    No ONU has been offline for {offlineDays} days or more.
                  </td></tr>
                )}
                {!loading && pagedRows.map((row: any) => (
                  <tr key={row.external_id} className={rowHover}>
                    <td className="px-3 py-2.5">
                      <input
                        type="checkbox"
                        disabled={!row.eligible}
                        checked={selected.has(row.external_id)}
                        onChange={(e) => toggle(row.external_id, e.target.checked)}
                        className="rounded disabled:opacity-30"
                      />
                    </td>
                    <td className={`px-3 py-2.5 font-mono text-xs ${text}`}>{row.sn || '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${text}`}>{row.name || <span className={muted}>not set</span>}</td>
                    <td className={`px-3 py-2.5 text-xs ${muted}`}>{[row.zone_name, row.olt_name].filter(Boolean).join(' / ') || '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${text}`}>{row.status}</td>
                    <td className={`px-3 py-2.5 text-xs ${text}`}>{row.days_offline ?? '—'}</td>
                    <td className="px-3 py-2.5 text-xs">
                      {row.eligible ? (
                        <span className="text-[11px] px-2 py-0.5 rounded border bg-emerald-500/15 text-emerald-500 border-emerald-500/30">
                          Eligible
                        </span>
                      ) : (
                        <div className="space-y-0.5">
                          {row.reasons.map((reason: string) => (
                            <div key={reason} className="flex items-start gap-1 text-amber-500">
                              <XCircle className="w-3 h-3 mt-0.5 shrink-0" /> {reason}
                            </div>
                          ))}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {tab === 'logs' && (
            <table className="w-full text-sm">
              <thead className={`text-xs uppercase tracking-wide ${headRow}`}>
                <tr>
                  <th className="px-3 py-2.5 text-left font-semibold">When</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Operator</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Action</th>
                  <th className="px-3 py-2.5 text-left font-semibold">ONU</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Change</th>
                  <th className="px-3 py-2.5 text-left font-semibold">Status</th>
                  <th className="px-3 py-2.5 text-right font-semibold">Undo</th>
                </tr>
              </thead>
              <tbody className={isDarkMode ? 'divide-y divide-gray-800' : 'divide-y divide-gray-100'}>
                {loading && <tr><td colSpan={7} className={`px-4 py-10 text-center ${muted}`}><Loader2 className="w-5 h-5 animate-spin mx-auto" /></td></tr>}
                {!loading && logs.length === 0 && (
                  <tr><td colSpan={7} className={`px-4 py-10 text-center ${muted}`}>No operation has been recorded yet.</td></tr>
                )}
                {!loading && logs.map((entry) => (
                  <tr key={entry.log_id} className={rowHover}>
                    <td className={`px-3 py-2.5 text-xs whitespace-nowrap ${muted}`}>
                      {entry.created_at ? new Date(entry.created_at).toLocaleString() : '—'}
                    </td>
                    <td className={`px-3 py-2.5 text-xs ${text}`}>{entry.operator}</td>
                    <td className={`px-3 py-2.5 text-xs font-mono ${text}`}>{entry.action}</td>
                    <td className={`px-3 py-2.5 text-xs font-mono ${text}`}>{entry.external_id ?? '—'}</td>
                    <td className={`px-3 py-2.5 text-xs ${muted} max-w-md`}>
                      <div className="truncate" title={entry.message}>{entry.message}</div>
                    </td>
                    <td className="px-3 py-2.5">
                      {entry.reversed ? (
                        <span className="text-[11px] px-2 py-0.5 rounded border bg-gray-500/15 text-gray-400 border-gray-500/30">Reversed</span>
                      ) : entry.reversible ? (
                        <span className="text-[11px] px-2 py-0.5 rounded border bg-emerald-500/15 text-emerald-500 border-emerald-500/30">Applied</span>
                      ) : (
                        <span className="text-[11px] px-2 py-0.5 rounded border bg-amber-500/15 text-amber-500 border-amber-500/30">Final</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-right">
                      <button
                        onClick={() => setUndoTarget(entry)}
                        disabled={!entry.reversible || entry.reversed}
                        className="px-2 py-1 rounded text-[11px] font-medium bg-cyan-500/15 text-cyan-400 border border-cyan-500/30 hover:bg-cyan-500/25 disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center gap-1"
                      >
                        <Undo2 className="w-3 h-3" /> Undo
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {tab !== 'logs' && !loading && activeRows.length > PAGE_SIZE && (
          <div className={`flex items-center justify-between px-4 py-3 border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-100'}`}>
            <span className={`text-xs ${muted}`}>
              Showing {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, activeRows.length)} of {activeRows.length}
            </span>
            <div className="flex items-center gap-2">
              <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1}
                className={`px-3 py-1 rounded border text-xs disabled:opacity-40 ${card} ${text}`}>Previous</button>
              <span className={`text-xs ${muted}`}>Page {page} of {totalPages}</span>
              <button onClick={() => setPage((p) => Math.min(totalPages, p + 1))} disabled={page >= totalPages}
                className={`px-3 py-1 rounded border text-xs disabled:opacity-40 ${card} ${text}`}>Next</button>
            </div>
          </div>
        )}
      </div>

      {/* Stepwise job modal */}
      {job && (job.status === 'running' || jobPaused) && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className={`w-full max-w-2xl rounded-xl border p-5 ${card}`}>
            <div className="flex items-center justify-between mb-4">
              <h3 className={`text-base font-bold flex items-center gap-2 ${text}`}>
                <Loader2 className={`w-4 h-4 ${jobPaused ? '' : 'animate-spin'}`} />
                {job.type.replace(/_/g, ' ')}
              </h3>
              <span className={`text-xs ${muted}`}>
                Step {job.current} of {job.total || '?'}
              </span>
            </div>

            <div className={`h-2 rounded-full overflow-hidden mb-2 ${isDarkMode ? 'bg-gray-800' : 'bg-gray-200'}`}>
              <div
                className="h-full bg-gradient-to-r from-cyan-500 to-blue-600 transition-all duration-300"
                style={{ width: `${job.total > 0 ? Math.min(100, (job.current / job.total) * 100) : 5}%` }}
              />
            </div>
            <p className={`text-sm mb-4 ${muted}`}>{job.message}</p>

            <div className={`rounded-lg border p-3 mb-4 h-48 overflow-y-auto font-mono text-[11px] ${isDarkMode ? 'bg-gray-950 border-gray-800 text-gray-400' : 'bg-gray-50 border-gray-200 text-gray-600'}`}>
              {jobLog.length === 0 ? <span>Waiting for the first step…</span> : jobLog.map((line, index) => <div key={index}>{line}</div>)}
            </div>

            <div className="flex items-center justify-end gap-2">
              {jobPaused ? (
                <button onClick={resumeJob} className="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium">
                  Resume
                </button>
              ) : (
                <button onClick={pauseJob} className={`px-4 py-2 rounded-lg border text-sm ${card} ${text}`}>
                  Pause
                </button>
              )}
              <button onClick={cancelJob} className="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-500 text-white text-sm font-medium">
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Permanent deletion confirmation */}
      {deleteModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className={`w-full max-w-lg rounded-xl border p-5 ${card}`}>
            <div className="flex items-start gap-3 mb-4">
              <AlertTriangle className="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
              <div>
                <h3 className={`text-base font-bold ${text}`}>Permanently unprovision {selected.size} ONU(s)?</h3>
                <p className={`text-sm mt-1 ${muted}`}>
                  This removes the ONU from the OLT. It cannot be undone from this tool — the ONU must be re-provisioned
                  in SmartOLT. Every candidate is re-checked against billing immediately before its delete fires, and any
                  that has come back to life is skipped.
                </p>
              </div>
            </div>

            {tab === 'mac_alignment' && (
              <div className="mb-4 px-3 py-2 rounded-lg border border-amber-500/30 bg-amber-500/10 text-xs text-amber-500">
                The same safety guards apply from this tab: an ONU is only removed if it has been dark for at least{' '}
                {offlineDays} days, its billing account is Terminated, it has no open job order, and nobody holds a live
                RADIUS session on it. A currently-matched ONU will be reported as blocked, not deleted.
              </div>
            )}

            <label className={`block text-xs font-medium mb-1.5 ${muted}`}>
              Type <span className="font-mono font-bold">{DELETE_CONFIRMATION}</span> to confirm
            </label>
            <input
              value={deleteConfirm}
              onChange={(e) => setDeleteConfirm(e.target.value)}
              placeholder={DELETE_CONFIRMATION}
              className={`w-full px-3 py-2 rounded-lg border text-sm font-mono mb-4 ${input}`}
            />

            <div className="flex items-center justify-end gap-2">
              <button onClick={() => { setDeleteModalOpen(false); setDeleteConfirm(''); }} className={`px-4 py-2 rounded-lg border text-sm ${card} ${text}`}>
                Cancel
              </button>
              <button
                onClick={() => {
                  setDeleteModalOpen(false);
                  startJob('delete', {
                    external_ids: Array.from(selected),
                    offline_days: offlineDays,
                    confirmation: deleteConfirm,
                  });
                  setDeleteConfirm('');
                }}
                disabled={deleteConfirm !== DELETE_CONFIRMATION}
                className="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-500 text-white text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed"
              >
                Permanently delete
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Undo confirmation */}
      {undoTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className={`w-full max-w-md rounded-xl border p-5 ${card}`}>
            <h3 className={`text-base font-bold mb-2 ${text}`}>Reverse this operation?</h3>
            <p className={`text-sm mb-3 ${muted}`}>{undoTarget.message}</p>

            <div className={`rounded-lg border p-3 mb-4 text-xs font-mono ${isDarkMode ? 'bg-gray-950 border-gray-800' : 'bg-gray-50 border-gray-200'}`}>
              <div className={`mb-1 ${muted}`}>Restoring:</div>
              <pre className={`whitespace-pre-wrap break-all ${text}`}>{JSON.stringify(undoTarget.previous_state, null, 2)}</pre>
            </div>

            <div className="flex items-center justify-end gap-2">
              <button onClick={() => setUndoTarget(null)} className={`px-4 py-2 rounded-lg border text-sm ${card} ${text}`}>
                Cancel
              </button>
              <button
                onClick={confirmUndo}
                className="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium flex items-center gap-2"
              >
                <Undo2 className="w-4 h-4" /> Reverse
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default SmartOltTool;
