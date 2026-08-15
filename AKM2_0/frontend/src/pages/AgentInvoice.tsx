import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Search, RefreshCw, Download, FileText, Users, User as UserIcon, Loader2,
    ChevronsLeft, ChevronsRight, ChevronLeft, ChevronRight, X, Eye, Calendar, Play
} from 'lucide-react';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { agentInvoiceService, AgentInvoiceRecord } from '../services/agentInvoiceService';
import { exportToCSV } from '../utils/exportUtils';

const hexToRgba = (hex: string, opacity: number) => {
    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result
        ? `rgba(${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}, ${opacity})`
        : hex;
};

interface ColumnDefinition {
    key: string;
    label: string;
    minWidth: number;
    align?: 'right' | 'left' | 'center';
}

const columns: ColumnDefinition[] = [
    { key: 'invoice_number', label: 'Invoice No.', minWidth: 150 },
    { key: 'invoice_type', label: 'Type', minWidth: 100 },
    { key: 'billed_to', label: 'Team / Agent', minWidth: 200 },
    { key: 'invoice_date', label: 'Invoice Date', minWidth: 130 },
    { key: 'period', label: 'Billing Period', minWidth: 190 },
    { key: 'total_customers', label: 'Customers', minWidth: 110, align: 'right' },
    { key: 'total_amount', label: 'Total Amount', minWidth: 140, align: 'right' },
    { key: 'subtotal', label: 'Subtotal', minWidth: 140, align: 'right' },
    { key: 'status', label: 'Status', minWidth: 120 },
    { key: 'actions', label: 'Actions', minWidth: 130, align: 'center' },
];

const STATUSES = ['Generated', 'Sent', 'Paid', 'Cancelled'];

const formatCurrency = (amount: number): string =>
    `₱${Number(amount || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,')}`;

const formatDate = (value?: string | null): string => {
    if (!value) return '-';
    const date = new Date(value);
    if (isNaN(date.getTime())) return '-';
    return date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
};

