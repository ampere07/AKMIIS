import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import { View, Text, Pressable, ActivityIndicator, Linking, useWindowDimensions, RefreshControl, Modal, Animated, StyleSheet, DeviceEventEmitter } from 'react-native';
import * as WebBrowser from 'expo-web-browser';
import { Download, FileText, Clock, File } from 'lucide-react-native';
import { FlashList } from '@shopify/flash-list';
import { useCustomerDataContext } from '../contexts/CustomerDataContext';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { API_BASE_URL } from '../config/api';
import { useTabBarOffset } from '../hooks/useTabBarOffset';

interface SOARecord {
    id: number;
    statement_date?: string;
    statement_no?: string;
    print_link?: string;
    total_amount_due?: number;
}

interface PaymentRecord {
    id: string;
    date: string;
    reference: string;
    amount: number;
    source: 'Online' | 'Manual';
    status?: string;
}

interface BillsProps {
    initialTab?: 'soa' | 'invoices' | 'payments';
}

const formatDate = (dateStr?: string) => {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return `${months[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
};

const formatCurrency = (amount: number) => {
    const isNegative = (amount || 0) < 0;
    const formatted = Math.abs(amount || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    return `₱${isNegative ? '-' : ''}${formatted}`;
};

/**
 * Open a statement PDF in an in-app Safari sheet, so reading a bill does not
 * send the customer out of the app; the sheet's share button still saves or
 * prints it. Falls back to Safari itself if the sheet cannot be shown.
 */
const openPdf = (url: string) => {
    WebBrowser.openBrowserAsync(url).catch(() => {
        Linking.openURL(url).catch((err) => console.error('Failed to open PDF:', err));
    });
};

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#f9fafb' },
    loadingContainer: { padding: 32, flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#f9fafb' },
    tabRow: { flexDirection: 'row', width: '100%', justifyContent: 'center', gap: 6, marginBottom: 0 },
    tabBase: {
        flex: 1, paddingTop: 14, paddingBottom: 14, paddingHorizontal: 4,
        flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8,
        borderRadius: 12, position: 'relative',
    },
    tabActive: {
        backgroundColor: '#ffffff',
        borderTopLeftRadius: 12,
        borderTopRightRadius: 12,
        borderBottomLeftRadius: 0,
        borderBottomRightRadius: 0,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.05,
        shadowRadius: 10,
        elevation: 2
    },
    tabText: { fontSize: 13, fontWeight: '800' },
    tabTextActive: { color: '#111827' },
    tabTextInactive: { color: '#9ca3af' },
    tabIndicator: { position: 'absolute', bottom: 0, width: '50%', height: 3, borderRadius: 3 },
    contentContainer: {
        paddingHorizontal: 16, paddingTop: 0,
        backgroundColor: '#ffffff',
    },
    card: {
        paddingVertical: 18,
    },
    cardRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
    divider: { height: 1.5, backgroundColor: '#f1f5f9', marginVertical: 14 },
    labelText: { fontSize: 11, color: '#6b7280', fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.5 },
    valueText: { fontSize: 15, fontWeight: '800', color: '#111827', marginTop: 2 },
    amountText: { fontSize: 18, fontWeight: '900', marginTop: 2 },
    alignEnd: { alignItems: 'flex-end' },
    pdfBtnBase: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 14, paddingVertical: 10, borderRadius: 12, borderWidth: 1.5 },
    pdfText: { fontSize: 13, fontWeight: '800' },
    statusBadge: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 8 },
    statusText: { fontSize: 11, fontWeight: '800' },
    refText: { fontSize: 13, fontFamily: 'Menlo', color: '#64748b', marginTop: 2 },
    paymentAmount: { fontSize: 18, fontWeight: '900', color: '#16a34a', marginTop: 2 },
    paginationRow: { flexDirection: 'row', justifyContent: 'center', alignItems: 'center', paddingTop: 10, paddingBottom: 40, gap: 16 },
    paginationBtn: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 8, paddingVertical: 12, borderRadius: 12, minWidth: 40, justifyContent: 'center' },
    paginationBtnDisabled: { backgroundColor: '#f3f4f6', opacity: 0.5 },
    paginationText: { fontSize: 14, fontWeight: '800' },
    pageIndicator: { fontSize: 14, color: '#111827', fontWeight: '800' },
    emptyContainer: { paddingVertical: 80, alignItems: 'center', paddingHorizontal: 40 },
    emptyTitle: { fontSize: 20, fontWeight: '800', color: '#6b7280', marginBottom: 8 },
    modalOverlay: { flex: 1, justifyContent: 'center', alignItems: 'center' },
    modalBackdrop: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(0,0,0,0.5)' },
});

const BillCard = React.memo(({ record, type, primaryColor, onDownload, isGenerating, isAnyGenerating }: { record: any, type: 'soa' | 'invoice', primaryColor: string, onDownload: (record: any) => void, isGenerating: boolean, isAnyGenerating?: boolean }) => {
    const isSoa = type === 'soa';
    const date = isSoa ? record.statement_date : record.invoice_date;
    const amount = isSoa ? record.total_amount_due : record.invoice_balance;
    const label = isSoa ? 'Statement Date' : 'Invoice Date';
    const amountLabel = isSoa ? 'Amount Due' : 'Balance';

    return (
        <View style={styles.card}>
            <View style={[styles.cardRow, { marginBottom: 14 }]}>
                <View>
                    <Text style={styles.labelText}>{label}</Text>
                    <Text style={styles.valueText}>{formatDate(date)}</Text>
                </View>
                <View style={styles.alignEnd}>
                    <Text style={styles.labelText}>Ref No.</Text>
                    <Text style={styles.valueText}>#{record.id}</Text>
                </View>
            </View>
            <View style={styles.cardRow}>
                <View>
                    <Text style={styles.labelText}>{amountLabel}</Text>
                    <Text style={[styles.amountText, { color: isSoa ? primaryColor : '#111827' }]}>
                        {formatCurrency(amount || 0)}
                    </Text>
                </View>
                {isSoa ? (
                    <Pressable accessibilityRole="button" accessibilityLabel={`Statement #${record.id} PDF`}
                        onPress={() => onDownload(record)}
                        disabled={isAnyGenerating}
                        style={[styles.pdfBtnBase, { backgroundColor: primaryColor + '10', borderColor: primaryColor + '20' }, isAnyGenerating && { opacity: 0.5 }]}
                    >
                        {isGenerating ? (
                            <ActivityIndicator size="small" color={primaryColor} />
                        ) : (
                            <Download width={14} height={14} color={primaryColor} />
                        )}
                        <Text style={[styles.pdfText, { color: primaryColor }]}>{isGenerating ? '...' : 'PDF'}</Text>
                    </Pressable>
                ) : (
                    <View style={[
                        styles.statusBadge,
                        { backgroundColor: record.status?.toUpperCase() === 'PAID' ? '#f0fdf4' : record.status?.toUpperCase() === 'UNPAID' ? '#fef2f2' : '#f3f4f6' }
                    ]}>
                        <Text style={[
                            styles.statusText,
                            { color: record.status?.toUpperCase() === 'PAID' ? '#15803d' : record.status?.toUpperCase() === 'UNPAID' ? '#ef4444' : '#374151' }
                        ]}>
                            {(record.status || 'UNKNOWN').toUpperCase()}
                        </Text>
                    </View>
                )}
            </View>
            <View style={styles.divider} />
        </View>
    );
});

