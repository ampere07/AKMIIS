import React, { useState, useEffect } from 'react';
import { View, Text, ScrollView, Pressable, StyleSheet } from 'react-native';
import { ChevronLeft } from 'lucide-react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';

interface ReleaseNote {
    version: string;
    date: string;
    title: string;
    updates: string[];
}

interface ReleaseNotesProps {
    onBack: () => void;
}

/**
 * The release history as a customer reads it.
 *
 * The full app keeps one list for every role and filters it at runtime by the
 * signed-in role. Only customers sign in here, so this holds exactly what that
 * filter shows a customer — the entries marked 'customer' or 'all' — and
 * nothing written for technicians, agents or administrators.
 */
const NOTES: ReleaseNote[] = [
    {
        version: '2.5.47',
        date: 'June 20, 2026',
        title: 'Customer Payment Session Management',
        updates: [
            'Cancel Pending Payments: Added a "Cancel Payment" button in the Pending Payment modal, allowing customers to easily void and clear any stuck or unwanted payment sessions directly from the dashboard.'
        ]
    },
    {
        version: '2.5.27',
        date: 'May 9, 2026',
        title: 'Account Management & Speedtest Integration',
        updates: [
            'Account Suspension Enforcement: Implemented a security check during login. Suspended accounts (active = 0) are now blocked from accessing the mobile application with a clear contact support notification.'
        ]
    },
    {
        version: '2.5.25',
        date: 'May 9, 2026',
        title: 'UI Stability & Performance Optimization',
        updates: [
            'Persistent Theme Branding: Fixed an issue where the color palette would intermittently default to purple. Your selected branding now persists reliably across app restarts.',
            'Real-time UI Sync: Implemented real-time theme synchronization. Changing your dashboard colors now updates all active pages instantly for a seamless experience.',
            'Improved App Stability: General performance optimizations and bug fixes across the Dashboard, Bills, and Support sections for a smoother user experience.'
        ]
    },
    {
        version: '2.5.23',
        date: 'May 5, 2026',
        title: 'Proof Persistence & LCP/NAP Management',
        updates: [
            'Enhanced Dashboard Responsiveness: Optimized the customer dashboard layout for better performance and readability on a wider range of mobile devices.',
            'Support Center Refinements: Improved the support ticket interface and interaction flow for a smoother customer support experience.'
        ]
    },
    {
        version: '2.5.21',
        date: 'May 4, 2026',
        title: 'Modern Dashboard & Interactive UI',
        updates: [
            'Gliding Navigation: Experience a modern, floating oval navigation bar with a smooth gliding indicator that follows your active section.',
            'Interactive Ads Stack: Replaced the ad slider with a Tinder-style stacked card system featuring smooth slide animations.',
            'Vertical Flip Balance Card: Added a vertical flip animation to the balance card. Flip it to instantly view your Plan, Usage Type, and Email details.',
            'Payment History Polish: Cleaned up the payment list by removing icons and adding smart truncation for long reference numbers.',
            'On-Demand SOA: Generate your Statement of Account PDF on-demand directly from the Bills page if it hasn\'t been created yet.'
        ]
    },
    {
        version: '2.5.20',
        date: 'May 3, 2026',
        title: 'UI/UX Overhaul & Technician Workflow Updates',
        updates: [
            'Redesigned Customer Dashboard: Experience a more premium, modern interface with vibrant gradients, glassmorphism effects, and dynamic color palettes.',
            'Streamlined Menu: The Menu page has been simplified by removing redundant billing info and centering user profile details for a cleaner look.',
            'Enhanced Mobile Support: Optimized layouts across all pages to ensure a seamless experience on various mobile screen sizes and orientations.'
        ]
    },
    {
        version: '2.5.19',
        date: 'May 3, 2026',
        title: 'Forgot Password & Security Updates',
        updates: [
            'Forgot Password Cooldown: Implemented a 3-minute safety timer between recovery requests to prevent misuse and improve security.',
            'Expanded Recovery Options: You can now recover your account using your Account Number, Email, or Username for a more flexible login experience.'
        ]
    },
    {
        version: '2.5.18',
        date: 'May 3, 2026',
        title: 'Fullscreen Mode & Screen Sharing Fixes',
        updates: [
            'Immersive Fullscreen: The app now automatically opens in true fullscreen mode. The top status bar and bottom navigation buttons are hidden by default for an uninterrupted experience.',
            'Login Screen Sharing: Fixed a security issue that caused the login screen to turn black when sharing your screen or recording.'
        ]
    },
    {
        version: '2.5.17',
        date: 'May 2, 2026',
        title: 'Billing Layout & App Updates',
        updates: [
            'Bills UI Update: Unified the layout structure for Invoices, SOA, and History tabs to ensure a consistent look and feel.',
            'Invoice Status: Replaced the PDF download button in the Invoices tab with a dynamic status badge (PAID/UNPAID) for better clarity.',
            'Support Center UI: Completely redesigned the Ticket Details page for a cleaner, full-screen experience without bulky modals or drop shadows.'
        ]
    },
    {
        version: '2.5.16',
        date: 'May 1, 2026',
        title: 'Role-Based Permissions & UI Optimizations',
        updates: [
            'Dashboard UI: Fixed an issue where high account balances (thousands) would wrap to two lines. The font size now dynamically adjusts to stay on a single line.',
            'New Section: Added this Release Notes page to keep track of application improvements.'
        ]
    }
];