/** Status pill, coloured the way the billing invoice list colours its own. */
const StatusBadge: React.FC<{ status: string; isDarkMode: boolean }> = ({ status, isDarkMode }) => {
    const tone: Record<string, string> = {
        Generated: isDarkMode ? 'bg-blue-900/40 text-blue-300' : 'bg-blue-50 text-blue-700',
        Sent: isDarkMode ? 'bg-amber-900/40 text-amber-300' : 'bg-amber-50 text-amber-700',
        Paid: isDarkMode ? 'bg-emerald-900/40 text-emerald-300' : 'bg-emerald-50 text-emerald-700',
        Cancelled: isDarkMode ? 'bg-rose-900/40 text-rose-300' : 'bg-rose-50 text-rose-700',
    };

    return (
        <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold ${tone[status] || (isDarkMode ? 'bg-gray-800 text-gray-300' : 'bg-gray-100 text-gray-700')}`}>
            {status}
        </span>
    );
};

const AgentInvoice: React.FC = () => {
    const [isDarkMode, setIsDarkMode] = useState<boolean>(true);
    const [isMobile, setIsMobile] = useState<boolean>(window.innerWidth < 768);
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);

    const [records, setRecords] = useState<AgentInvoiceRecord[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [searchTerm, setSearchTerm] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');

    const [currentPage, setCurrentPage] = useState(1);
    const [itemsPerPage, setItemsPerPage] = useState(25);
    const [totalCount, setTotalCount] = useState(0);
    const [lastPage, setLastPage] = useState(1);

    const [selected, setSelected] = useState<AgentInvoiceRecord | null>(null);
    const [isLoadingDetail, setIsLoadingDetail] = useState(false);
    const [pdfPending, setPdfPending] = useState<number | null>(null);
    const [isGenerating, setIsGenerating] = useState(false);

    // Whether this user may generate invoices or change a status. The server is
    // the authority — this only decides whether the control is worth showing.
    const [canManage, setCanManage] = useState(false);

    const primaryColor = colorPalette?.primary || '#7c3aed';
    const searchDebounce = useRef<number | null>(null);

    useEffect(() => {
        const onResize = () => setIsMobile(window.innerWidth < 768);
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    useEffect(() => {
        const stored = localStorage.getItem('theme');
        if (stored) setIsDarkMode(stored === 'dark');

        const fetchPalette = async () => {
            try {
                setColorPalette(await settingsColorPaletteService.getActive());
            } catch (err) {
                console.error('[AgentInvoice] Failed to fetch color palette:', err);
            }
        };
        fetchPalette();

        const onPalette = () => fetchPalette();
        window.addEventListener('palette-updated', onPalette);
        return () => window.removeEventListener('palette-updated', onPalette);
    }, []);

    useEffect(() => {
        try {
            const raw = localStorage.getItem('authData') || localStorage.getItem('user');
            const auth = raw ? JSON.parse(raw) : null;
            const role = String(auth?.role?.role_name || auth?.role_name || auth?.role || '').toLowerCase();
            const roleId = auth?.role_id ?? auth?.user?.role_id;
            setCanManage(roleId === 7 || ['admin', 'administrator', 'billing', 'superadmin'].includes(role));
        } catch {
            setCanManage(false);
        }
    }, []);

    const load = useCallback(async (page: number, quiet = false) => {
        if (quiet) setIsRefreshing(true); else setIsLoading(true);
        setError(null);

        try {
            const response = await agentInvoiceService.list({
                search: searchTerm,
                status: statusFilter,
                type: typeFilter,
                date_from: dateFrom,
                date_to: dateTo,
                page,
                per_page: itemsPerPage,
            });

            if (response?.success) {
                setRecords(response.data || []);
                setTotalCount(response.meta?.total ?? 0);
                setLastPage(response.meta?.last_page ?? 1);
            } else {
                setRecords([]);
                setError('Failed to load invoices.');
            }
        } catch (err: any) {
            console.error('[AgentInvoice] Failed to load invoices:', err);
            setRecords([]);
            setError(err?.response?.data?.message || 'Failed to load invoices.');
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, [searchTerm, statusFilter, typeFilter, dateFrom, dateTo, itemsPerPage]);

    // Filters reset to the first page: staying on page 4 of a narrower result
    // set would show an empty table.
    useEffect(() => {
        setCurrentPage(1);
    }, [searchTerm, statusFilter, typeFilter, dateFrom, dateTo, itemsPerPage]);

    useEffect(() => {
        if (searchDebounce.current) window.clearTimeout(searchDebounce.current);
        searchDebounce.current = window.setTimeout(() => load(currentPage), 250);
        return () => {
            if (searchDebounce.current) window.clearTimeout(searchDebounce.current);
        };
    }, [load, currentPage]);

    const handleOpenDetails = async (record: AgentInvoiceRecord) => {
        setSelected(record);

        // The list already carries the customers, but re-reading gives the
        // freshest set and keeps the modal correct if the list is stale.
        setIsLoadingDetail(true);
        try {
            const response = await agentInvoiceService.get(record.id);
            if (response?.success) setSelected(response.data);
        } catch (err) {
            console.error('[AgentInvoice] Failed to load invoice details:', err);
        } finally {
            setIsLoadingDetail(false);
        }
    };

    const handlePdf = async (record: AgentInvoiceRecord, download: boolean) => {
        setPdfPending(record.id);
        try {
            const blob = await agentInvoiceService.pdfBlob(record.id, download);
            const url = window.URL.createObjectURL(blob);

            if (download) {
                const link = document.createElement('a');
                link.href = url;
                link.download = `${record.invoice_number}.pdf`;
                document.body.appendChild(link);
                link.click();
                link.remove();
            } else {
                window.open(url, '_blank', 'noopener');
            }

            // Released on the next tick so the tab has taken the reference.
            window.setTimeout(() => window.URL.revokeObjectURL(url), 60_000);
        } catch (err: any) {
            console.error('[AgentInvoice] Failed to open the PDF:', err);
            window.alert(err?.response?.data?.message || 'The invoice PDF could not be opened.');
        } finally {
            setPdfPending(null);
        }
    };

    const handleGenerate = async () => {
        if (isGenerating) return;
        if (!window.confirm('Generate the referral invoices for last week now?\n\nInvoices already raised for that week are left alone.')) return;

        setIsGenerating(true);
        try {
            const response = await agentInvoiceService.generate();
            window.alert(response?.message || 'Invoice generation finished.');
            await load(1);
        } catch (err: any) {
            window.alert(err?.response?.data?.message || 'Invoice generation failed.');
        } finally {
            setIsGenerating(false);
        }
    };

    const handleExport = () => {
        if (!records.length) return;

        // The visible columns, minus the actions column, which has nothing to export.
        const exportColumns = columns
            .filter(c => c.key !== 'actions')
            .map(c => ({ key: c.key, label: c.label }));

        exportToCSV('agent-invoices', exportColumns, records, (record, key) => {
            switch (key) {
                case 'invoice_type': return record.invoice_type === 'team' ? 'Team' : 'Solo';
                case 'period':       return `${record.period_start || ''} - ${record.period_end || ''}`;
                case 'total_amount': return record.total_amount;
                case 'subtotal':     return record.subtotal;
                default:             return (record as any)[key] ?? '';
            }
        });
    };

    const summary = useMemo(() => ({
        invoices: totalCount,
        customers: records.reduce((sum, r) => sum + (r.total_customers || 0), 0),
        amount: records.reduce((sum, r) => sum + (r.subtotal || 0), 0),
    }), [records, totalCount]);

    const cell = (record: AgentInvoiceRecord, key: string): React.ReactNode => {
        switch (key) {
            case 'invoice_number':
                return <span className="font-semibold">{record.invoice_number}</span>;
            case 'invoice_type':
                return (
                    <span className="inline-flex items-center gap-1.5">
                        {record.invoice_type === 'team'
                            ? <Users className="h-3.5 w-3.5 opacity-70" />
                            : <UserIcon className="h-3.5 w-3.5 opacity-70" />}
                        {record.invoice_type === 'team' ? 'Team' : 'Solo'}
                    </span>
                );
            case 'billed_to':
                return record.billed_to;
            case 'invoice_date':
                return formatDate(record.invoice_date);
            case 'period':
                return `${formatDate(record.period_start)} – ${formatDate(record.period_end)}`;
            case 'total_customers':
                return record.total_customers;
            case 'total_amount':
                return formatCurrency(record.total_amount);
            case 'subtotal':
                return <span className="font-semibold">{formatCurrency(record.subtotal)}</span>;
            case 'status':
                return <StatusBadge status={record.status} isDarkMode={isDarkMode} />;
            case 'actions':
                return (
                    <div className="flex items-center justify-center gap-1.5">
                        <button
                            onClick={(e) => { e.stopPropagation(); handleOpenDetails(record); }}
                            title="View details"
                            className={`p-1.5 rounded transition-colors ${isDarkMode ? 'hover:bg-gray-700' : 'hover:bg-gray-100'}`}
                        >
                            <Eye className="h-4 w-4" />
                        </button>
                        <button
                            onClick={(e) => { e.stopPropagation(); handlePdf(record, false); }}
                            disabled={pdfPending === record.id}
                            title="Open PDF"
                            className={`p-1.5 rounded transition-colors disabled:opacity-50 ${isDarkMode ? 'hover:bg-gray-700' : 'hover:bg-gray-100'}`}
                        >
                            {pdfPending === record.id
                                ? <Loader2 className="h-4 w-4 animate-spin" />
                                : <FileText className="h-4 w-4" />}
                        </button>
                        <button
                            onClick={(e) => { e.stopPropagation(); handlePdf(record, true); }}
                            disabled={pdfPending === record.id}
                            title="Download PDF"
                            className={`p-1.5 rounded transition-colors disabled:opacity-50 ${isDarkMode ? 'hover:bg-gray-700' : 'hover:bg-gray-100'}`}
                        >
                            <Download className="h-4 w-4" />
                        </button>
                    </div>
                );
            default:
                return '-';
        }
    };

    return (
        <div className={`h-full flex flex-col overflow-hidden ${isDarkMode ? 'bg-gray-950' : 'bg-gray-50'}`}>
            {/* Header */}
            <div className={`px-4 py-3 border-b flex-shrink-0 ${isDarkMode ? 'bg-gray-900 border-gray-700' : 'bg-white border-gray-200'}`}>
                <div className="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <h2 className={`text-lg font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                            Agent Invoices
                        </h2>
                        <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                            Weekly referral invoices, raised every Monday for the week just ended
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        {canManage && (
                            <button
                                onClick={handleGenerate}
                                disabled={isGenerating}
                                title="Generate last week's invoices now"
                                className="px-3 py-2 rounded-lg text-sm text-white font-medium transition-colors disabled:opacity-50 flex items-center gap-2"
                                style={{ backgroundColor: primaryColor }}
                            >
                                {isGenerating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Play className="h-4 w-4" />}
                                {isMobile ? '' : 'Generate'}
                            </button>
                        )}
                        <button
                            onClick={handleExport}
                            disabled={isLoading || records.length === 0}
                            title="Export to CSV"
                            className="p-2 rounded-lg transition-all flex items-center justify-center shadow-sm disabled:opacity-50 border"
                            style={{ backgroundColor: '#ffffff', borderColor: primaryColor, color: primaryColor }}
                            onMouseEnter={(e) => { if (records.length) e.currentTarget.style.backgroundColor = hexToRgba(primaryColor, 0.1); }}
                            onMouseLeave={(e) => { e.currentTarget.style.backgroundColor = '#ffffff'; }}
                        >
                            <Download className="h-5 w-5" />
                        </button>
                        <button
                            onClick={() => load(currentPage, true)}
                            disabled={isLoading || isRefreshing}
                            title="Refresh"
                            className="p-2 rounded-lg transition-all flex items-center justify-center shadow-sm disabled:opacity-50 border"
                            style={{ backgroundColor: '#ffffff', borderColor: primaryColor, color: primaryColor }}
                        >
                            <RefreshCw className={`h-5 w-5 ${(isLoading || isRefreshing) ? 'animate-spin' : ''}`} />
                        </button>
                    </div>
                </div>

                {/* Summary tiles */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                    {[
                        { label: 'Invoices', value: String(summary.invoices), icon: FileText },
                        { label: 'Customers on this page', value: String(summary.customers), icon: Users },
                        { label: 'Subtotal on this page', value: formatCurrency(summary.amount), icon: Calendar },
                    ].map(tile => (
                        <div
                            key={tile.label}
                            className={`rounded-lg border px-3 py-2.5 flex items-center gap-3 ${isDarkMode ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'}`}
                        >
                            <div className="p-2 rounded-lg" style={{ backgroundColor: hexToRgba(primaryColor, 0.12), color: primaryColor }}>
                                <tile.icon className="h-4 w-4" />
                            </div>
                            <div className="min-w-0">
                                <div className={`text-[11px] ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{tile.label}</div>
                                <div className={`text-sm font-bold truncate ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{tile.value}</div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Filters */}
            <div className={`px-4 py-3 border-b flex-shrink-0 flex flex-wrap items-center gap-2 ${isDarkMode ? 'bg-gray-900 border-gray-700' : 'bg-white border-gray-200'}`}>
                <div className="relative flex-1 min-w-[200px]">
                    <Search className={`absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`} />
                    <input
                        type="text"
                        value={searchTerm}
                        onChange={(e) => setSearchTerm(e.target.value)}
                        placeholder="Search invoice number, team or agent..."
                        className={`w-full pl-9 pr-3 py-2 rounded-lg border text-sm focus:outline-none ${isDarkMode ? 'bg-gray-800 border-gray-700 text-white placeholder-gray-500' : 'bg-white border-gray-300 text-gray-900'}`}
                    />
                </div>

                <select
                    value={typeFilter}
                    onChange={(e) => setTypeFilter(e.target.value)}
                    className={`px-3 py-2 rounded-lg border text-sm focus:outline-none ${isDarkMode ? 'bg-gray-800 border-gray-700 text-white' : 'bg-white border-gray-300 text-gray-900'}`}
                >
                    <option value="">All types</option>
                    <option value="team">Team</option>
                    <option value="solo">Solo</option>
                </select>

                <select
                    value={statusFilter}
                    onChange={(e) => setStatusFilter(e.target.value)}
                    className={`px-3 py-2 rounded-lg border text-sm focus:outline-none ${isDarkMode ? 'bg-gray-800 border-gray-700 text-white' : 'bg-white border-gray-300 text-gray-900'}`}
                >
                    <option value="">All statuses</option>
                    {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
                </select>

                <input
                    type="date"
                    value={dateFrom}
                    onChange={(e) => setDateFrom(e.target.value)}
                    title="Invoice date from"
                    className={`px-3 py-2 rounded-lg border text-sm focus:outline-none ${isDarkMode ? 'bg-gray-800 border-gray-700 text-white' : 'bg-white border-gray-300 text-gray-900'}`}
                />
                <input
                    type="date"
                    value={dateTo}
                    onChange={(e) => setDateTo(e.target.value)}
                    title="Invoice date to"
                    className={`px-3 py-2 rounded-lg border text-sm focus:outline-none ${isDarkMode ? 'bg-gray-800 border-gray-700 text-white' : 'bg-white border-gray-300 text-gray-900'}`}
                />

                {(searchTerm || statusFilter || typeFilter || dateFrom || dateTo) && (
                    <button
                        onClick={() => { setSearchTerm(''); setStatusFilter(''); setTypeFilter(''); setDateFrom(''); setDateTo(''); }}
                        className={`px-3 py-2 rounded-lg text-sm border transition-colors ${isDarkMode ? 'border-gray-700 text-gray-300 hover:bg-gray-800' : 'border-gray-300 text-gray-600 hover:bg-gray-50'}`}
                    >
                        Clear
                    </button>
                )}
            </div>

            {/* Table */}
            <div className="flex-1 overflow-auto">
                {isLoading ? (
                    <div className="h-full flex items-center justify-center">
                        <Loader2 className="h-8 w-8 animate-spin" style={{ color: primaryColor }} />
                    </div>
                ) : error ? (
                    <div className={`h-full flex flex-col items-center justify-center gap-2 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                        <X className="h-8 w-8 text-rose-500" />
                        <p className="text-sm">{error}</p>
                    </div>
                ) : records.length === 0 ? (
                    <div className={`h-full flex flex-col items-center justify-center gap-2 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                        <FileText className="h-10 w-10 opacity-40" />
                        <p className="text-sm font-medium">No invoices yet</p>
                        <p className="text-xs">Invoices are raised automatically every Monday for the week just ended.</p>
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className={`border-b sticky top-0 z-10 ${isDarkMode ? 'bg-gray-900 border-gray-700' : 'bg-gray-50 border-gray-200'}`}>
                                {columns.map(col => (
                                    <th
                                        key={col.key}
                                        style={{ minWidth: col.minWidth }}
                                        className={`px-4 py-3 text-[11px] font-bold uppercase tracking-wider whitespace-nowrap ${
                                            col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left'
                                        } ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}
                                    >
                                        {col.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {records.map(record => (
                                <tr
                                    key={record.id}
                                    onClick={() => handleOpenDetails(record)}
                                    className={`border-b cursor-pointer transition-colors ${isDarkMode ? 'border-gray-800 hover:bg-gray-800/60 text-gray-200' : 'border-gray-100 hover:bg-gray-50 text-gray-700'}`}
                                >
                                    {columns.map(col => (
                                        <td
                                            key={col.key}
                                            style={{ minWidth: col.minWidth }}
                                            className={`px-4 py-3 whitespace-nowrap ${
                                                col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left'
                                            }`}
                                        >
                                            {cell(record, col.key)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* Pagination */}
            {totalCount > 0 && (
                <div className={`flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t flex-shrink-0 ${isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'}`}>
                    <div className={`flex flex-wrap items-center gap-3 sm:gap-4 text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
                        <div className="flex items-center gap-2">
                            <span>Show</span>
                            <select
                                value={itemsPerPage}
                                onChange={(e) => setItemsPerPage(Number(e.target.value))}
                                className={`px-2 py-1 rounded border text-sm focus:outline-none ${isDarkMode ? 'bg-gray-800 border-gray-700 text-white' : 'bg-white border-gray-300 text-gray-900'}`}
                            >
                                {[10, 25, 50, 100].map(n => <option key={n} value={n}>{n}</option>)}
                            </select>
                            <span>entries</span>
                        </div>
                        <span>
                            Showing <span className="font-medium">{(currentPage - 1) * itemsPerPage + 1}</span> to{' '}
                            <span className="font-medium">{Math.min(currentPage * itemsPerPage, totalCount)}</span> of{' '}
                            <span className="font-medium">{totalCount}</span> results
                        </span>
                    </div>

                    <div className="flex items-center gap-2">
                        <button onClick={() => setCurrentPage(1)} disabled={currentPage === 1} title="First page"
                            className={`p-1 rounded transition-colors disabled:opacity-40 ${isDarkMode ? 'text-white hover:bg-gray-800' : 'text-gray-700 hover:bg-gray-100'}`}>
                            <ChevronsLeft className="h-5 w-5" />
                        </button>
                        <button onClick={() => setCurrentPage(p => Math.max(1, p - 1))} disabled={currentPage === 1} title="Previous"
                            className={`p-1 rounded transition-colors disabled:opacity-40 ${isDarkMode ? 'text-white hover:bg-gray-800' : 'text-gray-700 hover:bg-gray-100'}`}>
                            <ChevronLeft className="h-5 w-5" />
                        </button>
                        <span className={`px-3 text-sm ${isDarkMode ? 'text-gray-300' : 'text-gray-600'}`}>
                            Page {currentPage} of {lastPage}
                        </span>
                        <button onClick={() => setCurrentPage(p => Math.min(lastPage, p + 1))} disabled={currentPage >= lastPage} title="Next"
                            className={`p-1 rounded transition-colors disabled:opacity-40 ${isDarkMode ? 'text-white hover:bg-gray-800' : 'text-gray-700 hover:bg-gray-100'}`}>
                            <ChevronRight className="h-5 w-5" />
                        </button>
                        <button onClick={() => setCurrentPage(lastPage)} disabled={currentPage >= lastPage} title="Last page"
                            className={`p-1 rounded transition-colors disabled:opacity-40 ${isDarkMode ? 'text-white hover:bg-gray-800' : 'text-gray-700 hover:bg-gray-100'}`}>
                            <ChevronsRight className="h-5 w-5" />
                        </button>
                    </div>
                </div>
            )}

            {/* Details */}
            {selected && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setSelected(null)}>
                    <div
                        onClick={(e) => e.stopPropagation()}
                        className={`w-full max-w-4xl max-h-[90vh] rounded-2xl shadow-2xl overflow-hidden flex flex-col ${isDarkMode ? 'bg-gray-900' : 'bg-white'}`}
                    >
                        <div className={`px-5 py-4 border-b flex items-start justify-between gap-4 ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}>
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <h3 className={`text-lg font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                                        {selected.invoice_number}
                                    </h3>
                                    <StatusBadge status={selected.status} isDarkMode={isDarkMode} />
                                </div>
                                <p className={`text-sm mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                                    {selected.invoice_type === 'team' ? 'Team invoice' : 'Solo agent invoice'} · {selected.billed_to}
                                </p>
                            </div>
                            <button onClick={() => setSelected(null)} className={`p-1.5 rounded ${isDarkMode ? 'hover:bg-gray-800 text-gray-400' : 'hover:bg-gray-100 text-gray-500'}`}>
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        <div className="flex-1 overflow-y-auto px-5 py-4">
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                                {[
                                    { label: 'Invoice date', value: formatDate(selected.invoice_date) },
                                    { label: 'Billing period', value: `${formatDate(selected.period_start)} – ${formatDate(selected.period_end)}` },
                                    { label: 'Customers', value: String(selected.total_customers) },
                                    { label: 'Unit price', value: formatCurrency(selected.unit_price) },
                                ].map(f => (
                                    <div key={f.label}>
                                        <div className={`text-[11px] uppercase tracking-wide ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{f.label}</div>
                                        <div className={`text-sm font-medium mt-0.5 ${isDarkMode ? 'text-gray-200' : 'text-gray-800'}`}>{f.value}</div>
                                    </div>
                                ))}
                            </div>

                            <h4 className={`text-sm font-bold mb-2 ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                                Referred customers
                            </h4>

                            {isLoadingDetail ? (
                                <div className="py-10 flex justify-center">
                                    <Loader2 className="h-6 w-6 animate-spin" style={{ color: primaryColor }} />
                                </div>
                            ) : (
                                <div className={`rounded-lg border overflow-hidden ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}>
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className={isDarkMode ? 'bg-gray-800' : 'bg-gray-50'}>
                                                {['Customer', 'Referred by', 'Installed', 'Unit price', 'Qty', 'Total'].map((h, i) => (
                                                    <th key={h} className={`px-3 py-2 text-[11px] font-bold uppercase tracking-wider ${i >= 3 ? 'text-right' : 'text-left'} ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                                                        {h}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(selected.customers || []).map(c => (
                                                <tr key={c.id} className={`border-t ${isDarkMode ? 'border-gray-800 text-gray-200' : 'border-gray-100 text-gray-700'}`}>
                                                    <td className="px-3 py-2 font-medium">{c.customer_name}</td>
                                                    <td className="px-3 py-2">{c.referred_by_name || '-'}</td>
                                                    <td className="px-3 py-2">{formatDate(c.installed_date)}</td>
                                                    <td className="px-3 py-2 text-right">{formatCurrency(c.unit_price)}</td>
                                                    <td className="px-3 py-2 text-right">{c.quantity}</td>
                                                    <td className="px-3 py-2 text-right font-medium">{formatCurrency(c.total)}</td>
                                                </tr>
                                            ))}
                                            {(selected.customers || []).length === 0 && (
                                                <tr>
                                                    <td colSpan={6} className={`px-3 py-6 text-center text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                                                        No customers on this invoice.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {/* Totals, in the same order as the printed invoice */}
                            <div className="mt-4 flex justify-end">
                                <div className={`w-full sm:w-80 rounded-lg border overflow-hidden ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}>
                                    {[
                                        ['Total client installed', String(selected.total_customers)],
                                        ['Installation fee', formatCurrency(selected.installation_fee)],
                                        ['Total amount', formatCurrency(selected.total_amount)],
                                        ['Commission', formatCurrency(selected.commission)],
                                    ].map(([label, value]) => (
                                        <div key={label} className={`flex items-center justify-between px-3 py-2 text-sm ${isDarkMode ? 'text-gray-300' : 'text-gray-600'}`}>
                                            <span>{label}</span>
                                            <span className="font-medium">{value}</span>
                                        </div>
                                    ))}
                                    <div className="flex items-center justify-between px-3 py-2.5 text-sm font-bold text-white" style={{ backgroundColor: primaryColor }}>
                                        <span>Subtotal</span>
                                        <span>{formatCurrency(selected.subtotal)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className={`px-5 py-3 border-t flex items-center justify-end gap-2 ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}>
                            <button
                                onClick={() => handlePdf(selected, false)}
                                disabled={pdfPending === selected.id}
                                className={`px-3 py-2 rounded-lg text-sm border transition-colors disabled:opacity-50 flex items-center gap-2 ${isDarkMode ? 'border-gray-700 text-gray-200 hover:bg-gray-800' : 'border-gray-300 text-gray-700 hover:bg-gray-50'}`}
                            >
                                <FileText className="h-4 w-4" /> View PDF
                            </button>
                            <button
                                onClick={() => handlePdf(selected, true)}
                                disabled={pdfPending === selected.id}
                                className="px-3 py-2 rounded-lg text-sm text-white font-medium transition-colors disabled:opacity-50 flex items-center gap-2"
                                style={{ backgroundColor: primaryColor }}
                            >
                                <Download className="h-4 w-4" /> Download
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default AgentInvoice;
