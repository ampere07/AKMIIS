import React, { useState, useEffect, useRef } from 'react';
import { View, Alert, DeviceEventEmitter } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Linking from 'expo-linking';
import { StatusBar } from 'expo-status-bar';
import Login from './src/pages/Login';
import Dashboard from './src/pages/Dashboard';
import { UserData } from './src/types/api';
import { initializeCsrf, loadCookies, clearCookies, SESSION_EXPIRED_EVENT } from './src/config/api';
import { isCustomerAccount } from './src/config/customer';
import { revokeToken } from './src/services/api';
import { settingsColorPaletteService } from './src/services/settingsColorPaletteService';
import { getAppVersionConfig, isIosUpdateRequired, appStoreUrl, AppVersionConfig } from './src/services/appVersionService';
import PaymentResultModal from './src/components/PaymentResultModal';
import SplashScreen from './src/components/SplashScreen';
import ErrorBoundary from './src/components/ErrorBoundary';
import ForceUpdateModal from './src/modals/ForceUpdateModal';
import { version as currentVersion } from './package.json';

const STAFF_SESSION_NOTICE = 'This app is for AKM customer accounts only. Please sign in with your account number.';

function App() {
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isLoggingIn, setIsLoggingIn] = useState(false);
  const [loginNotice, setLoginNotice] = useState<string | undefined>(undefined);
  const [showUpdateModal, setShowUpdateModal] = useState(false);
  const [versionConfig, setVersionConfig] = useState<AppVersionConfig | null>(null);
  const [showPaymentResult, setShowPaymentResult] = useState(false);
  const [paymentSuccess, setPaymentSuccess] = useState(false);
  const [paymentRef, setPaymentRef] = useState('');

  const isLoggedInRef = useRef(false);
  useEffect(() => { isLoggedInRef.current = isLoggedIn; }, [isLoggedIn]);

  const sessionExpiredShownRef = useRef(false);

  const handleLogout = async () => {
    await AsyncStorage.removeItem('authData');
    await AsyncStorage.removeItem('authToken');
    await clearCookies();
    setIsLoggedIn(false);
    sessionExpiredShownRef.current = false;
  };

  useEffect(() => {
    const subscription = DeviceEventEmitter.addListener(SESSION_EXPIRED_EVENT, () => {
      if (!isLoggedInRef.current || sessionExpiredShownRef.current) return;
      sessionExpiredShownRef.current = true;

      Alert.alert(
        'Session Expired',
        'Please sign in again to continue.',
        [{ text: 'Re-login', onPress: () => { void handleLogout(); } }],
      );
    });

    return () => subscription.remove();
  }, []);

  useEffect(() => {
    const initialize = async () => {
      try {
        const url = await Linking.getInitialURL();
        if (url) {
          const { queryParams } = Linking.parse(url);
          if (queryParams?.payment && queryParams?.ref) {
            setPaymentSuccess(queryParams.payment === 'success');
            setPaymentRef(queryParams.ref as string);
            setShowPaymentResult(true);
          }
        }
      } catch (e) {
        console.error('Failed to parse linking URL:', e);
      }

      try {
        const config = await getAppVersionConfig();
        setVersionConfig(config);

        if (isIosUpdateRequired(config, currentVersion) && appStoreUrl(config)) {
          setShowUpdateModal(true);
        }
      } catch (error) {
        console.error('Failed to check app version:', error);
      }

      try {
        await loadCookies();
        await initializeCsrf();
      } catch (error) {
        console.error('Failed to initialize CSRF or load cookies:', error);
      }

      try {
        await settingsColorPaletteService.getActive();
      } catch (error) {
        console.error('Failed to preload color palette:', error);
      }

      try {
        const authData = await AsyncStorage.getItem('authData');
        if (authData) {
          const parsedUser = JSON.parse(authData);

          if (isCustomerAccount(parsedUser)) {
            setIsLoggedIn(true);
          } else {
            const token = await AsyncStorage.getItem('authToken');
            if (token) void revokeToken(token);
            await handleLogout();
            setLoginNotice(STAFF_SESSION_NOTICE);
          }
        }
      } catch (error) {
        console.error('Error parsing auth data:', error);
        await AsyncStorage.removeItem('authData');
      }

      setIsLoading(false);
    };

    initialize();
  }, []);

  const handleLogin = async (user: UserData) => {
    setIsLoggingIn(true);
    setLoginNotice(undefined);

    try {
      await AsyncStorage.setItem('authData', JSON.stringify(user));
      await AsyncStorage.setItem('theme', 'light');
      await new Promise(resolve => setTimeout(resolve, 600));
    } catch (e) {
      console.error('Login error:', e);
    }

    setIsLoggingIn(false);
    setIsLoggedIn(true);
  };

  if (isLoading || isLoggingIn) {
    return <SplashScreen />;
  }

  return (
    <SafeAreaProvider>
      <ErrorBoundary resetKey={isLoggedIn ? 'in' : 'out'}>
        <View style={{ flex: 1 }}>
          <StatusBar hidden={true} />
          {isLoggedIn ? (
            <>
              <Dashboard onLogout={handleLogout} />
              <PaymentResultModal
                isOpen={showPaymentResult}
                onClose={() => setShowPaymentResult(false)}
                success={paymentSuccess}
                referenceNo={paymentRef}
                isDarkMode={false}
              />
            </>
          ) : (
            <Login onLogin={handleLogin} notice={loginNotice} />
          )}

          {versionConfig && (
            <ForceUpdateModal
              visible={showUpdateModal}
              storeUrl={appStoreUrl(versionConfig)}
              latestVersion={versionConfig.ios_latest_version || versionConfig.ios_min_version || ''}
              isForce={true}
              onClose={() => setShowUpdateModal(false)}
            />
          )}
        </View>
      </ErrorBoundary>
    </SafeAreaProvider>
  );
}

export default App;