const ReleaseNotes: React.FC<ReleaseNotesProps> = ({ onBack }) => {
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);

    useEffect(() => {
        settingsColorPaletteService.getActive()
            .then(setColorPalette)
            .catch((err) => console.error('Failed to initialize ReleaseNotes:', err));
    }, []);

    const primaryColor = colorPalette?.primary || '#ef4444';

    return (
        <SafeAreaView style={styles.safeArea}>
            {/* Custom Header */}
            <View style={[styles.header, { borderBottomColor: '#e2e8f0' }]}>
                <Pressable onPress={onBack} style={styles.backBtn} accessibilityRole="button" accessibilityLabel="Back">
                    <ChevronLeft size={24} color="#000000" />
                </Pressable>
                <Text style={styles.headerTitle}>Release Notes</Text>
                <View style={{ width: 40 }} />
            </View>

            <ScrollView style={styles.container} contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
                <View style={styles.introBox}>
                    <Text style={[styles.introText, { color: '#475569' }]}>
                        Keep track of the latest features, improvements, and bug fixes in the AKM Portal.
                    </Text>
                </View>

                {NOTES.map((note) => (
                    <View key={note.version} style={styles.note}>
                        <Text style={styles.noteDate}>{note.date}</Text>
                        <Text style={[styles.noteVersion, { color: primaryColor }]}>Version {note.version}</Text>
                        {note.updates.map((update, uIdx) => (
                            <View key={uIdx} style={styles.updateRow}>
                                <Text style={[styles.bullet, { color: primaryColor }]}>•</Text>
                                <Text style={styles.updateText}>{update}</Text>
                            </View>
                        ))}
                    </View>
                ))}

                <View style={styles.footer}>
                    <Text style={styles.footerText}>You're up to date!</Text>
                </View>
            </ScrollView>
        </SafeAreaView>
    );
};

const styles = StyleSheet.create({
    safeArea: { flex: 1, backgroundColor: '#ffffff' },
    header: {
        height: 60,
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 16,
        backgroundColor: '#ffffff',
        borderBottomWidth: 1,
    },
    backBtn: { padding: 8 },
    headerTitle: { fontSize: 18, fontWeight: '700', color: '#1e293b' },
    container: { flex: 1 },
    scrollContent: { padding: 20, paddingBottom: 40 },
    introBox: { marginBottom: 24 },
    introText: { fontSize: 14, color: '#64748b', lineHeight: 20 },
    note: { marginBottom: 30, padding: 15, borderBottomWidth: 1, borderBottomColor: '#eee' },
    noteDate: { fontSize: 18, fontWeight: 'bold', color: '#000', marginBottom: 5 },
    noteVersion: { fontSize: 12, marginBottom: 10 },
    updateRow: { flexDirection: 'row', marginBottom: 12 },
    bullet: { marginRight: 10 },
    updateText: { flex: 1, color: '#333', lineHeight: 20 },
    footer: { marginTop: 20, alignItems: 'center' },
    footerText: { color: '#94a3b8', fontSize: 13 },
});

export default ReleaseNotes;
