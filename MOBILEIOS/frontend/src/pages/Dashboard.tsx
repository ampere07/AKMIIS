import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { View, Linking, Pressable, DeviceEventEmitter } from 'react-native';
import * as WebBrowser from 'expo-web-browser';
import MaterialCommunityIcons from '@expo/vector-icons/MaterialCommunityIcons';
import DashboardCustomer from './DashboardCustomer';
import Bills from './Bills';
import Support from './Support';
import Menu from './Menu';
import ReleaseNotes from './ReleaseNotes';
import Sidebar from './Sidebar';
import { CustomerDataProvider } from '../contexts/CustomerDataContext';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { usePushNotifications } from '../hooks/usePushNotifications';
import { useTabBarOffset } from '../hooks/useTabBarOffset';
import ErrorBoundary from '../components/ErrorBoundary';

interface DashboardProps {
    onLogout: () => void;
}

type BillsTab = 'soa' | 'invoices' | 'payments';

/**
 * The signed-in customer's app: one section on screen and the tab bar under it.
 *
 * The full app's Dashboard routes every role's sections through a permission
 * guard. Only customers can sign in here (see Login), and each of these
 * sections is one a customer holds — customer-dashboard, customer-bills and
 * customer-support, plus the menu and release notes, which need none — so the
 * guard has nothing left to refuse and is not carried over.
 */
const Dashboard: React.FC<DashboardProps> = ({ onLogout }) => {
    usePushNotifications();
    const [activeSection, setActiveSection] = useState('customer-dashboard');
    const [billsInitialTab, setBillsInitialTab] = useState<BillsTab>('soa');
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(() => settingsColorPaletteService.getActiveSync());
    const { lift } = useTabBarOffset();

    useEffect(() => {
        settingsColorPaletteService.getActive()
            .then(setColorPalette)
            .catch((err) => console.error('Failed to fetch color palette:', err));

        const paletteSub = DeviceEventEmitter.addListener('colorPaletteChanged', (newPalette) => {
            setColorPalette(newPalette);
        });

        return () => paletteSub.remove();
    }, []);

    const handleSectionChange = useCallback((section: string, extra?: string) => {
        setActiveSection(section);
        if (section === 'customer-bills') {
            setBillsInitialTab((extra as BillsTab) || 'soa');
        }
    }, []);

    const content = useMemo(() => {
        switch (activeSection) {
            case 'customer-bills':
                return <Bills initialTab={billsInitialTab} />;
            case 'customer-support':
                return <Support />;
            case 'menu':
                return <Menu onLogout={onLogout} onSectionChange={handleSectionChange} />;
            case 'release-notes':
                return <ReleaseNotes onBack={() => handleSectionChange('menu')} />;
            case 'customer-dashboard':
            default:
                return <DashboardCustomer onNavigate={(section, tab) => handleSectionChange(section, tab)} />;
        }
    }, [activeSection, billsInitialTab, onLogout, handleSectionChange]);

    const handleOpenChat = async () => {
        const webUrl = 'https://m.me/akmiis';
        const messengerAppUrl = 'fb-messenger://user-thread/';
        try {
            // fb-messenger is declared in LSApplicationQueriesSchemes (app.json);
            // without that, iOS answers false here whether Messenger is installed or not.
            const canOpenMessenger = await Linking.canOpenURL(messengerAppUrl);
            if (canOpenMessenger) {
                await Linking.openURL(webUrl);
            } else {
                await WebBrowser.openBrowserAsync(webUrl);
            }
        } catch (error) {
            await WebBrowser.openBrowserAsync(webUrl);
        }
    };

    // Release notes is a full screen with its own back button.
    const showSidebar = activeSection !== 'release-notes';

    return (
        <CustomerDataProvider>
            <View style={{ flex: 1, overflow: 'hidden', backgroundColor: '#f9fafb' }}>
                {/* Main Content Area */}
                <View style={{ flex: 1 }}>
                    <ErrorBoundary resetKey={activeSection} sectionName={activeSection}>
                        {content}
                    </ErrorBoundary>
                </View>

                {/* Bottom Navigation Bar */}
                {showSidebar && (
                    <Sidebar
                        activeSection={activeSection}
                        onSectionChange={handleSectionChange}
                    />
                )}

                {/* Persistent Floating Messenger Button */}
                {activeSection !== 'menu' && (
                    <View style={{
                        position: 'absolute',
                        bottom: 110 + lift,
                        left: 20,
                        zIndex: 99999,
                    }}>
                        <Pressable
                            onPress={handleOpenChat}
                            accessibilityRole="button"
                            accessibilityLabel="Chat with AKM on Messenger"
                            style={({ pressed }) => ({
                                transform: [{ scale: pressed ? 0.9 : 1 }],
                            })}
                        >
                            <View style={{
                                width: 56,
                                height: 56,
                                borderRadius: 28,
                                backgroundColor: colorPalette?.primary || '#ef4444',
                                justifyContent: 'center',
                                alignItems: 'center',
                                shadowColor: '#000',
                                shadowOffset: { width: 0, height: 4 },
                                shadowOpacity: 0.3,
                                shadowRadius: 5,
                            }}>
                                <MaterialCommunityIcons name="facebook-messenger" size={30} color="#fff" />
                            </View>
                        </Pressable>
                    </View>
                )}
            </View>
        </CustomerDataProvider>
    );
};

export default Dashboard;
