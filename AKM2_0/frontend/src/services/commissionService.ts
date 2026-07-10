import apiClient from '../config/api';

export const commissionService = {
    getEarnings: async (limit = 2000, offset = 0, updatedAfter?: string) => {
        const params = new URLSearchParams({
            limit: limit.toString(),
            offset: offset.toString(),
        });
        if (updatedAfter) params.append('updated_after', updatedAfter);
        
        const response = await apiClient.get(`/commissions?${params.toString()}`);
        return response.data;
    },

    getPayoutHistory: async (limit = 2000, offset = 0, updatedAfter?: string) => {
        const params = new URLSearchParams({
            limit: limit.toString(),
            offset: offset.toString(),
        });
        if (updatedAfter) params.append('updated_after', updatedAfter);

        const response = await apiClient.get(`/commissions/history?${params.toString()}`);
        return response.data;
    },

    // Auto-awarded quota incentives from the agent_incentive_history table (cron output).
    getIncentiveHistory: async (limit = 2000, offset = 0, updatedAfter?: string) => {
        const params = new URLSearchParams({
            limit: limit.toString(),
            offset: offset.toString(),
        });
        if (updatedAfter) params.append('updated_after', updatedAfter);

        const response = await apiClient.get(`/commissions/incentive-history?${params.toString()}`);
        return response.data;
    },

    // Manual bonus transactions (add / payout) from the agent_bonus_history table.
    getBonusHistory: async (limit = 2000, offset = 0, updatedAfter?: string) => {
        const params = new URLSearchParams({
            limit: limit.toString(),
            offset: offset.toString(),
        });
        if (updatedAfter) params.append('updated_after', updatedAfter);

        const response = await apiClient.get(`/commissions/bonus-history?${params.toString()}`);
        return response.data;
    }
};
