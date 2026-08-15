import apiClient from '../config/api';

/** One referred customer billed on an agent invoice. */
export interface AgentInvoiceCustomer {
    id: number;
    application_id: number;
    job_order_id: number | null;
    customer_name: string;
    referred_by_agent_id: number | null;
    referred_by_name: string | null;
    installed_date: string | null;
    unit_price: number;
    quantity: number;
    total: number;
}

/** One week's referral invoice for a team or a solo agent. */
export interface AgentInvoiceRecord {
    id: number;
    invoice_number: string;
    invoice_type: 'team' | 'solo';
    team_id: number | null;
    team_name: string | null;
    agent_id: number | null;
    agent_name: string | null;
    billed_to: string;
    invoice_date: string | null;
    period_start: string | null;
    period_end: string | null;
    total_customers: number;
    unit_price: number;
    installation_fee: number;
    total_amount: number;
    commission: number;
    subtotal: number;
    status: string;
    has_pdf: boolean;
    created_at: string | null;
    customers?: AgentInvoiceCustomer[];
}

export interface AgentInvoiceListParams {
    search?: string;
    status?: string;
    type?: string;
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
}

/**
 * Reads the weekly agent referral invoices.
 *
 * Every endpoint is scoped server side to what the signed-in user may see, so
 * nothing here filters by agent or team — asking for an invoice that is not
 * theirs returns a 404 rather than somebody else's data.
 */
export const agentInvoiceService = {
    async list(params: AgentInvoiceListParams = {}) {
        const query = new URLSearchParams();

        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                query.append(key, String(value));
            }
        });

        const response = await apiClient.get(`/agent-invoices?${query.toString()}`);
        return response.data as {
            success: boolean;
            data: AgentInvoiceRecord[];
            meta: { current_page: number; last_page: number; per_page: number; total: number };
        };
    },

    async get(id: number) {
        const response = await apiClient.get(`/agent-invoices/${id}`);
        return response.data as { success: boolean; data: AgentInvoiceRecord };
    },

    /**
     * The PDF as a blob, for viewing in a tab or saving.
     *
     * Fetched through the API client so the request carries the session — the
     * stored file is not reachable without one.
     */
    async pdfBlob(id: number, download = false): Promise<Blob> {
        const response = await apiClient.get(`/agent-invoices/${id}/pdf${download ? '?download=1' : ''}`, {
            responseType: 'blob',
        });
        return response.data as Blob;
    },

    async updateStatus(id: number, status: string) {
        const response = await apiClient.patch(`/agent-invoices/${id}/status`, { status });
        return response.data;
    },

    /** Runs the weekly generation now. Administrators only; safe to repeat. */
    async generate(week?: string) {
        const response = await apiClient.post('/agent-invoices/generate', week ? { week } : {});
        return response.data as { success: boolean; message?: string; data?: Record<string, unknown> };
    },
};
