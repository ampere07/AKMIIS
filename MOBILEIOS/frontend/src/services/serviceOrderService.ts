import apiClient from '../config/api';

interface ApiResponse<T> {
  success: boolean;
  data: T;
  message?: string;
}

/**
 * The fields a customer sets when raising a support ticket.
 *
 * A service order carries far more than this — assignment, visit outcome,
 * replacement equipment — but all of that is written by staff. The customer
 * app only ever creates the ticket and reads it back through
 * `/service-orders/by-account/{accountNo}` (see CustomerDataContext).
 */
export interface NewServiceOrder {
  account_no: string;
  concern: string;
  concern_remarks: string;
  created_by_user: string;
  requested_by: string;
  support_status: string;
  image4?: string;
}

export const createServiceOrder = async (serviceOrderData: NewServiceOrder) => {
  try {
    const response = await apiClient.post<ApiResponse<unknown>>('/service-orders', serviceOrderData);
    return response.data;
  } catch (error) {
    console.error('Error creating service order:', error);
    throw error;
  }
};
