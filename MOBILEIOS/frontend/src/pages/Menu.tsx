import React, { useState, useEffect } from 'react';
import { View, Text, ScrollView, Pressable, useWindowDimensions, ActivityIndicator, Image, Modal, StyleSheet } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Bell, Info, Clock } from 'lucide-react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { formUIService } from '../services/formUIService';
import { useCustomerDataContext } from '../contexts/CustomerDataContext';
import { useTabBarOffset } from '../hooks/useTabBarOffset';
import NotificationModal from '../modals/NotificationModal';
import AboutAppModal from '../modals/AboutAppModal';
import packageJson from '../../package.json';
const version = packageJson.version;

interface MenuProps {
    onLogout?: () => void;
    onSectionChange?: (section: string) => void;
}

/**
 * What a customer's menu holds.
 *
 * The full app builds this screen for every role: technicians get Time In/Out,
 * and anyone holding an administrative permission gets the whole admin
 * directory beneath. A customer has never been shown either, so this is the
 * customer's menu exactly as the full app renders it.
 */
const MENU_ITEMS = [
    { id: 'notifications', label: 'Notifications', icon: Bell },
    { id: 'about', label: 'About App', icon: Info },
    { id: 'release-notes', label: 'Release Notes', icon: Clock },
];

const Menu: React.FC<MenuProps> = ({ onLogout, onSectionChange }) => {
    const { height } = useWindowDimensions();
    const isShort = height < 700;
    const { lift } = useTabBarOffset();
    const { customerDetail } = useCustomerDataContext();
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
    const [userData, setUserData] = useState<any>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [logoUrl, setLogoUrl] = useState<string | null>(null);
    const [showLogoutModal, setShowLogoutModal] = useState(false);
    const [showNotificationModal, setShowNotificationModal] = useState(false);
    const [showAboutModal, setShowAboutModal] = useState(false);

    const initials = (customerDetail?.firstName && customerDetail?.lastName)
        ? `${customerDetail.firstName.charAt(0)}${customerDetail.lastName.charAt(0)}`.toUpperCase()
        : (customerDetail?.fullName || 'Customer').split(' ').map((n: any) => n[0]).join('').substring(0, 2).toUpperCase();

    const convertGoogleDriveUrl = (url: string): string => {
        if (!url) return '';
        const apiUrl = process.env.EXPO_PUBLIC_API_BASE_URL || '';
        return `${apiUrl}/proxy/image?url=${encodeURIComponent(url)}`;
    };

    useEffect(() => {
        const initialize = async () => {
            try {
                const [palette, authData] = await Promise.all([
                    settingsColorPaletteService.getActive(),
                    AsyncStorage.getItem('authData')
                ]);
                setColorPalette(palette);
                if (authData) {
                    setUserData(JSON.parse(authData));
                }

                // Fetch Logo
                const config = await formUIService.getConfig();
                if (config && config.logo_url) {
                    setLogoUrl(convertGoogleDriveUrl(config.logo_url));
                }
            } catch (err) {
                console.error('Failed to initialize Menu:', err);
            } finally {
                setIsLoading(false);
            }
        };

        initialize();
    }, []);

    const handleItemPress = (id: string) => {
        if (id === 'notifications') {
            setShowNotificationModal(true);
        } else if (id === 'about') {
            setShowAboutModal(true);
        } else if (id === 'release-notes' && onSectionChange) {
            onSectionChange('release-notes');
        }
    };

    const displayName = customerDetail?.fullName || userData?.full_name || userData?.name || 'User Name';
    const accountNo = customerDetail?.billingAccount?.accountNo || userData?.username || 'username';
    const email = customerDetail?.emailAddress || userData?.email || 'user@example.com';
    const primaryColor = colorPalette?.primary || '#ef4444';

    if (isLoading && !customerDetail) {
        return (
            <View style={s.loadingContainer}>
                <ActivityIndicator size="large" color={primaryColor} />
            </View>
        );
    }

    return (
        <ScrollView style={s.scrollView} showsVerticalScrollIndicator={false}>
            {/* Header Section */}
            <LinearGradient
                colors={[primaryColor, '#000000']}
                start={{ x: 0, y: 0 }}
                end={{ x: 1, y: 1 }}
                style={[s.headerGradient, { paddingTop: isShort ? 30 : 60 }]}
            >
                {/* Logo Section */}
                <View style={[s.logoWrap, { marginBottom: isShort ? 20 : 40 }]}>
                    {logoUrl ? (
                        <Image source={{ uri: logoUrl }} style={s.logoImage} />
                    ) : (
                        <View style={s.logoFallbackRow}>
                            <View style={[s.logoCircle, { backgroundColor: '#ffffff' }]}>
                                <Text style={[s.logoLetter, { color: primaryColor }]}>A</Text>
                            </View>
                            <Text style={s.logoText}>
                                AKM <Text style={s.logoTextBold}>PORTAL</Text>
                            </Text>
                        </View>
                    )}
                </View>

                {/* Profile */}
                <View style={s.balanceCard}>
                    <View style={[s.profileRow, { marginBottom: isShort ? 12 : 24 }]}>
                        <View style={[s.initialsCircle, { width: isShort ? 56 : 64, height: isShort ? 56 : 64, borderRadius: isShort ? 28 : 32 }]}>
                            <Text style={[s.initialsText, { fontSize: isShort ? 20 : 24 }]}>{initials}</Text>
                        </View>
                        <View style={{ alignItems: 'center' }}>
                            <Text style={[s.customerNameText, { fontSize: isShort ? 18 : 22, textAlign: 'center' }]}>{displayName}</Text>
                            <Text style={[s.customerAccountText, { textAlign: 'center', marginTop: 4 }]}>Account No: {accountNo}</Text>
                            <Text style={[s.customerEmailText, { textAlign: 'center', marginTop: 2 }]}>{email}</Text>
                        </View>
                    </View>
                </View>
            </LinearGradient>

            {/* Menu Group. The bottom padding clears the floating tab bar, which
                otherwise sat over the version line at the end of the page. */}
            <View style={[s.menuContainer, { paddingBottom: 120 + lift }]}>
                <View style={s.groupWrap}>
                    <Text style={s.groupTitle}>Menu</Text>
                    <View style={s.groupCard}>
                        {MENU_ITEMS.map((item, itemIndex) => (
                            <React.Fragment key={item.id}>
                                <Pressable accessibilityRole="button"
                                    onPress={() => handleItemPress(item.id)}
                                    style={({ pressed }) => [
                                        s.menuItem,
                                        { backgroundColor: pressed ? '#f9fafb' : '#ffffff' }
                                    ]}
                                >
                                    <View style={s.menuItemLeft}>
                                        <View style={s.menuIconWrap}>
                                            <item.icon size={20} color="#4b5563" />
                                        </View>
                                        <Text style={s.menuItemLabel}>{item.label}</Text>
                                    </View>
                                </Pressable>
                                {itemIndex < MENU_ITEMS.length - 1 && <View style={s.separator} />}
                            </React.Fragment>
                        ))}
                    </View>
                </View>

                {/* Logout Button */}
                <View style={s.logoutWrap}>
                    <View style={[s.logoutBtn, { backgroundColor: primaryColor }]}>
                        <Pressable accessibilityRole="button"
                            onPress={() => setShowLogoutModal(true)}
                            style={({ pressed }) => [s.logoutPressable, { backgroundColor: pressed ? 'rgba(0,0,0,0.1)' : 'transparent' }]}
                        >
                            <Text style={s.logoutText}>Sign out</Text>
                        </Pressable>
                    </View>
                </View>

                <Text style={s.versionText}>Version {version}</Text>
            </View>

            {/* Logout Confirmation Modal */}
            <Modal
                visible={showLogoutModal}
                transparent
                animationType="fade"
                onRequestClose={() => setShowLogoutModal(false)}
            >
                <View style={s.modalOverlay}>
                    <View style={s.modalCard}>
                        <Text style={s.modalTitle}>Sign Out</Text>
                        <Text style={s.modalDesc}>Are you sure you want to sign out of your account?</Text>

                        <View style={s.modalBtns}>
                            <Pressable accessibilityRole="button" onPress={() => setShowLogoutModal(false)} style={s.cancelBtn}>
                                <Text style={s.cancelBtnText}>Cancel</Text>
                            </Pressable>

                            <Pressable accessibilityRole="button"
                                onPress={() => {
                                    setShowLogoutModal(false);
                                    if (onLogout) onLogout();
                                }}
                                style={[s.confirmBtn, { backgroundColor: primaryColor }]}
                            >
                                <Text style={s.confirmBtnText}>Sign Out</Text>
                            </Pressable>
                        </View>
                    </View>
                </View>
            </Modal>

            <NotificationModal
                visible={showNotificationModal}
                onClose={() => setShowNotificationModal(false)}
            />

            <AboutAppModal
                visible={showAboutModal}
                onClose={() => setShowAboutModal(false)}
            />
        </ScrollView>
    );
};