const HistoryCard = React.memo(({ record }: { record: PaymentRecord }) => {
    const isPositive = record.status === 'Completed' || record.status === 'PAID';
    return (
        <View style={styles.card}>
            <View style={[styles.cardRow, { marginBottom: 14 }]}>
                <View>
                    <Text style={styles.labelText}>Payment Date</Text>
                    <Text style={styles.valueText}>{formatDate(record.date)}</Text>
                </View>
                <View style={[styles.alignEnd, { flex: 1, marginLeft: 16 }]}>
                    <Text style={styles.labelText}>Ref: {record.source}</Text>
                    <Text numberOfLines={1} ellipsizeMode="tail" style={styles.refText}>{record.reference}</Text>
                </View>
            </View>
            <View style={styles.cardRow}>
                <View>
                    <Text style={styles.labelText}>Amount Paid</Text>
                    <Text style={styles.paymentAmount}>{formatCurrency(record.amount)}</Text>
                </View>
                <View style={[styles.statusBadge, { backgroundColor: isPositive ? '#f0fdf4' : '#f3f4f6' }]}>
                    <Text style={[styles.statusText, { color: isPositive ? '#15803d' : '#374151' }]}>
                        {(record.status || 'Posted').toUpperCase()}
                    </Text>
                </View>
            </View>
            <View style={styles.divider} />
        </View>
    );
});

