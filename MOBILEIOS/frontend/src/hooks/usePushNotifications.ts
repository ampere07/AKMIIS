import { useState, useEffect, useRef } from 'react';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import Constants from 'expo-constants';
import apiClient from '../config/api';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
    shouldShowBanner: true,
    shouldShowList: true,
  } as Notifications.NotificationBehavior),
});

/**
 * Register this iPhone for push and hand the Expo token to the server.
 *
 * The full app also creates the Android channels its job/service/work order
 * alerts play their sounds through. iOS has no channels — the sound travels in
 * the APNs payload — and those alerts go to technicians, not customers, so
 * there is nothing to set up before asking for permission.
 *
 * The token is the same Expo push token the backend already sends to
 * (PushNotificationService), because this app uses the same EAS project.
 */
export function usePushNotifications() {
  const [expoPushToken, setExpoPushToken] = useState<string>('');
  const [notification, setNotification] = useState<Notifications.Notification | false>(false);
  const notificationListener = useRef<Notifications.Subscription | null>(null);
  const responseListener = useRef<Notifications.Subscription | null>(null);

  async function registerForPushNotificationsAsync() {
    let token = '';

    if (Device.isDevice) {
      const { status: existingStatus } = await Notifications.getPermissionsAsync();
      let finalStatus = existingStatus;
      if (existingStatus !== 'granted') {
        const { status } = await Notifications.requestPermissionsAsync();
        finalStatus = status;
      }
      if (finalStatus !== 'granted') {
        console.log('Failed to get push token for push notification!');
        return;
      }

      const projectId =
        Constants?.expoConfig?.extra?.eas?.projectId ?? Constants?.easConfig?.projectId;

      try {
        if (!projectId) {
          throw new Error('Project ID not found in app.json');
        }
        token = (await Notifications.getExpoPushTokenAsync({
          projectId,
        })).data;
        console.log('Push token:', token);
        setExpoPushToken(token);

        // Send token to backend
        try {
          await apiClient.post('/users/push-token', { push_token: token });
          console.log('Push token successfully sent to backend');
        } catch (error) {
          console.error('Failed to send push token to backend:', error);
        }
      } catch (e) {
        token = `${e}`;
        console.error('Error getting push token:', e);
      }
    } else {
      // The iOS Simulator cannot receive remote notifications.
      console.log('Must use physical device for Push Notifications');
    }

    return token;
  }

  useEffect(() => {
    registerForPushNotificationsAsync().then((token) => {
      if (token) setExpoPushToken(token);
    });

    notificationListener.current = Notifications.addNotificationReceivedListener((notification) => {
      setNotification(notification);
    });

    responseListener.current = Notifications.addNotificationResponseReceivedListener((response) => {
      console.log('User interacted with notification:', response);
    });

    return () => {
      if (notificationListener.current) {
        notificationListener.current.remove();
      }
      if (responseListener.current) {
        responseListener.current.remove();
      }
    };
  }, []);

  return {
    expoPushToken,
    notification,
  };
}