const s = StyleSheet.create({
    loadingContainer: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#f9fafb' },
    scrollView: { flex: 1, backgroundColor: '#f9fafb' },
    headerGradient: { paddingBottom: 30, paddingHorizontal: 24, borderBottomLeftRadius: 32, borderBottomRightRadius: 32 },
    logoWrap: { alignItems: 'center' },
    logoImage: { height: 90, width: 280, resizeMode: 'contain', tintColor: '#ffffff' },
    logoFallbackRow: { flexDirection: 'row', alignItems: 'center' },
    logoCircle: { width: 56, height: 56, borderRadius: 28, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginRight: 16 },
    logoLetter: { fontWeight: 'bold', fontSize: 24 },
    logoText: { color: '#ffffff', fontWeight: 'bold', fontSize: 28, letterSpacing: 0.5 },
    logoTextBold: { fontWeight: '800', color: '#ffffff' },
    balanceCard: { width: '100%' },
    profileRow: { flexDirection: 'column', alignItems: 'center', gap: 16 },
    initialsCircle: { backgroundColor: 'rgba(255, 255, 255, 0.15)', justifyContent: 'center', alignItems: 'center', borderWidth: 2, borderColor: 'rgba(255, 255, 255, 0.3)' },
    initialsText: { color: '#ffffff', fontWeight: 'bold' },
    customerNameText: { color: '#ffffff', fontWeight: 'bold', textTransform: 'capitalize' },
    customerAccountText: { color: '#e5e7eb', fontSize: 13, opacity: 0.9 },
    customerEmailText: { color: 'rgba(255, 255, 255, 0.7)', fontSize: 12 },
    menuContainer: { paddingHorizontal: 20, paddingTop: 24 },
    groupWrap: { marginBottom: 24 },
    groupTitle: { fontSize: 13, fontWeight: '600', color: '#9ca3af', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 12, marginLeft: 4 },
    groupCard: { backgroundColor: '#ffffff', borderRadius: 16, overflow: 'hidden', borderWidth: 1, borderColor: '#f1f5f9' },
    menuItem: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 20, paddingHorizontal: 20 },
    separator: { height: StyleSheet.hairlineWidth, backgroundColor: '#e5e7eb', marginHorizontal: 20 },
    menuItemLeft: { flexDirection: 'row', alignItems: 'center' },
    menuIconWrap: { width: 36, height: 36, borderRadius: 10, justifyContent: 'center', alignItems: 'center' },
    menuItemLabel: { fontSize: 15, fontWeight: '500', color: '#374151', marginLeft: 14 },
    logoutWrap: { width: '100%', alignItems: 'center', marginTop: 10 },
    logoutBtn: { borderRadius: 24, width: '60%', height: 48, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.1, shadowRadius: 4, overflow: 'hidden' },
    logoutPressable: { width: '100%', height: '100%' },
    logoutText: { color: '#ffffff', fontSize: 16, fontWeight: 'bold', textAlign: 'center', lineHeight: 48, width: '100%' },
    versionText: { textAlign: 'center', color: '#9ca3af', fontSize: 12, marginTop: 32 },
    modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', alignItems: 'center', padding: 24 },
    modalCard: { backgroundColor: '#ffffff', borderRadius: 24, padding: 24, width: '100%', maxWidth: 400, alignItems: 'center', shadowColor: '#000', shadowOffset: { width: 0, height: 10 }, shadowOpacity: 0.25, shadowRadius: 15 },
    modalTitle: { fontSize: 22, fontWeight: 'bold', color: '#111827', marginBottom: 12, textAlign: 'center', marginTop: 12 },
    modalDesc: { fontSize: 16, color: '#6b7280', textAlign: 'center', marginBottom: 32, lineHeight: 24 },
    modalBtns: { flexDirection: 'row', gap: 12, width: '100%' },
    cancelBtn: { flex: 1, paddingVertical: 14, borderRadius: 12, backgroundColor: '#f3f4f6', alignItems: 'center' },
    cancelBtnText: { fontSize: 16, fontWeight: '600', color: '#4b5563' },
    confirmBtn: { flex: 1, paddingVertical: 14, borderRadius: 12, alignItems: 'center' },
    confirmBtnText: { fontSize: 16, fontWeight: '600', color: '#ffffff' },
});

export default Menu;
