import axios from 'axios';

const getCookie = (name: string): string | null => {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
  return match ? decodeURIComponent(match[2]) : null;
};

/**
 * Read the Sanctum bearer token stored at login.
 *
 * This is the primary authentication mechanism: it is sent as an `Authorization: Bearer`
 * header and does NOT depend on cookies, so it works in embedded in-app browsers
 * (Facebook Messenger, Instagram, etc.) that block third-party cookie storage.
 */
const getAuthToken = (): string | null => {
  try {
    const raw = localStorage.getItem('authData');
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return parsed?.token || null;
  } catch {
    return null;
  }
};

/**
 * Detect embedded / in-app WebViews (Facebook, Messenger, Instagram, Line, WeChat, generic
 * Android WebView, etc.). These environments block third-party cookies, so cookie/session and
 * CSRF-cookie based auth cannot work there — we rely on the bearer token instead.
 */
export const isEmbeddedBrowser = (): boolean => {
  if (typeof navigator === 'undefined') return false;
  const ua = navigator.userAgent || '';
  return /FBAN|FBAV|FB_IAB|FBIOS|Messenger|Instagram|Line\/|MicroMessenger|Twitter|; wv\)|WebView/i.test(ua);
};

const API_BASE_URL = process.env.REACT_APP_API_BASE_URL as string;

if (!API_BASE_URL) {
  throw new Error('REACT_APP_API_BASE_URL must be defined in .env file');
}

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  // Still send cookies when the browser allows it (first-party browsers keep their session),
  // but auth no longer depends on them — the bearer token below is authoritative.
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

  // Pointless in embedded browsers: the CSRF cookie cannot be stored, and api/* routes are
  // CSRF-exempt on the backend anyway. Skip so we never block startup there.
  if (isEmbeddedBrowser()) {
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
    // 1) Attach the bearer token — primary, cookie-independent authentication.
    const token = getAuthToken();
    if (token) {
      config.headers = config.headers || {};
      if (!config.headers['Authorization']) {
        config.headers['Authorization'] = `Bearer ${token}`;
      }
    }

    const method = config.method?.toUpperCase();
    const requiresCsrf = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method || '');

    // 2) CSRF cookie flow is best-effort and only relevant to first-party, cookie-based
    //    sessions. It must NEVER block the request: api/* routes are CSRF-exempt on the
    //    backend, and token-authenticated / embedded-browser requests don't use it at all.
    if (requiresCsrf && !token && !isEmbeddedBrowser() && !csrfInitialized) {
      try {
        await initializeCsrf();
      } catch (e) {
        console.warn('[API] CSRF init skipped (non-fatal):', e);
      }
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

      // Handle CSRF expiration (only reachable for cookie-based first-party sessions).
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

      // Handle Session/Token expiration (401)
      if (status === 401) {
        console.warn('[API] Unauthorized (401). Triggering session expiration modal...', {
          embedded: isEmbeddedBrowser(),
          hasToken: !!getAuthToken(),
          url: error.config?.url,
        });
        // Dispatch custom event so App.tsx can show the modal
        window.dispatchEvent(new CustomEvent('auth:session-expired'));
      }
    } else {
      // No response object => network / CORS / TLS failure, or a request blocked by the
      // WebView. This is the classic symptom inside embedded browsers — log rich context so
      // it can be diagnosed from the field instead of guessing.
      console.error('[API] Network/transport error (no HTTP response received).', {
        embedded: isEmbeddedBrowser(),
        url: error.config?.url,
        method: error.config?.method,
        message: error.message,
        userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : 'unknown',
      });
    }
    return Promise.reject(error);
  }
);

export default apiClient;
export { API_BASE_URL };
