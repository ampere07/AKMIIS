import { useEffect } from 'react';
import { AppState, AppStateStatus } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { startTechLocationUpdates, stopTechLocationUpdates } from '../services/locationTask';
import { ensureLocationPermission } from '../services/locationConsent';

function isTechnician(user: any): boolean {
  if (!user) return false;
  const role = (user.role || '').toString().toLowerCase();
  const roleId = Number(user.role_id);
  return role === 'technician' || roleId === 2;
}

/**
 * Starts continuous GPS reporting for the logged-in technician and keeps it running —
 * including in the background — via the OS location task (see services/locationTask.ts).
 *
 * Permission is obtained through ensureLocationPermission(), which shows the in-app
 * prominent disclosure before asking the OS, as Google Play's User Data policy requires.
 * This hook never calls Location.request*PermissionsAsync() itself.
 *
 * Tracking is technician-only and stops on logout (unmount).
 */
export function useLocationTracking() {
  useEffect(() => {
    let cancelled = false;
    let isTech = false;

    const start = async () => {
      // background: duty tracking has to keep reporting when the app is minimised.
      // reAskIfDeclined: false — this runs automatically, so someone who already said
      // no is not asked again on every launch.
      const granted = await ensureLocationPermission({
        background: true,
        reAskIfDeclined: false,
      });

      if (!granted) {
        console.warn('[useLocationTracking] location permission not granted; tracking off');
        return;
      }
      if (cancelled) return;

      // Starts even if only foreground was granted; background simply extends it.
      const mode = await startTechLocationUpdates();
      if (mode !== 'background') {
        console.warn(`[useLocationTracking] tracking running in "${mode}" mode`);
      }
    };

    (async () => {
      try {
        const raw = await AsyncStorage.getItem('authData');
        const user = raw ? JSON.parse(raw) : null;

        // Disclosure and tracking are strictly technician-only.
        if (!isTechnician(user)) return;
        isTech = true;

        await start();
      } catch (e: any) {
        // Setup failure -> tracking simply stays off, but say so: a silent failure here
        // means the technician never appears on the dispatch map with no clue why.
        console.warn('[useLocationTracking] setup failed:', e?.message ?? e);
      }
    })();

    // Aggressive OEM battery managers (common on Android) kill the foreground service
    // while the app is away, and a technician who grants permission from the OS settings
    // screen never re-enters this effect. Re-checking on resume recovers both cases —
    // startTechLocationUpdates() is idempotent, so this is a no-op when already running.
    const onAppStateChange = (next: AppStateStatus) => {
      if (next !== 'active' || cancelled || !isTech) return;
      start().catch((e: any) =>
        console.warn('[useLocationTracking] resume re-check failed:', e?.message ?? e),
      );
    };
    const subscription = AppState.addEventListener('change', onAppStateChange);

    return () => {
      cancelled = true;
      subscription.remove();
      // Stop when the technician logs out (Dashboard unmounts).
      stopTechLocationUpdates();
    };
  }, []);
}
