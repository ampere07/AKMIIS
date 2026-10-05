import apiClient from '../config/api';
import { LoginResponse, ForgotPasswordResponse } from '../types/api';

export const login = async (email: string, password: string): Promise<LoginResponse> => {
  const response = await apiClient.post<LoginResponse>('/login', {
    email,
    password
  });
  return response.data;
};

export const forgotPassword = async (account_no: string): Promise<ForgotPasswordResponse> => {
  const response = await apiClient.post<ForgotPasswordResponse>('/forgot-password', {
    account_no
  });
  return response.data;
};

/**
 * Revoke a token that was issued but will not be kept.
 *
 * Used when a staff account signs in to this customer-only app: the server has
 * already minted a personal access token by the time the role can be checked,
 * and leaving it alive would strand a valid credential nobody holds. Sent with
 * the token explicitly because it is never written to storage, so the request
 * interceptor has nothing of its own to attach.
 *
 * Best effort — a failure here costs nothing the user can see.
 */
export const revokeToken = async (token: string): Promise<void> => {
  try {
    await apiClient.post('/logout', {}, {
      headers: {
        Authorization: `Bearer ${token}`,
        'X-Auth-Token': token,
      },
    });
  } catch {
    // The token simply lives out its natural life on the server.
  }
};
