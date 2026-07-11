import { useEffect } from 'react';
import * as Location from 'expo-location';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { startTechLocationUpdates, stopTechLocationUpdates } from '../services/locationTask';

function isTechnician(user: any): boolean {
  if (!user) return false;
  const role = (user.role || '').toString().toLowerCase();
  const roleId = Number(user.role_id);
  return role === 'technician' || roleId === 2;
}

/**
 * Starts continuous GPS reporting for the logged-in technician and keeps it
 * running while the app is open — including in the background — via the OS
 * location task (see services/locationTask.ts). Location permission is only
 * ever requested for a logged-in technician. Tracking stops on logout (unmount).
 */
export function useLocationTracking() {
  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const raw = await AsyncStorage.getItem('authData');
        const user = raw ? JSON.parse(raw) : null;

        // Permission prompt + tracking are strictly technician-only.
        if (!isTechnician(user)) return;

        const fg = await Location.requestForegroundPermissionsAsync();
        if (fg.status !== 'granted') return;

        // Background permission lets updates continue when the app is minimized.
        // If denied, tracking still works while the app is in the foreground.
        await Location.requestBackgroundPermissionsAsync().catch(() => null);

        if (cancelled) return;
        await startTechLocationUpdates();
      } catch {
        // Setup failure -> tracking simply stays off.
      }
    })();

    return () => {
      cancelled = true;
      // Stop when the technician logs out (Dashboard unmounts).
      stopTechLocationUpdates();
    };
  }, []);
}
