import * as Location from 'expo-location';
import AsyncStorage from '@react-native-async-storage/async-storage';

/**
 * The single gate every location request in the app must pass through.
 *
 * Google Play's User Data policy requires an in-app prominent disclosure immediately
 * before a location runtime permission is requested, anywhere in the app. Rather than
 * trusting each screen to remember that, no screen calls
 * Location.request*PermissionsAsync() directly any more — they call
 * ensureLocationPermission() here, which shows the disclosure first and only then asks
 * the OS.
 *
 * The disclosure itself is rendered by LocationDisclosureHost, mounted once at the root
 * of the app so it is available on every screen and for every role.
 */

/** Remembers that the user has seen the disclosure and made a choice. */
const CONSENT_KEY = 'locationDisclosureConsent';
/**
 * Remembers the answer to the *background* ("Allow all the time") step separately.
 * Android 11+ never shows an in-place prompt for background, so declining it leaves the
 * OS state untouched and `canAskAgain` stays true forever — without our own record we
 * would re-show the background disclosure on every single app launch.
 */
const BG_CONSENT_KEY = 'locationDisclosureBackgroundConsent';

export type LocationConsent = 'granted' | 'declined';
export type DisclosureStage = 'disclosure' | 'background';

type ShowDisclosure = (stage: DisclosureStage) => Promise<boolean>;

let showDisclosure: ShowDisclosure | null = null;

/** Called by LocationDisclosureHost on mount. */
export function registerDisclosureHost(fn: ShowDisclosure | null): void {
    showDisclosure = fn;
}

export async function getStoredConsent(key: string = CONSENT_KEY): Promise<LocationConsent | null> {
    try {
        const value = await AsyncStorage.getItem(key);
        return value === 'granted' || value === 'declined' ? value : null;
    } catch {
        return null;
    }
}

async function setStoredConsent(value: LocationConsent, key: string = CONSENT_KEY): Promise<void> {
    try {
        await AsyncStorage.setItem(key, value);
    } catch {
        // Non-fatal: the user is simply asked again next time.
    }
}

/** Clears both consent records so the disclosure is shown afresh (e.g. on logout). */
export async function resetStoredConsent(): Promise<void> {
    try {
        await AsyncStorage.multiRemove([CONSENT_KEY, BG_CONSENT_KEY]);
    } catch {
        // Non-fatal.
    }
}

interface EnsureOptions {
    /**
     * Also ask for background ("Allow all the time") permission. Only the technician
     * duty-tracking flow needs this; a one-off map lookup does not.
     */
    background?: boolean;
    /**
     * Show the disclosure again to someone who declined before. True for anything the
     * user explicitly initiated — pressing a locate button is a clear request, so
     * re-asking is appropriate. False for automatic flows, which must not nag.
     */
    reAskIfDeclined?: boolean;
}

/**
 * Ensures foreground location permission, showing the disclosure first when needed.
 *
 * @returns true when foreground permission is granted and location may be read.
 */
export async function ensureLocationPermission(options: EnsureOptions = {}): Promise<boolean> {
    const { background = false, reAskIfDeclined = true } = options;

    try {
        const current = await Location.getForegroundPermissionsAsync();

        // Already granted: the disclosure was shown before this was granted, so there is
        // nothing to disclose again for foreground use.
        if (current.status === 'granted') {
            if (background) await ensureBackgroundPermission(reAskIfDeclined);
            return true;
        }

        // Permanently denied at OS level — asking again would do nothing, and the OS
        // will not show a prompt, so there is no request to precede with a disclosure.
        if (!current.canAskAgain) return false;

        const stored = await getStoredConsent();
        if (stored === 'declined' && !reAskIfDeclined) return false;

        // No disclosure host mounted means we cannot disclose, and without a disclosure
        // we must not request. Failing closed keeps the app compliant.
        if (!showDisclosure) return false;

        const accepted = await showDisclosure('disclosure');
        if (!accepted) {
            await setStoredConsent('declined');
            return false;
        }

        await setStoredConsent('granted');

        // Consent given — now, and only now, ask the OS.
        const result = await Location.requestForegroundPermissionsAsync();
        if (result.status !== 'granted') return false;

        if (background) await ensureBackgroundPermission(reAskIfDeclined);
        return true;
    } catch {
        return false;
    }
}

/**
 * Requests background location, explaining the OS settings screen first.
 *
 * Android 11+ does not show an in-place prompt for this — it sends the user to a system
 * settings page — so without a lead-in most people never find "Allow all the time".
 *
 * @param reAskIfDeclined Show the background disclosure again to someone who already
 *   declined it. False for automatic flows (app launch), so they do not nag; true when
 *   the user explicitly asked for something that needs background location.
 * @returns true when background permission ends up granted.
 */
export async function ensureBackgroundPermission(reAskIfDeclined = true): Promise<boolean> {
    try {
        const current = await Location.getBackgroundPermissionsAsync();
        if (current.status === 'granted') return true;
        if (!current.canAskAgain) return false;

        const stored = await getStoredConsent(BG_CONSENT_KEY);
        if (stored === 'declined' && !reAskIfDeclined) return false;

        if (showDisclosure) {
            const proceed = await showDisclosure('background');
            // Declining the background step is not a refusal of location altogether;
            // the foreground grant already given stays in force.
            if (!proceed) {
                await setStoredConsent('declined', BG_CONSENT_KEY);
                return false;
            }
        }

        const result = await Location.requestBackgroundPermissionsAsync();
        const granted = result.status === 'granted';
        // Record the outcome either way: someone who walked through the settings screen
        // and still did not pick "Allow all the time" has effectively declined.
        await setStoredConsent(granted ? 'granted' : 'declined', BG_CONSENT_KEY);
        return granted;
    } catch {
        return false;
    }
}
