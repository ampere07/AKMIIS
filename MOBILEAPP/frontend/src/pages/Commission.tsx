import React, { useState, useEffect } from 'react';
import {
    View,
    Text,
    TouchableOpacity,
    FlatList,
    RefreshControl,
    ActivityIndicator,
    Platform,
    useWindowDimensions,
} from 'react-native';
import DateTimePicker from '@react-native-community/datetimepicker';
import { Download, RefreshCw, Plus, History, Gift, Filter } from 'lucide-react-native';
import { exportToPDF } from '../utils/exportUtils';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { useCommissionStore } from '../store/commissionStore';
import { CommissionData, PayoutHistoryData } from '../types/commission';
import CommissionDetails from '../components/CommissionDetails';
import CommissionPayoutModal from '../modals/CommissionPayoutModal';
import IncentivesPayoutModal from '../modals/IncentivesPayoutModal';
import BonusPayoutModal from '../modals/BonusPayoutModal';
import { useAgentStore } from '../store/agentStore';
import GlobalSearch from './globalfunctions/GlobalSearch';

type TabKey = 'payouts' | 'incentives' | 'bonus';

// Forced light mode to match the ~50 already-migrated pages.
const isDarkMode = false;

const toDateString = (d: Date | null): string => {
    if (!d) return '';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
};

