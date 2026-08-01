import apiClient from '../config/api';
import { 
  LoginResponse, 
  ForgotPasswordResponse, 
  HealthCheckResponse,
  ApplicationsResponse
} from '../types/api';

export const login = async (email: string, password: string): Promise<LoginResponse> => {
  const response = await apiClient.post<LoginResponse>('/login', {
    email,
    password
  });
  return response.data;
};

export const forgotPassword = async (email: string): Promise<ForgotPasswordResponse> => {
  const response = await apiClient.post<ForgotPasswordResponse>('/forgot-password', {
    email
  });
  return response.data;
};

// Best-effort logout: revokes the current Sanctum bearer token (and clears the web session).
// Failures are swallowed so the client always logs out locally even if the network call fails
// (e.g. offline, or a blocked request inside an embedded browser).
export const logout = async (): Promise<void> => {
  try {
    await apiClient.post('/logout');
  } catch (error) {
    console.warn('[API] Logout request failed (continuing local logout):', error);
  }
};

export const healthCheck = async (): Promise<HealthCheckResponse> => {
  const response = await apiClient.get<HealthCheckResponse>('/health');
  return response.data;
};

export const fetchApplications = async (): Promise<ApplicationsResponse> => {
  try {
    const response = await apiClient.get<ApplicationsResponse>('/applications');
    return response.data;
  } catch (error) {
    console.error('Error fetching applications:', error);
    throw error;
  }
};
