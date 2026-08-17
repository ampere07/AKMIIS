import apiClient from '../config/api';

export type SignalBand = 'optimal' | 'warning' | 'critical' | 'offline';

/**
 * `optical_scan` is the per-ONU optical-power and bridge-MAC crawl — one API call
 * per ONU, and the endpoint SmartOLT throttles hardest, so it only ever runs as a
 * background job. `mac_discovery` is its original name, still accepted server-side.
 */
export type JobType =
  | 'smartolt_sync'
  | 'radius_scan'
  | 'optical_scan'
  | 'mac_discovery'
  | 'rename'
  | 'profile_sync'
  | 'delete';

/**
 * `paused` is a rate-limit stop, not a failure: SmartOLT refused the call, the job
 * checkpointed where it got to, and it resumes itself once the cooldown elapses.
 */
export type JobStatus = 'pending' | 'running' | 'paused' | 'completed' | 'failed' | 'aborted';

/** Checkpoint state a paused job carries, so the banner can say when it resumes. */
export interface JobContext {
  rate_limited?: boolean;
  rate_limit_hits?: number;
  paused_at?: string;
  resume_at?: string;
  pause_reason?: string;
}

export interface ToolJob {
  id: number;
  type: JobType;
  status: JobStatus;
  current: number;
  total: number;
  message: string;
  summary: Record<string, number> | null;
  context?: JobContext;
  created_at: string | null;
  updated_at: string | null;
}

export interface OnuRow {
  external_id: string;
  sn: string;
  name: string;
  olt_name: string;
  board: string;
  port: string;
  zone_name: string;
  odb_name: string;
  address: string;
  contact: string;
  status: string;
  last_status_change: string;
  days_offline: number | null;
  rx_power: number | null;
  tx_power: number | null;
  signal: SignalBand;
  signal_checked_at: string | null;
}

export interface SmartOltState {
  configured: boolean;
  sub_domain: string | null;
  inventory_count: number;
  inventory_synced_at: string | null;
  status_synced_at: string | null;
  optical_checked: number;
  status_counts: Record<string, number>;
  signal_counts: Record<SignalBand, number>;
  thresholds: { optimal_above: number; critical_below: number };
  active_job: ToolJob | null;
  rows: OnuRow[];
}

export interface AlignmentRow {
  external_id: string;
  sn: string;
  current_name: string;
  proposed_name: string;
  matched_by: string | null;
  account_no: string | null;
  customer_name: string | null;
  plan: string | null;
  status: string;
  rename_needed: boolean;
  eligible: boolean;
  reason: string;
}

export interface AlignmentPreview {
  summary: { total: number; matched: number; rename_needed: number; aligned: number; unmatched: number; placeholder: number };
  rows: AlignmentRow[];
  updated_at: string;
}

/**
 * A row of the MAC alignment pass: which subscriber is actually authenticating
 * behind this ONU, matched by bridge MAC against the RADIUS calling-station-id.
 * `target_name` is the matched RADIUS username verbatim, never a composed label.
 */
export type MacAlignState = 'aligned' | 'rename_needed' | 'unmatched' | 'no_mac';

export interface MacAlignmentRow {
  external_id: string;
  state: MacAlignState;
  radius_username: string;
  calling_station_id: string;
  current_name: string;
  target_name: string;
  sn: string;
  status: string;
  server_id: number | null;
  server_label: string;
  eligible: boolean;
  reason: string;
}

export interface MacAlignmentPreview {
  summary: {
    total: number;
    matched: number;
    rename_needed: number;
    aligned: number;
    unmatched: number;
    no_mac: number;
    sessions: number;
  };
  rows: MacAlignmentRow[];
  errors: string[];
  updated_at: string;
}

export interface ProfileRow {
  external_id: string;
  sn: string;
  name: string;
  account_no: string | null;
  customer_name: string | null;
  old_address: string;
  new_address: string;
  old_contact: string;
  new_contact: string;
  old_latitude: string;
  new_latitude: string;
  old_longitude: string;
  new_longitude: string;
  olt_vlan: string;
  billing_vlan: string;
  address_changed: boolean;
  contact_changed: boolean;
  coords_changed: boolean;
  vlan_drift: boolean;
  eligible: boolean;
}

export interface ProfilePreview {
  summary: { total: number; eligible: number; unchanged: number; unmatched: number; vlan_drift: number };
  rows: ProfileRow[];
  vlan_note: string;
  updated_at: string;
}

export interface CleanupRow {
  external_id: string;
  sn: string;
  name: string;
  zone_name: string;
  odb_name: string;
  olt_name: string;
  status: string;
  last_status_change: string;
  days_offline: number | null;
  eligible: boolean;
  reasons: string[];
}

