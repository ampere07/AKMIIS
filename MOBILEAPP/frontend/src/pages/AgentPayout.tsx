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
import { Picker } from '@react-native-picker/picker';
import DateTimePicker from '@react-native-community/datetimepicker';
import { Download, RefreshCw, Plus, Filter } from 'lucide-react-native';
import { exportToPDF } from '../utils/exportUtils';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { useCommissionStore } from '../store/commissionStore';
import { CommissionData, PayoutHistoryData } from '../types/commission';
import CommissionDetails from '../components/CommissionDetails';
import AgentPayoutModal from '../modals/AgentPayoutModal';
import { useAgentStore } from '../store/agentStore';
import { userService } from '../services/userService';
import { User } from '../types/api';
import GlobalSearch from './globalfunctions/GlobalSearch';

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

const AgentPayout: React.FC = () => {
    const {
        payoutHistory,
        isLoading,
        fetchCommissions,
        fetchUpdates,
    } = useCommissionStore();

    const { width } = useWindowDimensions();
    const isTablet = width >= 768;

    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [showFilters, setShowFilters] = useState(false);

    // Date range state
    const [dateFrom, setDateFrom] = useState<Date | null>(null);
    const [dateTo, setDateTo] = useState<Date | null>(null);
    const [showFromPicker, setShowFromPicker] = useState(false);
    const [showToPicker, setShowToPicker] = useState(false);

    const [agentList, setAgentList] = useState<User[]>([]);
    const [selectedAgentId, setSelectedAgentId] = useState<string | number>('all');

    const [selectedRecord, setSelectedRecord] = useState<CommissionData | PayoutHistoryData | null>(null);
    const [showDetails, setShowDetails] = useState(false);
    const [showAgentPayoutModal, setShowAgentPayoutModal] = useState(false);

    const { fetchAgents } = useAgentStore();

    const fetchData = async () => {
        await fetchCommissions(true);
    };

    const handleRefresh = async () => {
        setRefreshing(true);
        try {
            await fetchUpdates();
        } catch (err) {
            console.error('[AgentPayout Page] Refresh failed:', err);
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

        const fetchAgentsData = async () => {
            try {
                const response = await userService.getUsersByRole('agent');
                if (response.success && response.data && response.data.length > 0) {
                    setAgentList(response.data);
                } else {
                    const responseById = await userService.getUsersByRoleId(4);
                    if (responseById.success && responseById.data) {
                        setAgentList(responseById.data);
                    }
                }
            } catch {
                setAgentList([]);
            }
        };
        fetchAgentsData();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Optional periodic refresh (15 min) — Pusher realtime is a no-op stub in RN.
    useEffect(() => {
        const intervalId = setInterval(() => {
            fetchUpdates().catch((err) => console.error('[AgentPayout Page] Poll failed:', err));
        }, 15 * 60 * 1000);
        return () => clearInterval(intervalId);
    }, [fetchUpdates]);

    const filteredData = React.useMemo(() => {
        const normalizedQuery = searchTerm.toLowerCase().replace(/\s+/g, '');
        return payoutHistory.filter((row: any) => {
            if (selectedAgentId !== 'all') {
                if (row.agent_id && String(row.agent_id) !== String(selectedAgentId)) return false;
            }

            const checkValue = (val: any): boolean => {
                if (val === null || val === undefined) return false;
                if (typeof val === 'object') return Object.values(val).some((v) => checkValue(v));
                return String(val).toLowerCase().replace(/\s+/g, '').includes(normalizedQuery);
            };
            const matchesSearch = searchTerm === '' || checkValue(row);

            if (dateFrom || dateTo) {
                const dateVal = row.date || row.created_at;
                if (!dateVal) return matchesSearch;
                const itemDate = new Date(dateVal).getTime();
                if (dateFrom && itemDate < dateFrom.getTime()) return false;
                if (dateTo && itemDate > dateTo.getTime()) return false;
            }
            return matchesSearch;
        });
    }, [payoutHistory, searchTerm, selectedAgentId, dateFrom, dateTo]);

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
        setShowAgentPayoutModal(true);
    };

    const handleExport = () => {
        const columns = [
            { key: 'id', label: 'ID' },
            { key: 'type', label: 'Type' },
            { key: 'ref_number', label: 'Ref Number' },
            { key: 'total_amount', label: 'Total Amount' },
            { key: 'commission_id_list', label: 'Job Orders' },
            { key: 'created_by', label: 'Processed By' },
        ];

        const getExportValue = (row: any, key: string) => {
            const val = row[key];
            if (key === 'total_amount' || key === 'amount' || key === 'incentive_value') return formatAmount(val);
            if (key === 'created_at' || key === 'date' || key === 'processed_at') return val ? new Date(val).toLocaleString() : '-';
            return val ?? '-';
        };

        // RN exportToPDF falls back to CSV share and does not accept a colorPalette arg.
        exportToPDF('Agent Payout History Report', 'agent_payout_history_export', columns, filteredData, getExportValue);
    };

    const primaryColor = colorPalette?.primary || '#7c3aed';
    const pageBg = '#f9fafb';
    const cardBg = '#ffffff';
    const borderColor = '#e5e7eb';
    const textColor = '#111827';
    const mutedColor = '#6b7280';
    const faintColor = '#9ca3af';

    // Summary cards for a selected agent.
    const selectedAgent = selectedAgentId !== 'all'
        ? agentList.find((a) => a.id === selectedAgentId)
        : null;
    const agentBalance: any = (selectedAgent as any)?.agent_balance || {};
    const summaryBalance = Number(agentBalance.balance || 0);
    const summaryIncentives = Number(agentBalance.incentives || 0);
    const summaryBonus = Number(agentBalance.bonus || agentBalance.Bonus || 0);

    const renderCard = ({ item }: { item: any }) => {
        const isPayoutType = item.type === 'incentives_payout';
        const isAddType = item.type === 'incentives';
        const amtColor = isPayoutType ? '#ef4444' : isAddType ? '#16a34a' : textColor;
        const sign = isPayoutType ? '-' : isAddType ? '+' : '';

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
                        <Text style={{ fontSize: 14, fontWeight: '600', color: '#3b82f6', fontVariant: ['tabular-nums'] }}>
                            {item.ref_number || `#${item.id}`}
                        </Text>
                        {item.type ? (
                            <View style={{ alignSelf: 'flex-start', marginTop: 4, paddingHorizontal: 8, paddingVertical: 2, borderRadius: 6, backgroundColor: isPayoutType ? '#fee2e2' : '#dcfce7' }}>
                                <Text style={{ fontSize: 10, fontWeight: '700', textTransform: 'uppercase', color: isPayoutType ? '#b91c1c' : '#15803d' }}>
                                    {isPayoutType ? 'Payout' : isAddType ? 'Add Incentives' : String(item.type)}
                                </Text>
                            </View>
                        ) : null}
                        {item.commission_id_list ? (
                            <Text style={{ fontSize: 12, color: '#60a5fa', marginTop: 4 }} numberOfLines={1}>
                                {item.commission_id_list.split(',').map((id: string) => `#${id.trim()}`).join(', ')}
                            </Text>
                        ) : null}
                        <Text style={{ fontSize: 11, color: faintColor, marginTop: 4 }}>
                            {item.created_at ? new Date(item.created_at).toLocaleString() : '---'}
                            {item.created_by ? `  ·  ${item.created_by}` : ''}
                        </Text>
                    </View>
                    <View style={{ alignItems: 'flex-end' }}>
                        <Text style={{ fontSize: 15, fontWeight: '700', color: amtColor }}>
                            {sign}{formatAmount(item.total_amount)}
                        </Text>
                    </View>
                </View>
            </TouchableOpacity>
        );
    };

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
                    <Text style={{ fontSize: 18, fontWeight: '700', color: textColor }}>Payout History</Text>
                    <TouchableOpacity
                        onPress={handleOpenPayout}
                        style={{ flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 6, backgroundColor: primaryColor }}
                    >
                        <Plus size={14} color="#ffffff" />
                        <Text style={{ color: '#ffffff', fontSize: 13, fontWeight: '600' }}>Add</Text>
                    </TouchableOpacity>
                </View>

                {/* Agent filter (replaces desktop sidebar agent list) */}
                <View style={{ marginBottom: 8, borderWidth: 1, borderColor, borderRadius: 6, backgroundColor: cardBg }}>
                    <Picker
                        selectedValue={String(selectedAgentId)}
                        onValueChange={(val) => setSelectedAgentId(val === 'all' ? 'all' : Number(val))}
                        dropdownIconColor={textColor}
                        style={{ color: textColor }}
                    >
                        <Picker.Item label={`All Agents (${agentList.length})`} value="all" />
                        {agentList.map((agent) => {
                            const agentName = `${agent.first_name || ''} ${agent.middle_initial || ''} ${agent.last_name || ''}`.replace(/\s+/g, ' ').trim();
                            return (
                                <Picker.Item key={String(agent.id)} label={agentName || agent.username} value={String(agent.id)} />
                            );
                        })}
                    </Picker>
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

            {/* Summary cards for a selected agent */}
            {selectedAgent ? (
                <View style={{ flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingTop: 12 }}>
                    <View style={{ flex: 1, padding: 10, borderRadius: 8, borderWidth: 1, borderColor, backgroundColor: cardBg }}>
                        <Text style={{ fontSize: 11, color: mutedColor, marginBottom: 2 }}>Balance</Text>
                        <Text style={{ fontSize: 15, fontWeight: '700', color: primaryColor }}>
                            ₱{summaryBalance.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                        </Text>
                    </View>
                    <View style={{ flex: 1, padding: 10, borderRadius: 8, borderWidth: 1, borderColor, backgroundColor: cardBg }}>
                        <Text style={{ fontSize: 11, color: mutedColor, marginBottom: 2 }}>Incentives</Text>
                        <Text style={{ fontSize: 15, fontWeight: '700', color: '#16a34a' }}>
                            ₱{summaryIncentives.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                        </Text>
                    </View>
                    <View style={{ flex: 1, padding: 10, borderRadius: 8, borderWidth: 1, borderColor, backgroundColor: cardBg }}>
                        <Text style={{ fontSize: 11, color: mutedColor, marginBottom: 2 }}>Bonus</Text>
                        <Text style={{ fontSize: 15, fontWeight: '700', color: '#3b82f6' }}>
                            ₱{summaryBonus.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                        </Text>
                    </View>
                </View>
            ) : null}

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
                    </View>
                }
            />

            {/* Details overlay */}
            {showDetails && selectedRecord ? (
                <CommissionDetails
                    data={selectedRecord}
                    type="payouts"
                    isMobile
                    onClose={() => { setShowDetails(false); setSelectedRecord(null); }}
                    onPrevious={currentIndex > 0 ? handlePrevious : undefined}
                    onNext={currentIndex !== -1 && currentIndex < filteredData.length - 1 ? handleNext : undefined}
                />
            ) : null}

            {/* Agent Payout Modal */}
            <AgentPayoutModal
                isOpen={showAgentPayoutModal}
                onClose={() => setShowAgentPayoutModal(false)}
                onSuccess={() => {
                    setShowAgentPayoutModal(false);
                    handleRefresh();
                }}
            />
        </View>
    );
};

export default AgentPayout;
