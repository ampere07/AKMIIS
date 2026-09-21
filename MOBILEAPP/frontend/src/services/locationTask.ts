import * as TaskManager from 'expo-task-manager';
import * as Location from 'expo-location';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { technicianLocationService } from './technicianLocationService';

// Cadence for the OS-driven location "cron". Kept well under the backend's
// 2-minute stale window so an active technician stays "online".
export const TECH_LOCATION_TASK = 'akm-tech-location-task';
// Life360-like cadence: report every ~10s. distanceInterval 0 means "report on the
// time interval even when standing still", so a technician stays reliably "online"
// (not just when moving). Trade-off: higher battery use — acceptable for on-duty techs.
const TIME_INTERVAL_MS = 10_000; // ~10s
const DISTANCE_INTERVAL_M = 0;   // 0 = time-based (also fires on movement)

/** How tracking actually ended up running, for logging and for the caller to surface. */
export type TrackingMode = 'background' | 'foreground' | 'off';

/** Foreground-only fallback subscription, used when the OS refuses the background task. */
let foregroundWatch: Location.LocationSubscription | null = null;

function isLoggedInTechnician(user: any): boolean {
  if (!user) return false;
  const role = (user.role || '').toString().toLowerCase();
  const roleId = Number(user.role_id);
  return role === 'technician' || roleId === 2;
}

/**
 * Push one fix to the backend, but only while a technician is still logged in.
 * Shared by the OS background task and the foreground fallback watcher so both
 * paths apply the same auth gate and payload shape.
 */
async function reportCoords(coords: any): Promise<void> {
  const [raw, token] = await Promise.all([
    AsyncStorage.getItem('authData'),
    AsyncStorage.getItem('authToken'),
  ]);
  const user = raw ? JSON.parse(raw) : null;
  if (!token || !isLoggedInTechnician(user)) return;

  const { latitude, longitude, accuracy, speed, heading } = coords;
  await technicianLocationService.updateLocation({
    latitude,
    longitude,
    accuracy: accuracy ?? null,
    speed: speed ?? null,
    heading: heading ?? null,
  });
}

/**
 * Background/foreground location task. The OS delivers batched positions here
 * (even when the app is minimized). Defined at module scope so it is registered
 * whenever the app is launched, including background relaunches.
 */
TaskManager.defineTask(TECH_LOCATION_TASK, async ({ data, error }: any) => {
  if (error) {
    console.warn('[locationTask] OS reported an error:', error?.message ?? error);
    return;
  }
  const locations = data?.locations;
  if (!locations || locations.length === 0) return;

  try {
    await reportCoords(locations[locations.length - 1].coords);
  } catch (e: any) {
    // Network loss / transient error: the next OS location tick will retry.
    console.warn('[locationTask] report failed:', e?.message ?? e);
  }
});

/**
 * Begin continuous location updates (idempotent).
 *
 * Prefers the OS background task, which keeps reporting when the app is minimized.
 * When the OS refuses that — most commonly on iOS, where a technician who picked
 * "While Using the App" instead of "Always" cannot have a background task — we fall
 * back to a foreground watcher so dispatch at least sees them while the app is open,
 * rather than the technician silently never appearing on the map at all.
 *
 * @returns how tracking is actually running, so the caller can log or surface it.
 */
export async function startTechLocationUpdates(): Promise<TrackingMode> {
  const already = await Location.hasStartedLocationUpdatesAsync(TECH_LOCATION_TASK).catch(() => false);
  if (already) return 'background';

  try {
    await Location.startLocationUpdatesAsync(TECH_LOCATION_TASK, {
      accuracy: Location.Accuracy.High,
      timeInterval: TIME_INTERVAL_MS,
      distanceInterval: DISTANCE_INTERVAL_M,
      // Keep delivering even when the device is stationary (iOS pauses by default).
      pausesUpdatesAutomatically: false,
      activityType: Location.ActivityType.Other,
      // iOS: show the blue status bar so the OS keeps the app alive in the background.
      showsBackgroundLocationIndicator: true,
      // Android: a persistent foreground-service notification is what keeps location
      // flowing when the app is backgrounded AND after it is swiped from recents.
      foregroundService: {
        notificationTitle: 'AKM location sharing active',
        notificationBody: 'Sharing your live location with dispatch while you are on duty.',
        notificationColor: '#7c3aed',
        killServiceOnDestroy: false,
      },
    });
    return 'background';
  } catch (e: any) {
    console.warn(
      '[locationTask] background updates unavailable, falling back to foreground-only:',
      e?.message ?? e,
    );
    return (await startForegroundWatch()) ? 'foreground' : 'off';
  }
}

/** Foreground-only reporting: same cadence, but stops when the app is backgrounded. */
async function startForegroundWatch(): Promise<boolean> {
  if (foregroundWatch) return true;
  try {
    foregroundWatch = await Location.watchPositionAsync(
      {
        accuracy: Location.Accuracy.High,
        timeInterval: TIME_INTERVAL_MS,
        distanceInterval: DISTANCE_INTERVAL_M,
      },
      (position) => {
        reportCoords(position.coords).catch(() => {
          // Transient failure: the next tick retries.
        });
      },
    );
    return true;
  } catch (e: any) {
    console.warn('[locationTask] foreground watch failed:', e?.message ?? e);
    foregroundWatch = null;
    return false;
  }
}

/** Stop location updates (idempotent). Called on logout. */
export async function stopTechLocationUpdates(): Promise<void> {
  if (foregroundWatch) {
    foregroundWatch.remove();
    foregroundWatch = null;
  }

  const already = await Location.hasStartedLocationUpdatesAsync(TECH_LOCATION_TASK).catch(() => false);
  if (already) {
    await Location.stopLocationUpdatesAsync(TECH_LOCATION_TASK).catch(() => {});
  }
}