export interface CleanupPreview {
  summary: { total: number; eligible: number; blocked: number };
  rows: CleanupRow[];
  offline_days: number;
  updated_at: string;
}

export interface SmartOltLog {
  log_id: number;
  created_at: string | null;
  level: string;
  action: string;
  message: string;
  operator: string;
  external_id: string | null;
  previous_state: Record<string, any>;
  new_state: Record<string, any>;
  reversible: boolean;
  reversed: boolean;
  reversed_at: string | null;
}

export interface ActionResult {
  success: boolean;
  skipped: boolean;
  message: string;
}

export interface JobResult extends ActionResult {
  job: ToolJob | null;
}

export interface OpticalResult {
  success: boolean;
  checked: number;
  remaining: number;
  items: Array<{
    external_id: string;
    sn: string;
    name: string;
    status: string;
    rx_power: number | null;
    tx_power: number | null;
    signal: SignalBand;
    checked_at: string | null;
  }>;
  errors: string[];
}

/** The literal phrase the backend requires before it will unprovision an ONU. */
export const DELETE_CONFIRMATION = 'DELETE';

const BASE = '/smartolt-reconciliation';

const unwrapJob = async (call: any): Promise<JobResult> => {
  try {
    const response = await call;
    return response.data as JobResult;
  } catch (error: any) {
    const payload = error?.response?.data;
    return {
      success: false,
      skipped: false,
      message: payload?.message || error?.message || 'The request failed.',
      job: payload?.job ?? null,
    };
  }
};

export const smartOltReconciliationService = {
  getState: async (includeRows = false): Promise<SmartOltState> => {
    const response = await apiClient.get<{ success: boolean; data: SmartOltState }>(`${BASE}/state`, {
      params: { include_rows: includeRows ? 1 : 0 },
    });
    return response.data.data;
  },

  getOpticalPower: async (externalIds: string[] = [], limit = 25): Promise<OpticalResult> => {
    const response = await apiClient.get<OpticalResult>(`${BASE}/optical-power`, {
      params: { external_ids: externalIds, limit },
    });
    return response.data;
  },

  getAlignmentPreview: async (): Promise<AlignmentPreview> => {
    const response = await apiClient.get<{ success: boolean; data: AlignmentPreview }>(`${BASE}/alignment-preview`);
    return response.data.data;
  },

  getMacAlignment: async (): Promise<MacAlignmentPreview> => {
    const response = await apiClient.get<{ success: boolean; data: MacAlignmentPreview }>(`${BASE}/mac-alignment`);
    return response.data.data;
  },

  getProfilePreview: async (): Promise<ProfilePreview> => {
    const response = await apiClient.get<{ success: boolean; data: ProfilePreview }>(`${BASE}/profile-preview`);
    return response.data.data;
  },

  getCleanupPreview: async (offlineDays: number): Promise<CleanupPreview> => {
    const response = await apiClient.get<{ success: boolean; data: CleanupPreview }>(`${BASE}/cleanup-preview`, {
      params: { offline_days: offlineDays },
    });
    return response.data.data;
  },

  startJob: (type: JobType, options: Record<string, any> = {}): Promise<JobResult> =>
    unwrapJob(apiClient.post(`${BASE}/start-job`, { type, ...options })),

  processJob: (jobId: number): Promise<JobResult> => unwrapJob(apiClient.post(`${BASE}/process-job`, { job_id: jobId })),

  abortJob: (jobId: number): Promise<JobResult> => unwrapJob(apiClient.post(`${BASE}/abort-job`, { job_id: jobId })),

  undo: async (logId: number): Promise<ActionResult> => {
    try {
      const response = await apiClient.post<ActionResult>(`${BASE}/undo`, { log_id: logId });
      return response.data;
    } catch (error: any) {
      const payload = error?.response?.data;
      return { success: false, skipped: false, message: payload?.message || error?.message || 'The undo failed.' };
    }
  },

  getLogs: async (limit = 50): Promise<SmartOltLog[]> => {
    const response = await apiClient.get<{ success: boolean; data: SmartOltLog[] }>(`${BASE}/logs`, {
      params: { limit },
    });
    return response.data.data;
  },

  exportCsv: async (dataset: 'inventory' | 'alignment' | 'profile' | 'cleanup'): Promise<void> => {
    const response = await apiClient.get(`${BASE}/export`, {
      params: { dataset },
      responseType: 'blob',
    });

    const url = window.URL.createObjectURL(new Blob([response.data as BlobPart], { type: 'text/csv' }));
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `smartolt-${dataset}-${Date.now()}.csv`);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  },
};