const formatAmount = (val: any): string => {
    if (typeof val === 'number') return `₱${val.toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
    if (!isNaN(Number(val))) return `₱${Number(val).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
    return String(val ?? '---');
};

const Commission: React.FC = () => {
    const store = useCommissionStore();
    const {
        payoutHistory,
        isLoading,
        fetchCommissions,
        fetchUpdates,
    } = store;

    // Incentive/bonus history & totals are not present on the reduced RN store; default to [].
    const incentiveHistory: any[] = (store as any).incentiveHistory ?? [];
    const bonusHistory: any[] = (store as any).bonusHistory ?? [];

    const { width } = useWindowDimensions();
    const isTablet = width >= 768;

    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
    const [activeTab, setActiveTab] = useState<TabKey>('payouts');
    const [searchTerm, setSearchTerm] = useState('');
    const [refreshing, setRefreshing] = useState(false);

    // Date range state
    const [dateFrom, setDateFrom] = useState<Date | null>(null);
    const [dateTo, setDateTo] = useState<Date | null>(null);
    const [showFromPicker, setShowFromPicker] = useState(false);
    const [showToPicker, setShowToPicker] = useState(false);
    const [showFilters, setShowFilters] = useState(false);

    const [selectedRecord, setSelectedRecord] = useState<CommissionData | PayoutHistoryData | null>(null);
    const [showDetails, setShowDetails] = useState(false);

    const [showPayoutModal, setShowPayoutModal] = useState(false);
    const [showIncentiveModal, setShowIncentiveModal] = useState(false);
    const [showBonusModal, setShowBonusModal] = useState(false);

    const { fetchAgents } = useAgentStore();

    const fetchData = async () => {
        await fetchCommissions(true);
    };

    const handleRefresh = async () => {
        setRefreshing(true);
        try {
            await fetchUpdates();
        } catch (err) {
            console.error('[Commission Page] Refresh failed:', err);
        } finally {
            setRefreshing(false);
        }
    };

    useEffect(() => {
        const fetchPalette = async () => {
            const palette = await settingsColorPaletteService.getActive();
            setColorPalette(palette);
        };
        fetchPalette();
        fetchData();
        fetchAgents();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Optional periodic refresh (15 min) — Pusher realtime is a no-op stub in RN.
    useEffect(() => {
        const intervalId = setInterval(() => {
            fetchUpdates().catch((err) => console.error('[Commission Page] Poll failed:', err));
        }, 15 * 60 * 1000);
        return () => clearInterval(intervalId);
    }, [fetchUpdates]);

    // Data for the active tab.
    const rawData: any[] = activeTab === 'payouts'
        ? payoutHistory.filter((item: any) => !item.type || item.type === 'commission')
        : activeTab === 'incentives'
            ? incentiveHistory
            : bonusHistory;

    const filteredData = React.useMemo(() => {
        const normalizedQuery = searchTerm.toLowerCase().replace(/\s+/g, '');
        return rawData.filter((row: any) => {
            const checkValue = (val: any): boolean => {
                if (val === null || val === undefined) return false;
                if (typeof val === 'object') return Object.values(val).some((v) => checkValue(v));
                return String(val).toLowerCase().replace(/\s+/g, '').includes(normalizedQuery);
            };
            const matchesSearch = searchTerm === '' || checkValue(row);

            if (dateFrom || dateTo) {
                const dateVal = row.date || row.created_at || row.processed_at;
                if (!dateVal) return matchesSearch;
                const itemDate = new Date(dateVal).getTime();
                if (dateFrom && itemDate < dateFrom.getTime()) return false;
                if (dateTo && itemDate > dateTo.getTime()) return false;
            }
            return matchesSearch;
        });
    }, [rawData, searchTerm, dateFrom, dateTo]);

    const handleRowClick = (record: CommissionData | PayoutHistoryData) => {
        setSelectedRecord(record);
        setShowDetails(true);
    };

    const currentIndex = selectedRecord
        ? filteredData.findIndex((r) => r.id === (selectedRecord as any).id)
        : -1;

    const handlePrevious = () => {
        if (currentIndex > 0) setSelectedRecord(filteredData[currentIndex - 1]);
    };

    const handleNext = () => {
        if (currentIndex !== -1 && currentIndex < filteredData.length - 1) {
            setSelectedRecord(filteredData[currentIndex + 1]);
        }
    };

    const handleOpenPayout = () => {
        if (activeTab === 'incentives') {
            setShowIncentiveModal(true);
        } else if (activeTab === 'bonus') {
            setShowBonusModal(true);
        } else {
            setShowPayoutModal(true);
        }
    };

    const handleExport = () => {
        // Export the visible rows for the active tab. RN exportToPDF falls back to CSV share.
        const columnMap: Record<TabKey, { key: string; label: string }[]> = {
            payouts: [
                { key: 'id', label: 'ID' },
                { key: 'ref_number', label: 'Ref Number' },
                { key: 'total_amount', label: 'Total Amount' },
                { key: 'commission_id_list', label: 'Job Orders' },
                { key: 'created_by', label: 'Processed By' },
            ],
            incentives: [
                { key: 'id', label: 'ID' },
                { key: 'agent_name', label: 'Agent' },
                { key: 'job_order_id', label: 'Job Order' },
                { key: 'batch_number', label: 'Batch' },
                { key: 'quota_reached', label: 'Quota Reached' },
                { key: 'incentive_value', label: 'Incentive Value' },
                { key: 'processed_at', label: 'Processed At' },
            ],
            bonus: [
                { key: 'id', label: 'ID' },
                { key: 'ref_number', label: 'Ref Number' },
                { key: 'type', label: 'Type' },
                { key: 'total_amount', label: 'Total Amount' },
                { key: 'created_by', label: 'Processed By' },
            ],
        };

        const getExportValue = (row: any, key: string) => {
            const val = row[key];
            if (key === 'total_amount' || key === 'amount' || key === 'incentive_value') return formatAmount(val);
            if (key === 'created_at' || key === 'date' || key === 'processed_at') return val ? new Date(val).toLocaleString() : '-';
            return val ?? '-';
        };

        const titleMap: Record<TabKey, string> = {
            payouts: 'Commission Payout History Report',
            incentives: 'Incentives History Report',
            bonus: 'Bonus Payout History Report',
        };

        exportToPDF(titleMap[activeTab], `commission_${activeTab}_export`, columnMap[activeTab], filteredData, getExportValue);
    };

    const primaryColor = colorPalette?.primary || '#7c3aed';
    const pageBg = '#f9fafb';
    const cardBg = '#ffffff';
    const borderColor = '#e5e7eb';
    const textColor = '#111827';
    const mutedColor = '#6b7280';
    const faintColor = '#9ca3af';

    const TabButton = ({ id, label, icon: Icon }: { id: TabKey; label: string; icon: any }) => {
        const active = activeTab === id;
        return (
            <TouchableOpacity
                onPress={() => {
                    setActiveTab(id);
                    setShowDetails(false);
                    setSelectedRecord(null);
                }}
                style={{
                    flex: 1,
                    flexDirection: 'row',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: 6,
                    paddingVertical: 12,
                    borderBottomWidth: 2,
                    borderBottomColor: active ? primaryColor : 'transparent',
                }}
            >
                <Icon size={16} color={active ? primaryColor : mutedColor} />
                <Text style={{ fontSize: 13, fontWeight: '600', color: active ? primaryColor : mutedColor }}>{label}</Text>
            </TouchableOpacity>
        );
    };

    const renderCard = ({ item }: { item: any }) => {
        const isIncentive = activeTab === 'incentives';
        const isBonus = activeTab === 'bonus';

        // Primary amount + label per tab.
        let amountNode: React.ReactNode = null;
        if (isIncentive) {
            amountNode = (
                <Text style={{ fontSize: 15, fontWeight: '700', color: '#16a34a' }}>
                    +{formatAmount(item.incentive_value)}
                </Text>
            );
        } else {
            const isPayoutType = item.type === 'incentives_payout';
            const isAddType = item.type === 'incentives';
            const amtColor = isPayoutType ? '#ef4444' : isAddType ? '#16a34a' : textColor;
            const sign = isPayoutType ? '-' : isAddType ? '+' : '';
            amountNode = (
                <Text style={{ fontSize: 15, fontWeight: '700', color: amtColor }}>
                    {sign}{formatAmount(item.total_amount)}
                </Text>
            );
        }

        return (
            <TouchableOpacity
                onPress={() => handleRowClick(item)}
                activeOpacity={0.7}
                style={{
                    backgroundColor: cardBg,
                    borderWidth: 1,
                    borderColor,
                    borderRadius: 10,
                    padding: 14,
                    marginBottom: 10,
                }}
            >
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                    <View style={{ flex: 1, paddingRight: 10 }}>
                        {isIncentive ? (
                            <>
                                <Text style={{ fontSize: 14, fontWeight: '600', color: textColor }}>
                                    {item.agent_name || '---'}
                                </Text>
                                <Text style={{ fontSize: 12, color: '#3b82f6', marginTop: 2 }}>
                                    JO #{item.job_order_id ?? '---'}
                                    {item.batch_number != null ? `  ·  Batch ${item.batch_number}` : ''}
                                </Text>
                                {item.quota_reached != null ? (
                                    <Text style={{ fontSize: 12, color: mutedColor, marginTop: 2 }}>
                                        Quota Reached: {item.quota_reached}
                                    </Text>
                                ) : null}
                                <Text style={{ fontSize: 11, color: faintColor, marginTop: 4 }}>
                                    {item.processed_at ? new Date(item.processed_at).toLocaleString() : '---'}
                                </Text>
                            </>
                        ) : (
                            <>
                                <Text style={{ fontSize: 14, fontWeight: '600', color: '#3b82f6', fontVariant: ['tabular-nums'] }}>
                                    {item.ref_number || `#${item.id}`}
                                </Text>
                                {isBonus && item.type ? (
                                    <View style={{ alignSelf: 'flex-start', marginTop: 4, paddingHorizontal: 8, paddingVertical: 2, borderRadius: 6, backgroundColor: item.type === 'Bonus_payout' ? '#fee2e2' : '#dcfce7' }}>
                                        <Text style={{ fontSize: 10, fontWeight: '700', textTransform: 'uppercase', color: item.type === 'Bonus_payout' ? '#b91c1c' : '#15803d' }}>
                                            {item.type === 'Bonus_payout' ? 'Payout' : 'Add Bonus'}
                                        </Text>
                                    </View>
                                ) : null}
                                {item.agent_name ? (
                                    <Text style={{ fontSize: 12, color: mutedColor, marginTop: 2 }}>{item.agent_name}</Text>
                                ) : null}
                                {item.commission_id_list ? (
                                    <Text style={{ fontSize: 12, color: '#60a5fa', marginTop: 2 }} numberOfLines={1}>
                                        {item.commission_id_list.split(',').map((id: string) => `#${id.trim()}`).join(', ')}
                                    </Text>
                                ) : null}
                                <Text style={{ fontSize: 11, color: faintColor, marginTop: 4 }}>
                                    {item.created_at ? new Date(item.created_at).toLocaleString() : '---'}
                                    {item.created_by ? `  ·  ${item.created_by}` : ''}
                                </Text>
                            </>
                        )}
                    </View>
                    <View style={{ alignItems: 'flex-end' }}>{amountNode}</View>
                </View>
            </TouchableOpacity>
        );
    };

    const headerTitle = activeTab === 'incentives' ? 'Incentives History' : activeTab === 'bonus' ? 'Bonus History' : 'Commission History';

    if (isLoading && payoutHistory.length === 0) {
        return (
            <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: pageBg }}>
                <ActivityIndicator size="large" color={primaryColor} />
            </View>
        );
    }

    return (
        <View style={{ flex: 1, backgroundColor: pageBg }}>
            {/* Header */}
            <View style={{
                paddingTop: isTablet ? 16 : 60,
                paddingHorizontal: 16,
                paddingBottom: 12,
                backgroundColor: cardBg,
                borderBottomWidth: 1,
                borderBottomColor: borderColor,
            }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
                    <Text style={{ fontSize: 18, fontWeight: '700', color: textColor }}>{headerTitle}</Text>
                    {activeTab !== 'incentives' ? (
                        <TouchableOpacity
                            onPress={handleOpenPayout}
                            style={{ flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 6, backgroundColor: primaryColor }}
                        >
                            <Plus size={14} color="#ffffff" />
                            <Text style={{ color: '#ffffff', fontSize: 13, fontWeight: '600' }}>Add</Text>
                        </TouchableOpacity>
                    ) : null}
                </View>

                {/* Search + actions */}
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                    <GlobalSearch
                        searchQuery={searchTerm}
                        setSearchQuery={setSearchTerm}
                        isDarkMode={isDarkMode}
                        colorPalette={colorPalette}
                        placeholder="Search history..."
                    />
                    <TouchableOpacity
                        onPress={() => setShowFilters((v) => !v)}
                        style={{ padding: 9, borderRadius: 6, borderWidth: 1, borderColor: (dateFrom || dateTo) ? primaryColor : borderColor, backgroundColor: cardBg }}
                    >
                        <Filter size={18} color={(dateFrom || dateTo) ? primaryColor : mutedColor} />
                    </TouchableOpacity>
                    <TouchableOpacity
                        onPress={handleExport}
                        style={{ padding: 9, borderRadius: 6, borderWidth: 1, borderColor, backgroundColor: cardBg }}
                    >
                        <Download size={18} color={mutedColor} />
                    </TouchableOpacity>
                    <TouchableOpacity
                        onPress={handleRefresh}
                        style={{ padding: 9, borderRadius: 6, borderWidth: 1, borderColor, backgroundColor: cardBg }}
                    >
                        <RefreshCw size={18} color={primaryColor} />
                    </TouchableOpacity>
                </View>

                {/* Date range filters */}
                {showFilters ? (
                    <View style={{ marginTop: 12 }}>
                        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                            <Text style={{ fontSize: 11, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 1, color: faintColor }}>
                                Date Range
                            </Text>
                            {(dateFrom || dateTo) ? (
                                <TouchableOpacity onPress={() => { setDateFrom(null); setDateTo(null); }}>
                                    <Text style={{ fontSize: 12, fontWeight: '600', color: primaryColor }}>Clear</Text>
                                </TouchableOpacity>
                            ) : null}
                        </View>
                        <View style={{ flexDirection: 'row', gap: 8 }}>
                            <View style={{ flex: 1 }}>
                                <Text style={{ fontSize: 11, color: mutedColor, marginBottom: 4 }}>From</Text>
                                <TouchableOpacity
                                    onPress={() => setShowFromPicker(true)}
                                    style={{ paddingHorizontal: 10, paddingVertical: 8, borderRadius: 6, borderWidth: 1, borderColor: dateFrom ? primaryColor : borderColor, backgroundColor: cardBg }}
                                >
                                    <Text style={{ fontSize: 13, color: dateFrom ? textColor : faintColor }}>
                                        {dateFrom ? toDateString(dateFrom) : 'Select date'}
                                    </Text>
                                </TouchableOpacity>
                                {showFromPicker ? (
                                    <DateTimePicker
                                        value={dateFrom || new Date()}
                                        mode="date"
                                        display={Platform.OS === 'ios' ? 'spinner' : 'default'}
                                        onChange={(_e, date) => {
                                            setShowFromPicker(false);
                                            if (date) setDateFrom(date);
                                        }}
                                    />
                                ) : null}
                            </View>
                            <View style={{ flex: 1 }}>
                                <Text style={{ fontSize: 11, color: mutedColor, marginBottom: 4 }}>To</Text>
                                <TouchableOpacity
                                    onPress={() => setShowToPicker(true)}
                                    style={{ paddingHorizontal: 10, paddingVertical: 8, borderRadius: 6, borderWidth: 1, borderColor: dateTo ? primaryColor : borderColor, backgroundColor: cardBg }}
                                >
                                    <Text style={{ fontSize: 13, color: dateTo ? textColor : faintColor }}>
                                        {dateTo ? toDateString(dateTo) : 'Select date'}
                                    </Text>
                                </TouchableOpacity>
                                {showToPicker ? (
                                    <DateTimePicker
                                        value={dateTo || new Date()}
                                        mode="date"
                                        display={Platform.OS === 'ios' ? 'spinner' : 'default'}
                                        onChange={(_e, date) => {
                                            setShowToPicker(false);
                                            if (date) setDateTo(date);
                                        }}
                                    />
                                ) : null}
                            </View>
                        </View>
                    </View>
                ) : null}
            </View>

            {/* Tabs */}
            <View style={{ flexDirection: 'row', backgroundColor: cardBg, borderBottomWidth: 1, borderBottomColor: borderColor }}>
                <TabButton id="payouts" label="Commission" icon={History} />
                <TabButton id="incentives" label="Incentives" icon={Gift} />
                <TabButton id="bonus" label="Bonus" icon={Gift} />
            </View>

            {/* List */}
            <FlatList
                data={filteredData}
                keyExtractor={(item, index) => String(item.id ?? index)}
                renderItem={renderCard}
                contentContainerStyle={{ padding: 16, paddingBottom: 96 }}
                refreshControl={
                    <RefreshControl refreshing={refreshing} onRefresh={handleRefresh} tintColor={primaryColor} colors={[primaryColor]} />
                }
                ListEmptyComponent={
                    <View style={{ paddingVertical: 48, alignItems: 'center' }}>
                        <Text style={{ fontSize: 14, fontStyle: 'italic', color: faintColor }}>No matching records found</Text>
                        {activeTab === 'incentives' && incentiveHistory.length === 0 ? (
                            <Text style={{ fontSize: 12, color: faintColor, marginTop: 4, textAlign: 'center' }}>
                                Incentive history is not available on mobile yet.
                            </Text>
                        ) : null}
                    </View>
                }
            />

            {/* Details overlay */}
            {showDetails && selectedRecord ? (
                <CommissionDetails
                    data={selectedRecord}
                    type={activeTab}
                    isMobile
                    onClose={() => { setShowDetails(false); setSelectedRecord(null); }}
                    onPrevious={currentIndex > 0 ? handlePrevious : undefined}
                    onNext={currentIndex !== -1 && currentIndex < filteredData.length - 1 ? handleNext : undefined}
                />
            ) : null}

            {/* Commission Payout Modal */}
            <CommissionPayoutModal
                isOpen={showPayoutModal}
                onClose={() => setShowPayoutModal(false)}
                onSuccess={() => { setShowPayoutModal(false); fetchData(); }}
            />

            {/* Incentives Payout Modal */}
            <IncentivesPayoutModal
                isOpen={showIncentiveModal}
                onClose={() => setShowIncentiveModal(false)}
                onSuccess={() => { setShowIncentiveModal(false); handleRefresh(); }}
            />

            {/* Bonus Payout Modal */}
            <BonusPayoutModal
                isOpen={showBonusModal}
                onClose={() => setShowBonusModal(false)}
                onSuccess={() => { setShowBonusModal(false); fetchData(); }}
            />
        </View>
    );
};

export default Commission;
