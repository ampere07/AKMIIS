import Constants from 'expo-constants';
import apiClient from '../config/api';

export interface AppVersionConfig {
  latest_version: string;
  min_version: string;
  playstore_url: string;
  /**
   * The iOS release line, when the server publishes one.
   *
   * latest_version / min_version above describe the Android build on the Play
   * Store. The iOS app ships on its own schedule, so it must not be held to
   * them: an Android release that raised min_version would otherwise lock every
   * iPhone out behind an update that does not exist on the App Store yet.
   */
  ios_latest_version?: string;
  ios_min_version?: string;
  appstore_url?: string;
}

export interface AppVersionResponse {
  success: boolean;
  data: AppVersionConfig;
}

export const getAppVersionConfig = async (): Promise<AppVersionConfig> => {
  try {
    const response = await apiClient.get<AppVersionResponse>('/app-version/config');
    return response.data.data;
  } catch (error) {
    console.error('Error fetching app version config:', error);
    throw error;
  }
};

/**
 * Compares two semantic version strings.
 * Returns:
 *   1 if v1 > v2
 *  -1 if v1 < v2
 *   0 if v1 === v2
 */
export const compareVersions = (v1: string, v2: string): number => {
  const parts1 = v1.split('.').map(Number);
  const parts2 = v2.split('.').map(Number);

  for (let i = 0; i < Math.max(parts1.length, parts2.length); i++) {
    const p1 = parts1[i] || 0;
    const p2 = parts2[i] || 0;
    if (p1 > p2) return 1;
    if (p1 < p2) return -1;
  }
  return 0;
};

/**
 * Where "Update Now" sends an iPhone: the server's App Store link if it has
 * one, otherwise this app's own listing, opened straight in the App Store app.
 */
export const appStoreUrl = (config?: AppVersionConfig | null): string => {
  if (config?.appstore_url) return config.appstore_url;

  const appStoreId = Constants.expoConfig?.extra?.appStoreId;
  return appStoreId ? `itms-apps://apps.apple.com/app/id${appStoreId}` : '';
};

/**
 * Whether this build is older than the oldest iOS version the server accepts.
 *
 * False when the server names no iOS minimum — see ios_min_version above for
 * why the Android one is deliberately not used in its place.
 */
export const isIosUpdateRequired = (config: AppVersionConfig, currentVersion: string): boolean =>
  !!config.ios_min_version && compareVersions(currentVersion, config.ios_min_version) < 0;