/**
 * Statements, invoices and payment history.
 *
 * The full app's copy of this screen also carries a complete Pay Now flow —
 * state, a swipe-to-dismiss sheet and four payment modals — with nothing on
 * the screen that ever opens it. Paying lives on the dashboard's balance card,
 * so that unreachable copy is not carried over.
 */
const Bills: React.FC<BillsProps> = ({ initialTab = 'soa' }) => {
    const { width, height } = useWindowDimensions();
    const isMobile = width < 768;
    const isShort = height < 700;
    const { lift } = useTabBarOffset();
    const { customerDetail, payments: paymentRecords, soaRecords, invoiceRecords, isLoading: contextLoading, silentRefresh } = useCustomerDataContext();
    const [activeTab, setActiveTab] = useState<'soa' | 'invoices' | 'payments'>(initialTab);

    const [tabMeasurements, setTabMeasurements] = useState<Record<'soa' | 'invoices' | 'payments', { x: number, width: number }>>({
        soa: { x: 0, width: 0 },
        invoices: { x: 0, width: 0 },
        payments: { x: 0, width: 0 },
    });
    const slideAnim = useRef(new Animated.Value(0)).current;

    useEffect(() => {
        const measurement = tabMeasurements[activeTab];
        if (measurement && measurement.width > 0) {
            Animated.spring(slideAnim, {
                toValue: measurement.x,
                useNativeDriver: false,
                tension: 65,
                friction: 10
            }).start();
        }
    }, [activeTab, tabMeasurements, slideAnim]);

    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(() => settingsColorPaletteService.getActiveSync());
    const primaryColor = colorPalette?.primary || '#ef4444';
    const [refreshing, setRefreshing] = useState(false);
    const [isGeneratingPDF, setIsGeneratingPDF] = useState<number | null>(null);

    /**
     * A freshly generated PDF waiting for the "Generating PDF" overlay to close.
     *
     * Opened from the overlay's onDismiss rather than straight away: on iOS a
     * sheet presented on top of a modal is torn down with it, so opening the PDF
     * while the overlay was still on screen would close it again at once.
     */
    const pendingPdfUrl = useRef<string | null>(null);

    const ITEMS_PER_PAGE = 5;
    const [currentPage, setCurrentPage] = useState(0);

    useEffect(() => {
        let cancelled = false;
        settingsColorPaletteService.getActive()
            .then((palette) => { if (!cancelled) setColorPalette(palette); })
            .catch(() => { /* keep the fallback colour */ });
        silentRefresh();

        const paletteSub = DeviceEventEmitter.addListener('colorPaletteChanged', (newPalette) => {
            setColorPalette(newPalette);
        });

        return () => {
            cancelled = true;
            paletteSub.remove();
        };
    }, []);

    useEffect(() => {
        setCurrentPage(0);
    }, [activeTab]);

    const onRefresh = useCallback(async () => {
        setRefreshing(true);
        try {
            await silentRefresh();
        } catch (error) {
            console.error('Refresh failed:', error);
        } finally {
            setRefreshing(false);
        }
    }, [silentRefresh]);

    const currentRecords = useMemo(() => {
        if (activeTab === 'soa') return soaRecords;
        if (activeTab === 'invoices') return invoiceRecords;
        return paymentRecords;
    }, [activeTab, soaRecords, invoiceRecords, paymentRecords]);

    const paginatedData = useMemo(() => {
        const start = currentPage * ITEMS_PER_PAGE;
        return currentRecords.slice(start, start + ITEMS_PER_PAGE);
    }, [currentRecords, currentPage]);

    const totalPages = Math.max(1, Math.ceil(currentRecords.length / ITEMS_PER_PAGE));

    const renderPagination = useCallback(() => {
        if (currentRecords.length <= ITEMS_PER_PAGE) return null;
        const isPrevDisabled = currentPage === 0;
        const isNextDisabled = currentPage >= totalPages - 1;
        return (
            <View style={styles.paginationRow}>
                <Pressable accessibilityRole="button"
                    onPress={() => setCurrentPage(Math.max(0, currentPage - 1))}
                    disabled={isPrevDisabled}
                    style={[styles.paginationBtn, isPrevDisabled ? styles.paginationBtnDisabled : { backgroundColor: primaryColor + '10' }]}
                >
                    <Text style={[styles.paginationText, {
                        color: isPrevDisabled ? '#9ca3af' : primaryColor,
                        fontSize: 18,
                        fontWeight: 'bold'
                    }]}>{"<"}</Text>
                </Pressable>

                <Text style={styles.pageIndicator}>
                    {currentPage + 1} / {totalPages}
                </Text>

                <Pressable accessibilityRole="button"
                    onPress={() => setCurrentPage(Math.min(totalPages - 1, currentPage + 1))}
                    disabled={isNextDisabled}
                    style={[styles.paginationBtn, isNextDisabled ? styles.paginationBtnDisabled : { backgroundColor: primaryColor + '10' }]}
                >
                    <Text style={[styles.paginationText, {
                        color: isNextDisabled ? '#9ca3af' : primaryColor,
                        fontSize: 18,
                        fontWeight: 'bold'
                    }]}>{">"}</Text>
                </Pressable>
            </View>
        );
    }, [primaryColor, currentPage, totalPages, currentRecords.length]);

    if (contextLoading && !customerDetail) return (
        <View style={styles.loadingContainer}>
            <ActivityIndicator size="large" color="#111827" />
        </View>
    );

    const handleDownloadPDF = async (record: SOARecord) => {
        if (record.print_link) {
            openPdf(record.print_link);
            return;
        }

        setIsGeneratingPDF(record.id);
        try {
            const response = await fetch(`${API_BASE_URL}/statement-of-accounts/${record.id}/generate-pdf`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                }
            });
            const result = await response.json();
            const pdfUrl = result.pdf_url || result.data?.url || result.data?.print_link;
            if (result.success && pdfUrl) {
                pendingPdfUrl.current = pdfUrl;
                void silentRefresh();
            } else {
                console.error('PDF Generation failed:', result.message);
            }
        } catch (error) {
            console.error('Error generating PDF:', error);
        } finally {
            setIsGeneratingPDF(null);
        }
    };

    const handleGeneratingModalDismissed = () => {
        const url = pendingPdfUrl.current;
        pendingPdfUrl.current = null;
        if (url) openPdf(url);
    };

    return (
        <View style={styles.container}>
            <View style={{ paddingHorizontal: isMobile ? 16 : 24, paddingTop: isMobile ? (isShort ? 20 : 60) : 16, gap: isShort ? 12 : 20 }}>
                <View accessibilityRole="tablist" style={[styles.tabRow, { position: 'relative' }]}>
                    <Animated.View style={[
                        styles.tabActive,
                        {
                            position: 'absolute',
                            height: '100%',
                            width: tabMeasurements[activeTab]?.width || 0,
                            left: 0,
                            bottom: 0,
                            opacity: tabMeasurements[activeTab]?.width > 0 ? 1 : 0,
                            transform: [{ translateX: slideAnim }],
                            alignItems: 'center',
                        }
                    ]}>
                        <View style={[styles.tabIndicator, { backgroundColor: primaryColor }]} />
                    </Animated.View>

                    <Pressable accessibilityRole="tab" aria-selected={activeTab === 'soa'}
                        onLayout={(e) => {
                            const { x, width } = e.nativeEvent.layout;
                            setTabMeasurements(prev => ({ ...prev, soa: { x, width } }));
                        }}
                        onPress={() => setActiveTab('soa')}
                        style={[styles.tabBase, { zIndex: 5, backgroundColor: 'transparent' }]}
                    >
                        <FileText width={16} height={16} color={activeTab === 'soa' ? primaryColor : '#9ca3af'} />
                        <Text style={[styles.tabText, activeTab === 'soa' ? styles.tabTextActive : styles.tabTextInactive]}>SOA</Text>
                    </Pressable>
                    <Pressable accessibilityRole="tab" aria-selected={activeTab === 'invoices'}
                        onLayout={(e) => {
                            const { x, width } = e.nativeEvent.layout;
                            setTabMeasurements(prev => ({ ...prev, invoices: { x, width } }));
                        }}
                        onPress={() => setActiveTab('invoices')}
                        style={[styles.tabBase, { zIndex: 5, backgroundColor: 'transparent' }]}
                    >
                        <File width={16} height={16} color={activeTab === 'invoices' ? primaryColor : '#9ca3af'} />
                        <Text style={[styles.tabText, activeTab === 'invoices' ? styles.tabTextActive : styles.tabTextInactive]}>Invoices</Text>
                    </Pressable>
                    <Pressable accessibilityRole="tab" aria-selected={activeTab === 'payments'}
                        onLayout={(e) => {
                            const { x, width } = e.nativeEvent.layout;
                            setTabMeasurements(prev => ({ ...prev, payments: { x, width } }));
                        }}
                        onPress={() => setActiveTab('payments')}
                        style={[styles.tabBase, { zIndex: 5, backgroundColor: 'transparent' }]}
                    >
                        <Clock width={16} height={16} color={activeTab === 'payments' ? primaryColor : '#9ca3af'} />
                        <Text style={[styles.tabText, activeTab === 'payments' ? styles.tabTextActive : styles.tabTextInactive]}>History</Text>
                    </Pressable>
                </View>
            </View>

            <FlashList
                data={paginatedData as any[]}
                style={{ flex: 1, backgroundColor: '#ffffff' }}
                keyExtractor={(item: any) => String(item.id)}
                contentContainerStyle={{ ...styles.contentContainer, paddingBottom: 120 + lift }}
                refreshControl={
                    <RefreshControl
                        refreshing={refreshing}
                        onRefresh={onRefresh}
                        tintColor={primaryColor}
                    />
                }
                ListEmptyComponent={() => (
                    <View style={styles.emptyContainer}>
                        <Text style={styles.emptyTitle}>
                            {activeTab === 'soa' ? 'No Statements' : activeTab === 'invoices' ? 'No Invoices' : 'No History'}
                        </Text>
                    </View>
                )}
                renderItem={({ item }) => (
                    activeTab === 'payments'
                        ? <HistoryCard record={item as any} />
                        : <BillCard
                            record={item}
                            type={activeTab === 'soa' ? 'soa' : 'invoice'}
                            primaryColor={primaryColor}
                            onDownload={handleDownloadPDF}
                            isGenerating={isGeneratingPDF === item.id}
                            isAnyGenerating={isGeneratingPDF !== null}
                          />
                )}
                ListFooterComponent={renderPagination}
            />

            {/* Loading Modal for PDF Generation */}
            <Modal
                visible={isGeneratingPDF !== null}
                transparent={true}
                animationType="fade"
                onDismiss={handleGeneratingModalDismissed}
            >
                <View style={styles.modalOverlay}>
                    <View style={styles.modalBackdrop} />
                    <View style={{ backgroundColor: 'white', padding: 32, borderRadius: 24, alignItems: 'center', shadowColor: '#000', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.25, shadowRadius: 10, width: '80%', maxWidth: 320 }}>
                        <ActivityIndicator size="large" color={primaryColor} />
                        <Text style={{ marginTop: 20, fontSize: 18, fontWeight: '800', color: '#111827' }}>Generating PDF</Text>
                        <Text style={{ marginTop: 8, fontSize: 14, color: '#6b7280', textAlign: 'center', lineHeight: 20 }}>Please wait a moment while we prepare your document...</Text>
                    </View>
                </View>
            </Modal>
        </View>
    );
};

export default Bills;
