import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { View, Text, Pressable, ScrollView, ActivityIndicator, useWindowDimensions, Animated, RefreshControl, StyleSheet, DeviceEventEmitter, Alert } from 'react-native';
import { RefreshCcw } from 'lucide-react-native';
import { LinearGradient } from 'expo-linear-gradient';
import Svg, { Circle, G } from 'react-native-svg';
import AsyncStorage from '@react-native-async-storage/async-storage';
import dayjs from 'dayjs';
import { useCustomerDataContext } from '../contexts/CustomerDataContext';
import { useApplicationContext } from '../contexts/ApplicationContext';
import { useJobOrderContext } from '../contexts/JobOrderContext';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { fetchAgentCommissionHistory, fetchAgentAchievements, claimAgentAchievement } from '../services/api';
import { agentOwnsReferral } from '../utils/agentReferral';

const AnimatedCircle = Animated.createAnimatedComponent(Circle);

interface DashboardAgentProps {
    onNavigate?: (section: string, tab?: string) => void;
}

const DashboardAgent: React.FC<DashboardAgentProps> = ({ onNavigate }) => {
    const { width, height } = useWindowDimensions();
    const isMobile = width < 768;
    const isShort = height < 700;
    const { customerDetail, payments, isLoading: contextLoading, silentRefresh: customerRefresh } = useCustomerDataContext();
    const { applications, silentRefresh: applicationsRefresh } = useApplicationContext();
    const { jobOrders, silentRefresh: jobOrdersRefresh } = useJobOrderContext();
    const [user, setUser] = useState<any>(null);
    const [cashouts, setCashouts] = useState<any[]>([]);
    const [stats, setStats] = useState<any>(null);
    const [agentBalance, setAgentBalance] = useState<number>(0);
    const [agentIncentives, setAgentIncentives] = useState<number>(0);
    const [agentBonus, setAgentBonus] = useState<number>(0);
    const [agentAchievement, setAgentAchievement] = useState<number>(0);

    const latestCashouts = useMemo(() => {
        return (cashouts || []).slice(0, 5);
    }, [cashouts]);

    const formatDate = useCallback((dateStr?: string) => {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return `${months[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
    }, []);

    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(() => settingsColorPaletteService.getActiveSync());
    const [refreshing, setRefreshing] = useState(false);
    const [isCardFlipped, setIsCardFlipped] = useState(false);
    const [claimedMilestones, setClaimedMilestones] = useState<number[]>([]);
    const [isClaiming, setIsClaiming] = useState(false);
    const flipAnim = React.useRef(new Animated.Value(0)).current;

    const handleFlipCard = useCallback(() => {
        Animated.timing(flipAnim, {
            toValue: 1,
            duration: 250,
            useNativeDriver: true,
        }).start(() => {
            setIsCardFlipped(prev => !prev);
            Animated.timing(flipAnim, {
                toValue: 0,
                duration: 250,
                useNativeDriver: true,
            }).start();
        });
    }, [flipAnim]);

    const cardScaleY = flipAnim.interpolate({
        inputRange: [0, 1],
        outputRange: [1, 0]
    });

    const displayName = customerDetail?.fullName || user?.full_name || 'Agent';
    const initials = (customerDetail?.firstName && customerDetail?.lastName)
        ? `${customerDetail.firstName.charAt(0)}${customerDetail.lastName.charAt(0)}`.toUpperCase()
        : displayName.split(' ').map((n: any) => n[0]).join('').substring(0, 2).toUpperCase();
    const accountNo = customerDetail?.billingAccount?.accountNo || user?.username || 'N/A';
    const emailAddress = customerDetail?.emailAddress || user?.email || 'N/A';
    const balance = stats ? Number(stats.total.replace(/[^0-9.]/g, '')) : Number(customerDetail?.billingAccount?.accountBalance || 0);

    const agentEmail = user?.email || '';
    const agentName = user?.full_name || '';

    const { referredCount, successfulInstalledCount, failedInstalledCount, rescheduleCount } = useMemo(() => {
        if (!agentEmail && !agentName) return { referredCount: 0, successfulInstalledCount: 0, failedInstalledCount: 0, rescheduleCount: 0 };

        const filtered = jobOrders.filter(jo =>
            agentOwnsReferral(jo.Referred_By || jo.referred_by || '', agentName, agentEmail)
        );

        const inProgress = filtered.filter(jo => {
            const status = (jo.Onsite_Status || jo.onsite_status || '').toLowerCase().trim();
            return status === 'in progress' || status === 'inprogress' || status === 'in-progress' || status === 'pending';
        }).length;

        const onboard = filtered.filter(jo => {
            const status = (jo.Onsite_Status || jo.onsite_status || '').toLowerCase().trim();
            return status === 'done' || status === 'completed';
        }).length;

        const failed = filtered.filter(jo => {
            const status = (jo.Onsite_Status || jo.onsite_status || '').toLowerCase().trim();
            return status === 'failed' || status === 'cancelled' || status === 'suspended' || status === 'disapproved';
        }).length;

        const reschedule = filtered.filter(jo => {
            const status = (jo.Onsite_Status || jo.onsite_status || '').toLowerCase().trim();
            return status === 'reschedule' || status === 'rescheduled' || status === 're-schedule';
        }).length;

        return {
            referredCount: inProgress,
            successfulInstalledCount: onboard,
            failedInstalledCount: failed,
            rescheduleCount: reschedule
        };
    }, [jobOrders, agentEmail, agentName]);

    // ---- Achievement (30-onboard milestone) ----
    const agentId = user?.id || 0;
    const onboardReferredCount = successfulInstalledCount;
    const achievementTarget = 30;

    const possibleMilestones = useMemo(() => {
        const ms: number[] = [];
        for (let i = achievementTarget; i <= onboardReferredCount; i += achievementTarget) ms.push(i);
        return ms;
    }, [onboardReferredCount]);

    const pendingMilestone = possibleMilestones.find(m => !claimedMilestones.includes(m));
    const isAchieved = !!pendingMilestone;
    const achievementProgress = pendingMilestone ? achievementTarget : (onboardReferredCount % achievementTarget);

    const gaugeAnim = useRef(new Animated.Value(0)).current;
    useEffect(() => {
        Animated.timing(gaugeAnim, {
            toValue: achievementProgress,
            duration: 1200,
            useNativeDriver: false,
        }).start();
    }, [achievementProgress]);

    const handleClaimReward = async () => {
        if (!agentId || !pendingMilestone) return;
        setIsClaiming(true);
        try {
            const response = await claimAgentAchievement({ agent_id: agentId, milestone: pendingMilestone, amount: 1500 });
            if (response.success) {
                setClaimedMilestones(prev => [...prev, pendingMilestone]);
                Alert.alert('Reward Claimed!', `₱1,500 has been added to your achievement rewards for hitting ${pendingMilestone} onboards.`, [{ text: 'OK' }]);
                fetchHistory();
            } else {
                Alert.alert('Error', response.message || 'Failed to claim reward.');
            }
        } catch (error: any) {
            const errMsg = error.response?.data?.message || 'An unexpected error occurred.';
            Alert.alert('Error', errMsg);
        } finally {
            setIsClaiming(false);
        }
    };

    // Speedometer gauge geometry
    const gaugeWidth = 220;
    const gaugeR = (gaugeWidth - 30) / 2;
    const gaugeCx = gaugeWidth / 2;
    const gaugeCy = gaugeWidth / 2;
    const gaugeCircumference = 2 * Math.PI * gaugeR;
    const gaugeArcLength = gaugeCircumference * 0.75;
    const gaugeDashoffset = gaugeAnim.interpolate({
        inputRange: [0, achievementTarget],
        outputRange: [gaugeArcLength, 0],
        extrapolate: 'clamp'
    });

    const claimButtonColor = colorPalette?.primary || '#ef4444';

    const fetchHistory = useCallback(async () => {
        try {
            const response = await fetchAgentCommissionHistory();
            if (response.success) {
                setCashouts(response.data);
                setAgentBalance(response.balance !== undefined ? Number(response.balance) : 0);
                setAgentIncentives(response.incentives !== undefined ? Number(response.incentives) : 0);
                setAgentBonus(response.bonus !== undefined ? Number(response.bonus) : 0);
                setAgentAchievement(response.achievement !== undefined ? Number(response.achievement) : 0);
            }
        } catch (error) {
            console.error('Failed to fetch cashout history:', error);
        }
    }, []);

    const fetchAchievements = useCallback(async (agentId: number) => {
        try {
            const response = await fetchAgentAchievements(agentId);
            if (response && response.data) {
                setClaimedMilestones(response.data.map((item: any) => item.milestone));
            }
        } catch (error) {
            console.error('Failed to fetch achievements:', error);
        }
    }, []);

    useEffect(() => {
        const loadUser = async () => {
            try {
                const storedUser = await AsyncStorage.getItem('authData');
                if (storedUser) {
                    const parsed = JSON.parse(storedUser);
                    setUser(parsed);
                    if (parsed?.id) fetchAchievements(parsed.id);
                }
            } catch (e) {
                console.error('Failed to parse auth data:', e);
            }
        };
        loadUser();
        customerRefresh();
        applicationsRefresh();
        jobOrdersRefresh();
        fetchHistory();
    }, [fetchHistory, fetchAchievements]);

    useEffect(() => {
        const fetchColorPalette = async () => {
            try {
                const activePalette = await settingsColorPaletteService.getActive();
                setColorPalette(activePalette);
            } catch (err) {
                console.error('Failed to fetch color palette:', err);
            }
        };
        fetchColorPalette();

        const paletteSub = DeviceEventEmitter.addListener('colorPaletteChanged', (newPalette) => {
            setColorPalette(newPalette);
        });

        return () => paletteSub.remove();
    }, []);

    const onRefresh = React.useCallback(async () => {
        setRefreshing(true);
        try {
            await Promise.all([customerRefresh(), applicationsRefresh(), jobOrdersRefresh(), fetchHistory()]);
        } catch (error) {
            console.error('Refresh failed:', error);
        } finally {
            setRefreshing(false);
        }
    }, [customerRefresh, applicationsRefresh, jobOrdersRefresh, fetchHistory]);

    const formatCurrency = useCallback((amount: number) => {
        const isNegative = amount < 0;
        const formatted = Math.abs(amount).toFixed(0).replace(/\d(?=(\d{3})+$)/g, '$&,');
        return `₱${isNegative ? '-' : ''}${formatted}`;
    }, []);

    if (contextLoading && !customerDetail) return (
        <View style={styles.loadingContainer}>
            <ActivityIndicator size="large" color="#111827" />
        </View>
    );

    return (
        <View style={styles.container}>
            <ScrollView
                showsVerticalScrollIndicator={false}
                contentContainerStyle={{ paddingTop: !isMobile ? 16 : (isShort ? 20 : 60), paddingHorizontal: isMobile ? 16 : 24, paddingBottom: 100, gap: isShort ? 16 : 24 }}
                refreshControl={
                    <RefreshControl
                        refreshing={refreshing}
                        onRefresh={onRefresh}
                        colors={[colorPalette?.primary || '#ef4444']}
                        tintColor={colorPalette?.primary || '#ef4444'}
                    />
                }
            >
                <View style={styles.contentGap}>
                    <View style={{ gap: 16 }}>
                        <Animated.View style={[styles.balanceCard, { transform: [{ scaleY: cardScaleY }] }]}>
                            <LinearGradient
                                colors={isCardFlipped ? ['#000000', colorPalette?.primary || '#ef4444'] : [colorPalette?.primary || '#ef4444', '#000000']}
                                start={{ x: 0, y: 0 }}
                                end={{ x: 1, y: 1 }}
                                style={[styles.gradientInner, { paddingVertical: isShort ? 20 : 32, paddingHorizontal: isMobile ? 20 : 28, minHeight: isShort ? 180 : 230 }]}
                            >
                                <View style={[styles.profileRow, { marginBottom: isShort ? 12 : 32, justifyContent: 'space-between' }]}>
                                    <View style={[styles.initialsCircle, { width: isShort ? 44 : 50, height: isShort ? 44 : 50, borderRadius: isShort ? 22 : 25 }]}>
                                        <Text style={[styles.initialsText, { fontSize: isShort ? 18 : 20 }]}>{initials}</Text>
                                    </View>
                                    <View style={{ flex: 1, marginHorizontal: 12 }}>
                                        <Text
                                            allowFontScaling={false}
                                            numberOfLines={1}
                                            adjustsFontSizeToFit
                                            style={[styles.customerNameText, { fontSize: isShort ? 16 : 18 }]}
                                        >
                                            {displayName}
                                        </Text>
                                        <Text allowFontScaling={false} style={styles.customerAccountText}>Agent ID: {accountNo}</Text>
                                        {isCardFlipped && <Text allowFontScaling={false} numberOfLines={1} style={styles.customerAccountText}>{emailAddress}</Text>}
                                    </View>
                                    <Pressable
                                        onPress={handleFlipCard}
                                        style={({ pressed }) => ({
                                            opacity: pressed ? 0.6 : 1,
                                            padding: 8
                                        })}
                                    >
                                        <RefreshCcw size={20} color="#ffffff" />
                                    </Pressable>
                                </View>

                                {!isCardFlipped ? (
                                    <View style={{ justifyContent: 'center' }}>
                                        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
                                            {[
                                                { label: 'Incentives', value: agentIncentives },
                                                { label: 'Commission', value: agentBalance },
                                                { label: 'Bonus', value: agentBonus },
                                                { label: 'Achievement', value: agentAchievement },
                                            ].map((item, i) => {
                                                const alignRight = i % 2 === 1;
                                                return (
                                                    <View key={item.label} style={{ width: '50%', alignItems: alignRight ? 'flex-end' : 'flex-start', paddingVertical: isShort ? 6 : 10, paddingHorizontal: 2 }}>
                                                        <Text
                                                            allowFontScaling={false}
                                                            numberOfLines={1}
                                                            adjustsFontSizeToFit
                                                            style={[styles.balanceLabel, { textAlign: alignRight ? 'right' : 'left' }]}
                                                        >
                                                            {item.label}
                                                        </Text>
                                                        <Text
                                                            numberOfLines={1}
                                                            adjustsFontSizeToFit
                                                            minimumFontScale={0.4}
                                                            allowFontScaling={false}
                                                            style={[styles.balanceAmountText, { textAlign: alignRight ? 'right' : 'left', fontSize: isMobile ? (isShort ? 20 : 24) : 28 }]}
                                                        >
                                                            {formatCurrency(item.value)}
                                                        </Text>
                                                    </View>
                                                );
                                            })}
                                        </View>
                                    </View>
                                ) : (
                                    <View style={{ justifyContent: 'center' }}>
                                        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
                                            {[
                                                { label: 'In Progress', value: referredCount },
                                                { label: 'Done', value: successfulInstalledCount },
                                                { label: 'Failed', value: failedInstalledCount },
                                                { label: 'Reschedule', value: rescheduleCount },
                                            ].map((item, i) => {
                                                const alignRight = i % 2 === 1;
                                                return (
                                                    <View key={item.label} style={{ width: '50%', alignItems: alignRight ? 'flex-end' : 'flex-start', paddingVertical: isShort ? 6 : 10, paddingHorizontal: 2 }}>
                                                        <Text
                                                            numberOfLines={1}
                                                            adjustsFontSizeToFit
                                                            allowFontScaling={false}
                                                            style={{ color: 'rgba(255,255,255,0.6)', fontSize: 13, marginBottom: 4, textAlign: alignRight ? 'right' : 'left' }}
                                                        >
                                                            {item.label}
                                                        </Text>
                                                        <Text style={{ color: '#ffffff', fontSize: 24, fontWeight: '700', textAlign: alignRight ? 'right' : 'left' }}>{item.value}</Text>
                                                    </View>
                                                );
                                            })}
                                        </View>
                                    </View>
                                )}
                            </LinearGradient>
                        </Animated.View>

                        {/* Achievements Section */}
                        <View style={styles.sectionGap}>
                            <View style={styles.achievementCard}>
                                <Text style={styles.achievementTitle}>30 Onboard Referrals</Text>
                                <Text style={styles.achievementDesc}>Refer 30 customers and have them successfully onboarded.</Text>

                                <View style={styles.gaugeWrapper}>
                                    <View style={{ width: gaugeWidth, height: gaugeWidth, position: 'relative' }}>
                                        <Svg width={gaugeWidth} height={gaugeWidth} viewBox={`0 0 ${gaugeWidth} ${gaugeWidth}`}>
                                            <G rotation="135" origin={`${gaugeCx}, ${gaugeCy}`}>
                                                <Circle
                                                    cx={gaugeCx}
                                                    cy={gaugeCy}
                                                    r={gaugeR}
                                                    stroke="#e2e8f0"
                                                    strokeWidth={30}
                                                    strokeDasharray={`${gaugeArcLength}, ${gaugeCircumference}`}
                                                    fill="none"
                                                    strokeLinecap="round"
                                                />
                                                <AnimatedCircle
                                                    cx={gaugeCx}
                                                    cy={gaugeCy}
                                                    r={gaugeR}
                                                    stroke={colorPalette?.primary || '#ef4444'}
                                                    strokeWidth={30}
                                                    strokeDasharray={`${gaugeArcLength}, ${gaugeCircumference}`}
                                                    strokeDashoffset={gaugeDashoffset}
                                                    fill="none"
                                                    strokeLinecap="round"
                                                />
                                            </G>
                                        </Svg>
                                        <View style={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, alignItems: 'center', justifyContent: 'center' }}>
                                            <Text style={styles.gaugeValueText}>{achievementProgress}</Text>
                                            <Text style={styles.gaugeValueLabel}>Onboarded</Text>
                                        </View>
                                    </View>
                                </View>

                                {isAchieved && (
                                    <Pressable
                                        style={({ pressed }) => [
                                            styles.claimBtn,
                                            { backgroundColor: claimButtonColor, opacity: pressed ? 0.8 : 1 }
                                        ]}
                                        onPress={handleClaimReward}
                                        disabled={isClaiming}
                                    >
                                        {isClaiming ? (
                                            <ActivityIndicator size="small" color="#ffffff" />
                                        ) : (
                                            <Text style={styles.claimBtnText}>Get Reward (₱1,500)</Text>
                                        )}
                                    </Pressable>
                                )}
                            </View>
                        </View>

                        <View style={styles.balanceCard}>
                            <LinearGradient
                                colors={[colorPalette?.primary || '#ef4444', '#000000']}
                                start={{ x: 0, y: 0 }}
                                end={{ x: 1, y: 1 }}
                                style={[styles.gradientInner, { paddingVertical: isShort ? 20 : 28, paddingHorizontal: isMobile ? 20 : 28, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }]}
                            >
                                <View style={{ flex: 1 }}>
                                    <Text allowFontScaling={false} style={{ color: '#e5e7eb', fontSize: 14, fontWeight: '600', marginBottom: 8 }}>Total Balance</Text>
                                    <Text
                                        numberOfLines={1}
                                        adjustsFontSizeToFit
                                        minimumFontScale={0.5}
                                        allowFontScaling={false}
                                        style={{ fontWeight: 'bold', color: '#ffffff', fontSize: (agentBalance + agentIncentives + agentBonus + agentAchievement) >= 1000 ? (isMobile ? (isShort ? 32 : 36) : 44) : (isMobile ? (isShort ? 40 : 44) : 52) }}
                                    >
                                        {formatCurrency(agentBalance + agentIncentives + agentBonus + agentAchievement)}
                                    </Text>
                                </View>
                                <Pressable onPress={() => onNavigate && onNavigate('Application')}>
                                    {({ pressed }) => (
                                        <View style={[
                                            {
                                                backgroundColor: 'transparent',
                                                paddingHorizontal: 16,
                                                paddingVertical: 10,
                                                borderRadius: 12,
                                                borderWidth: 2,
                                                borderColor: '#ffffff'
                                            },
                                            pressed && { opacity: 0.7, backgroundColor: 'rgba(255,255,255,0.1)' }
                                        ]}>
                                            <Text style={{ color: '#ffffff', fontWeight: 'bold', fontSize: 13 }}>
                                                Application Form
                                            </Text>
                                        </View>
                                    )}
                                </Pressable>
                            </LinearGradient>
                        </View>
                    </View>

                    {/* Cashout History Section */}
                    <View style={styles.sectionGap}>
                        <View style={styles.sectionHeader}>
                            <Text style={styles.sectionTitle}>Cashout History</Text>
                        </View>

                        <View style={styles.referralContent}>
                            {latestCashouts.length > 0 ? (
                                latestCashouts.map((cashout: any) => (
                                    <View key={cashout.id} style={styles.paymentItem}>
                                        <View style={{ flex: 1.5 }}>
                                            <Text numberOfLines={1} ellipsizeMode="tail" style={styles.paymentRef}>Ref: {cashout.ref_number}</Text>
                                            <Text style={styles.paymentDate}>{dayjs(cashout.created_at).format('MMM DD, YYYY')}</Text>
                                        </View>
                                        <View style={styles.alignEnd}>
                                            <Text style={styles.paymentAmountValue}>{formatCurrency(Number(cashout.total_amount))}</Text>
                                            <View style={[styles.statusBadgeSmall, { backgroundColor: 'transparent' }]}>
                                                <Text style={[
                                                    styles.statusTextSmall,
                                                    { color: '#16a34a' }
                                                ]}>
                                                    {'POSTED'}
                                                </Text>
                                            </View>
                                        </View>
                                    </View>
                                ))
                            ) : (
                                <View style={styles.emptyReferrals}>
                                    <Text style={styles.emptyReferralsText}>No cashouts found</Text>
                                </View>
                            )}
                        </View>
                    </View>
                </View>
            </ScrollView>
        </View>
    );
};

const styles = StyleSheet.create({
    loadingContainer: { padding: 32, flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#f9fafb' },
    container: { flex: 1, backgroundColor: '#f9fafb', position: 'relative' },
    contentGap: { gap: 32 },
    balanceCard: { borderRadius: 24, backgroundColor: '#f9fafb' },
    gradientInner: { borderRadius: 24, paddingHorizontal: 24, position: 'relative', overflow: 'hidden' },
    profileRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
    initialsCircle: { backgroundColor: 'rgba(255, 255, 255, 0.15)', justifyContent: 'center', alignItems: 'center', borderWidth: 1, borderColor: 'rgba(255, 255, 255, 0.3)' },
    initialsText: { color: '#ffffff', fontWeight: 'bold' },
    customerNameText: { color: '#ffffff', fontWeight: 'bold', textTransform: 'capitalize' },
    customerAccountText: { color: '#e5e7eb', fontSize: 11, opacity: 0.9 },
    billingRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12 },
    billingLeft: { flex: 1, minWidth: 120 },
    balanceLabel: { color: '#e5e7eb', fontSize: 12, marginBottom: 4 },
    balanceAmountText: { fontWeight: 'bold', color: '#ffffff' },
    sectionGap: { gap: 16 },
    sectionHeader: { flexDirection: 'row', alignItems: 'center', gap: 8 },
    sectionTitle: { fontSize: 18, fontWeight: 'bold', color: '#111827' },
    achievementCard: { backgroundColor: '#f9fafb', borderRadius: 24, padding: 20, alignItems: 'center' },
    achievementTitle: { fontSize: 18, fontWeight: '700', color: '#1e293b', textAlign: 'center', marginBottom: 6 },
    achievementDesc: { fontSize: 14, color: '#64748b', lineHeight: 20, textAlign: 'center', marginBottom: 24 },
    gaugeWrapper: { alignItems: 'center', marginBottom: 12 },
    gaugeValueText: { fontSize: 48, fontWeight: '800', color: '#0f172a', lineHeight: 56 },
    gaugeValueLabel: { fontSize: 12, fontWeight: '700', color: '#64748b', textTransform: 'uppercase', letterSpacing: 1, marginTop: -4 },
    claimBtn: { backgroundColor: '#ef4444', paddingVertical: 14, paddingHorizontal: 32, borderRadius: 12, alignItems: 'center', justifyContent: 'center', marginTop: 12, alignSelf: 'stretch' },
    claimBtnText: { color: '#ffffff', fontSize: 16, fontWeight: '700' },
    referralContent: { gap: 12, paddingBottom: 8 },
    paymentItem: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        paddingVertical: 14,
        backgroundColor: 'transparent',
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9'
    },
    paymentRef: { fontSize: 14, fontWeight: '700', color: '#1e293b' },
    paymentDate: { fontSize: 12, color: '#64748b', marginTop: 2 },
    paymentAmountValue: { fontSize: 15, fontWeight: '800', color: '#1e293b', textAlign: 'right' },
    statusBadgeSmall: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 6, marginTop: 4, alignSelf: 'flex-end' },
    statusTextSmall: { fontSize: 10, fontWeight: '800' },
    alignEnd: { alignItems: 'flex-end' },
    emptyReferrals: { padding: 40, alignItems: 'center', justifyContent: 'center' },
    emptyReferralsText: { color: '#6b7280', fontSize: 14 },
    sectionHeaderBetween: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    filterContainer: { flexDirection: 'row', gap: 8 },
    dropdownBtn: { flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: '#ffffff', paddingHorizontal: 12, paddingVertical: 6, borderRadius: 12, borderWidth: 1, borderColor: '#e2e8f0' },
    dropdownBtnText: { fontSize: 12, color: '#64748b', fontWeight: '600' },
    dropdownMenu: { position: 'absolute', top: 38, right: 0, backgroundColor: '#ffffff', borderRadius: 12, padding: 4, minWidth: 120, shadowColor: '#000', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.1, shadowRadius: 8, elevation: 5, zIndex: 1000, borderWidth: 1, borderColor: '#f1f5f9' },
    dropdownItem: { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 8 },
    dropdownItemText: { fontSize: 12, color: '#64748b' },
    filterBtn: { paddingHorizontal: 12, paddingVertical: 4, borderRadius: 12, borderWidth: 1, borderColor: '#e2e8f0', backgroundColor: '#f9fafb' },
    filterText: { fontSize: 12, color: '#64748b' },
    graphWrapper: { backgroundColor: '#f9fafb', borderRadius: 24, padding: 12 },
    graphBody: { flexDirection: 'row', height: 160, width: '100%' },
    yAxis: { width: 40, height: 160, justifyContent: 'space-between', paddingVertical: 0, marginRight: 4 },
    yAxisLabel: { fontSize: 8, color: '#94a3b8', fontWeight: '600', textAlign: 'right' },
    graphContainer: { flex: 1, height: 160 },
    xAxisRow: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 12, marginLeft: 44, paddingHorizontal: 0 },
    labelItem: { fontSize: 8, color: '#94a3b8', fontWeight: '600', textAlign: 'center', width: 24 },

    // Modal Styles
    modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.4)', justifyContent: 'center', alignItems: 'center', padding: 20 },
    tooltipCard: { backgroundColor: '#ffffff', borderRadius: 20, padding: 24, width: '100%', maxWidth: 300, shadowColor: '#000', shadowOffset: { width: 0, height: 10 }, shadowOpacity: 0.1, shadowRadius: 20, elevation: 10 },
    tooltipHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 },
    tooltipTitle: { fontSize: 14, fontWeight: '600', color: '#64748b' },
    tooltipBody: { gap: 4, marginBottom: 20 },
    tooltipLabel: { fontSize: 12, color: '#94a3b8' },
    tooltipValue: { fontSize: 28, fontWeight: '800' },
    tooltipFooter: { borderTopWidth: 1, borderTopColor: '#f1f5f9', paddingTop: 12 },
    tooltipHint: { fontSize: 10, color: '#cbd5e1', textAlign: 'center' },
});

export default DashboardAgent;
