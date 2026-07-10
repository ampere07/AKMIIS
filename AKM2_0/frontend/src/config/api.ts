import axios from 'axios';

const getCookie = (name: string): string | null => {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
  return match ? decodeURIComponent(match[2]) : null;
};

const API_BASE_URL = process.env.REACT_APP_API_BASE_URL as string;

if (!API_BASE_URL) {
  throw new Error('REACT_APP_API_BASE_URL must be defined in .env file');
}

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
  timeout: 60000,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

let csrfInitialized = false;

let csrfInitializationPromise: Promise<void> | null = null;

export const initializeCsrf = async (): Promise<void> => {
  if (csrfInitialized) {
    return;
  }

  if (csrfInitializationPromise) {
    return csrfInitializationPromise;
  }

  csrfInitializationPromise = (async () => {
    try {
      const baseUrl = API_BASE_URL.replace(/\/api$/, '');
      await axios.get(`${baseUrl}/sanctum/csrf-cookie`, {
        withCredentials: true,
      });
      csrfInitialized = true;
    } catch (error) {
      console.error('CSRF Initialization failed:', error);
      throw error;
    } finally {
      csrfInitializationPromise = null;
    }
  })();

  return csrfInitializationPromise;
};

apiClient.interceptors.request.use(
  async (config: any) => {
    const method = config.method?.toUpperCase();
    const requiresCsrf = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method || '');

    if (requiresCsrf && !csrfInitialized) {
      await initializeCsrf();
    }

    const xsrfToken = getCookie('XSRF-TOKEN');
    if (xsrfToken && requiresCsrf) {
      config.headers = config.headers || {};
      config.headers['X-XSRF-TOKEN'] = xsrfToken;
    }

    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

apiClient.interceptors.response.use(
  (response) => {
    return response;
  },
  async (error) => {
    if (error.response) {
      const status = error.response.status;
      
      // Handle CSRF expiration
      if (status === 419) {
        csrfInitialized = false;
        try {
          await initializeCsrf();
          const config = error.config;
          config.headers['X-XSRF-TOKEN'] = getCookie('XSRF-TOKEN') || '';
          return apiClient(config);
        } catch (retryError) {
          return Promise.reject(retryError);
        }
      }
      
      // Handle Session expiration (401)
      if (status === 401) {
        console.warn('[API] Unauthorized (401). Triggering session expiration modal...');
        // Dispatch custom event so App.tsx can show the modal
        window.dispatchEvent(new CustomEvent('auth:session-expired'));
      }
    }
    return Promise.reject(error);
  }
);

export default apiClient;
export { API_BASE_URL };
