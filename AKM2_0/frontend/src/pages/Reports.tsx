import React, { useState, useEffect, useRef, useMemo } from 'react';
import {
    RefreshCw, Filter, ArrowUp, ArrowDown, ExternalLink,
    ChevronLeft, ChevronRight, X, Settings2, FileText, Plus, DownloadCloud
} from 'lucide-react';
import GlobalSearch from './globalfunctions/GlobalSearch';
import apiClient from '../config/api';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import AddReportModal from '../modals/AddReportModal';

// ─── Types ────────────────────────────────────────────────────────────────────

interface ReportData {
    id: number;
    report_name: string;
    report_type: string;
    report_schedule: string;
    report_time: string;
    day: string;
    send_to: string;
    date_range: string;
    created_by: string;
    created_at: string;
    file_url?: string;
}

const ALL_COLUMNS = [
    { key: 'id', label: 'ID' },
    { key: 'report_name', label: 'Report Name' },
    { key: 'report_type', label: 'Report Type' },
    { key: 'report_schedule', label: 'Schedule' },
    { key: 'report_time', label: 'Time' },
    { key: 'day', label: 'Day' },
    { key: 'send_to', label: 'Send To' },
    { key: 'date_range', label: 'Date Range' },
    { key: 'created_by', label: 'Created By' },
    { key: 'created_at', label: 'Created At' },
];

const DEFAULT_VISIBLE = [
    'id', 'report_name', 'report_type', 'report_schedule', 'day', 'report_time', 'send_to', 'date_range', 'created_by', 'created_at'
];

// itemsPerPage is now managed as state inside the component

// ─── Helpers ──────────────────────────────────────────────────────────────────

const formatDate = (d?: string | null) => {
    if (!d) return '—';
    try {
        const date = new Date(d);
        if (isNaN(date.getTime())) return d;
        const mm = String(date.getMonth() + 1).padStart(2, '0');
        const dd = String(date.getDate()).padStart(2, '0');
        const yyyy = date.getFullYear();
        let hours = date.getHours();
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        const hh = String(hours).padStart(2, '0');
        return `${mm}/${dd}/${yyyy} ${hh}:${minutes} ${ampm}`;
    } catch {
        return d;
    }
};

const getCellValue = (row: ReportData, key: string): string => {
    const raw = (row as any)[key];
    if (raw == null) return '—';
    if (key === 'created_at') return formatDate(raw);

    if (key === 'report_time' && raw) {
        // Simple 24h to 12h formatting if needed, or return raw
        try {
            const [h, m] = String(raw).split(':');
            let hours = parseInt(h, 10);
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            return `${hours}:${m} ${ampm} GMT+8`;
        } catch {
            return String(raw);
        }
    }

    return String(raw);
};

// ─── Role Guard Helper ────────────────────────────────────────────────────────

const hasReportsAccess = (): boolean => {
    try {
        const authData = localStorage.getItem('authData');
        if (!authData) return false;
        const user = JSON.parse(authData);
        const roleId = String(user.role_id ?? '');
        const role = (user.role ?? '').toLowerCase().trim();
        return roleId === '1' || roleId === '7' || role === 'administrator' || role === 'superadmin';
    } catch {
        return false;
    }
};

// ─── Main Component ───────────────────────────────────────────────────────────

const Reports: React.FC = () => {
    const [isDarkMode, setIsDarkMode] = useState(true);
    const [isMobile, setIsMobile] = useState<boolean>(window.innerWidth < 768);
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
    const [accessDenied] = useState<boolean>(!hasReportsAccess());
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);

    // Data
    const [rows, setRows] = useState<ReportData[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [isRefreshing, setIsRefreshing] = useState(false);

    // UI state
    const [searchQuery, setSearchQuery] = useState('');
    const [currentPage, setCurrentPage] = useState(1);
    const [itemsPerPage, setItemsPerPage] = useState(25);
    const [sortColumn, setSortColumn] = useState<string | null>('created_at');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
    const [visibleColumns, setVisibleColumns] = useState<string[]>(DEFAULT_VISIBLE);
    const [showColumnPicker, setShowColumnPicker] = useState(false);

    const columnPickerRef = useRef<HTMLDivElement>(null);
    const scrollRef = useRef<HTMLDivElement>(null);

    // ── Color palette & dark mode ──────────────────────────────────────────────

    useEffect(() => {
        settingsColorPaletteService.getActive().then(p => setColorPalette(p)).catch(() => { });
    }, []);

    useEffect(() => {
        const check = () => setIsDarkMode(localStorage.getItem('theme') !== 'light');
        check();
        const obs = new MutationObserver(check);
        obs.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        return () => obs.disconnect();
    }, []);

    useEffect(() => {
        const handleResize = () => {
            setIsMobile(window.innerWidth < 768);
        };
        handleResize();
        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    // ── Click outside ─────────────────────────────────────────────────────────

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (columnPickerRef.current && !columnPickerRef.current.contains(e.target as Node)) {
                setShowColumnPicker(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    // ── Fetch data ─────────────────────────────────────────────────────────────

    const fetchReports = async (silent = false) => {
        if (!silent) setIsLoading(true);
        else setIsRefreshing(true);
        try {
            const res = await apiClient.get<{ success: boolean; data: ReportData[] }>('/reports');
            if (res.data.success && Array.isArray(res.data.data)) {
                setRows(res.data.data);
            }
            // Always update current page based on new length
            setCurrentPage(1);
        } catch (err) {
            console.error('Failed to fetch reports:', err);
            // Optionally set error state here
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    };

    useEffect(() => {
        if (!accessDenied) fetchReports();
    }, [accessDenied]);

    // ── Toggle columns ─────────────────────────────────────────────────────────

    const toggleColumn = (key: string) => {
        setVisibleColumns(prev => {
            if (prev.includes(key)) {
                if (prev.length <= 1) return prev; // keep at least one
                return prev.filter(c => c !== key);
            }
            return [...prev, key];
        });
    };

    const handleSort = (key: string) => {
        if (sortColumn === key) {
            setSortDir(p => p === 'asc' ? 'desc' : 'asc');
        } else {
            setSortColumn(key);
            setSortDir('asc');
        }
    };

    // ── Derived Data ──────────────────────────────────────────────────────────

    const filtered = useMemo(() => {
        let f = rows;

        // Search
        if (searchQuery.trim()) {
            const lower = searchQuery.toLowerCase();
            f = f.filter(r =>
                r.report_name?.toLowerCase().includes(lower) ||
                r.report_type?.toLowerCase().includes(lower) ||
                r.send_to?.toLowerCase().includes(lower)
            );
        }

        // Sort
        if (sortColumn) {
            f.sort((a, b) => {
                const va = (a as any)[sortColumn];
                const vb = (b as any)[sortColumn];
                if (va == null && vb == null) return 0;
                if (va == null) return sortDir === 'asc' ? -1 : 1;
                if (vb == null) return sortDir === 'asc' ? 1 : -1;

                if (typeof va === 'number' && typeof vb === 'number') {
                    return sortDir === 'asc' ? va - vb : vb - va;
                }

                // string compare
                const sa = String(va).toLowerCase();
                const sb = String(vb).toLowerCase();
                if (sa < sb) return sortDir === 'asc' ? -1 : 1;
                if (sa > sb) return sortDir === 'asc' ? 1 : -1;
                return 0;
            });
        }
        return f;
    }, [rows, searchQuery, sortColumn, sortDir]);

    const paginated = useMemo(() => {
        const start = (currentPage - 1) * itemsPerPage;
        return filtered.slice(start, start + itemsPerPage);
    }, [filtered, currentPage, itemsPerPage]);

    const totalPages = Math.max(1, Math.ceil(filtered.length / itemsPerPage));

    useEffect(() => {
        setCurrentPage(1);
    }, [itemsPerPage]);

    // Scroll to top on page change
    useEffect(() => {
        if (scrollRef.current) {
            scrollRef.current.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }, [currentPage]);

    // Ordered columns based on ALL_COLUMNS to preserve original index
    const orderedVisibleColumns = ALL_COLUMNS.filter(c => visibleColumns.includes(c.key));

    // ── Guards ────────────────────────────────────────────────────────────────

    if (accessDenied) {
        return (
            <div className={`h-screen flex items-center justify-center ${isDarkMode ? 'bg-gray-900' : 'bg-gray-50'}`}>
                <div className={`flex flex-col items-center gap-4 p-10 rounded-2xl shadow-xl border ${isDarkMode ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'}`}>
                    <div className="w-16 h-16 rounded-full flex items-center justify-center" style={{ backgroundColor: '#ef444420' }}>
                        <X size={32} stroke="#ef4444" strokeWidth={2.5} />
                    </div>
                    <div className="text-center">
                        <h2 className={`text-xl font-bold mb-1 ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                            Access Denied
                        </h2>
                        <p className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                            You do not have permission to view the reports page.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    // ── Render Helpers ────────────────────────────────────────────────────────

    const primary = colorPalette?.primary || '#7c3aed';
    const bg = isDarkMode ? 'bg-gray-950' : 'bg-white';
    const headerBg = isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-gray-50 border-gray-200';
    const text = isDarkMode ? 'text-white' : 'text-gray-900';
    const subText = isDarkMode ? 'text-gray-400' : 'text-gray-500';
    const thCls = isDarkMode ? 'bg-gray-900 text-gray-300 border-gray-800' : 'bg-gray-50 text-gray-600 border-gray-200';
    const tdCls = isDarkMode ? 'text-gray-300 border-gray-800' : 'text-gray-700 border-gray-200';
    const rowHover = isDarkMode ? 'group-hover:bg-gray-800' : 'group-hover:bg-gray-100/70';
    const inputCls = isDarkMode
        ? 'bg-gray-800 border-gray-700 text-white placeholder-gray-500 hover:border-gray-600'
        : 'bg-white border-gray-300 text-gray-900 placeholder-gray-400 hover:border-gray-400';
    const cardBg = isDarkMode ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200';

    return (
        <>
            <div className={`flex flex-col h-full overflow-hidden ${bg}`}>

                {/* ── Top Bar ─────────────────────────────────────────────────────────── */}
                <div className={`flex flex-col sm:flex-row sm:items-center justify-between px-5 py-3 border-b gap-3 flex-shrink-0 ${headerBg}`}>
                    <div className="flex items-center justify-between sm:justify-start gap-3 w-full sm:w-auto">
                        <div className="flex items-center gap-3">
                            <FileText size={20} style={{ color: primary }} />
                            <h1 className={`text-lg font-bold ${text}`}>Reports</h1>
                            <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${isDarkMode ? 'bg-gray-700 text-gray-300' : 'bg-gray-200 text-gray-600'}`}>
                                {filtered.length.toLocaleString()} records
                            </span>
                        </div>
                        {isMobile && (
                            <button
                                onClick={() => setIsAddModalOpen(true)}
                                className="flex items-center gap-1.5 px-3 py-2 text-sm text-white rounded-md transition-opacity hover:opacity-90 font-medium flex-shrink-0"
                                style={{ backgroundColor: primary }}
                            >
                                <Plus size={14} />
                                Add
                            </button>
                        )}
                    </div>

                    <div className="flex items-center gap-2 overflow-x-auto scrollbar-none pb-1 -mb-1 w-full sm:w-auto">
                        {!isMobile && (
                            <button
                                onClick={() => setIsAddModalOpen(true)}
                                className="flex items-center gap-1.5 px-3 py-2 text-sm text-white rounded-md transition-opacity hover:opacity-90 font-medium flex-shrink-0"
                                style={{ backgroundColor: primary }}
                            >
                                <Plus size={14} />
                                Add
                            </button>
                        )}

                        {/* Search */}
                        <div className="flex-1 min-w-[150px] sm:min-w-[200px] flex-shrink-0">
                            <GlobalSearch 
                                searchQuery={searchQuery}
                                setSearchQuery={setSearchQuery}
                                isDarkMode={isDarkMode}
                                colorPalette={colorPalette}
                                placeholder="Search reports…"
                            />
                        </div>

                        {/* Column picker */}
                        <div className="relative flex-shrink-0" ref={columnPickerRef}>
                            <button
                                onClick={() => setShowColumnPicker(p => !p)}
                                className={`flex items-center gap-1.5 px-3 py-2 text-sm border rounded-md transition-colors ${showColumnPicker
                                    ? 'text-white'
                                    : isDarkMode ? 'text-gray-300 border-gray-600 hover:bg-gray-700' : 'text-gray-600 border-gray-300 hover:bg-gray-100'
                                    }`}
                                style={showColumnPicker ? { backgroundColor: primary, borderColor: primary } : {}}
                            >
                                <Settings2 size={14} />
                                Columns
                            </button>

                            {showColumnPicker && (
                                <div className={`absolute right-0 top-10 z-50 w-64 border rounded-xl shadow-2xl p-3 ${cardBg}`}>
                                    <div className="flex items-center justify-between mb-2">
                                        <span className={`text-xs font-semibold ${text}`}>Toggle Columns</span>
                                        <div className="flex gap-2">
                                            <button onClick={() => setVisibleColumns(ALL_COLUMNS.map(c => c.key))} className="text-xs" style={{ color: primary }}>All</button>
                                            <span className={`text-xs ${subText}`}>·</span>
                                            <button onClick={() => setVisibleColumns(DEFAULT_VISIBLE)} className="text-xs" style={{ color: primary }}>Reset</button>
                                        </div>
                                    </div>
                                    <div className="max-h-72 overflow-y-auto space-y-1 pr-1">
                                        {ALL_COLUMNS.map(col => (
                                            <label key={col.key} className={`flex items-center gap-2 px-2 py-1 rounded cursor-pointer text-xs ${isDarkMode ? 'hover:bg-gray-700 text-gray-300' : 'hover:bg-gray-100 text-gray-700'}`}>
                                                <input
                                                    type="checkbox"
                                                    checked={visibleColumns.includes(col.key)}
                                                    onChange={() => toggleColumn(col.key)}
                                                    className="rounded"
                                                    style={{ accentColor: primary }}
                                                />
                                                {col.label}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Refresh */}
                        <button
                            onClick={() => fetchReports(true)}
                            disabled={isRefreshing || isLoading}
                            className={`p-2 border rounded-md transition-colors flex-shrink-0 ${isDarkMode ? 'border-gray-600 text-gray-300 hover:bg-gray-700' : 'border-gray-300 text-gray-600 hover:bg-gray-100'
                                }`}
                            title="Refresh"
                        >
                            <RefreshCw size={14} className={isRefreshing ? 'animate-spin' : ''} />
                        </button>
                    </div>
                </div>

                {/* ── Table area ──────────────────────────────────────────────────────── */}
                <div className="flex-1 min-h-0 overflow-auto" ref={scrollRef}>
                    {isLoading ? (
                        <div className="flex flex-col items-center justify-center h-full gap-4">
                            <div
                                className="animate-spin rounded-full h-12 w-12 border-b-4 border-t-4"
                                style={{ borderColor: primary, borderTopColor: 'transparent' }}
                            />
                            <p className={`text-sm ${subText}`}>Loading reports…</p>
                        </div>
                    ) : filtered.length === 0 ? (
                        <div className="flex flex-col items-center justify-center h-full gap-3">
                            <FileText size={40} className={subText} />
                            <p className={`text-base font-medium ${text}`}>No records found</p>
                            <p className={`text-sm ${subText}`}>
                                {searchQuery
                                    ? 'Try adjusting your search query.'
                                    : 'No report data available. Start by clicking the "+ Add" button.'}
                            </p>
                            {searchQuery && (
                                <button
                                    onClick={() => setSearchQuery('')}
                                    className="text-sm mt-1 underline"
                                    style={{ color: primary }}
                                >
                                    Clear search
                                </button>
                            )}
                        </div>
                    ) : (
                        <table className="w-max min-w-full text-xs border-separate border-spacing-0">
                            <thead>
                                <tr className="sticky top-0 z-30">
                                    <th className={`sticky left-0 top-0 z-30 px-3 py-2.5 text-left font-semibold border-b border-r text-xs ${thCls} w-10`}>#</th>
                                    {orderedVisibleColumns.map(col => (
                                        <th
                                            key={col.key}
                                            className={`sticky top-0 z-20 px-3 py-2.5 text-left font-semibold border-b border-r whitespace-nowrap select-none cursor-pointer ${thCls}`}
                                            onClick={() => handleSort(col.key)}
                                        >
                                            <div className="flex items-center gap-1">
                                                {col.label}
                                                {sortColumn === col.key ? (
                                                    sortDir === 'asc'
                                                        ? <ArrowUp size={11} style={{ color: primary }} />
                                                        : <ArrowDown size={11} style={{ color: primary }} />
                                                ) : (
                                                    <ArrowUp size={11} className="opacity-20" />
                                                )}
                                            </div>
                                        </th>
                                    ))}
                                    {/* Action column (Download) */}
                                    <th className={`sticky top-0 z-20 px-3 py-2.5 text-center font-semibold border-b text-xs ${thCls} whitespace-nowrap`}>
                                        Download
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {paginated.map((row, idx) => {
                                    const rowNum = (currentPage - 1) * itemsPerPage + idx + 1;
                                    const isEven = idx % 2 === 0;
                                    const evenBg = isDarkMode
                                        ? (isEven ? 'bg-gray-900' : 'bg-gray-850')
                                        : (isEven ? 'bg-white' : 'bg-gray-50');

                                    return (
                                        <tr
                                            key={row.id}
                                            className={`${evenBg} ${rowHover} transition-colors duration-100 group`}
                                        >
                                            <td className={`sticky left-0 z-10 px-3 py-2 border-b border-r ${tdCls} ${subText} ${evenBg}`}>
                                                {rowNum}
                                            </td>
                                            {orderedVisibleColumns.map(col => (
                                                <td
                                                    key={col.key}
                                                    className={`px-3 py-2 border-b border-r whitespace-nowrap max-w-xs overflow-hidden text-ellipsis ${tdCls}`}
                                                    title={getCellValue(row, col.key)}
                                                >
                                                    {getCellValue(row, col.key)}
                                                </td>
                                            ))}
                                            <td className={`px-3 py-2 border-b text-center ${tdCls}`}>
                                                <div className="flex justify-center">
                                                    {row.file_url ? (
                                                        <a
                                                            href={row.file_url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className={`flex items-center gap-1 px-3 py-1.5 rounded-lg transition-colors font-medium border ${isDarkMode
                                                                ? 'hover:bg-gray-800 border-gray-700 text-gray-300'
                                                                : 'hover:bg-gray-100 border-gray-300 text-gray-700'
                                                                }`}
                                                            title="Download PDF from Google Drive"
                                                        >
                                                            <DownloadCloud size={14} style={{ color: primary }} />
                                                            <span className="text-xs">Download</span>
                                                        </a>
                                                    ) : (
                                                        <span className="text-gray-400 italic text-[11px]">No file</span>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>

                {/* ── Pagination ───────────────────────────────────────────────────────── */}
                {!isLoading && filtered.length > 0 && (
                    <div className={`flex flex-col md:flex-row items-center justify-between gap-3 px-5 py-3 border-t flex-shrink-0 ${headerBg}`}>
                        <div className={`flex flex-wrap items-center justify-center md:justify-start gap-3 text-xs ${subText}`}>
                            <div className="flex items-center gap-1.5">
                                <span>Show</span>
                                <select
                                    value={itemsPerPage}
                                    onChange={(e) => setItemsPerPage(Number(e.target.value))}
                                    className={`px-1.5 py-1 rounded border text-xs focus:outline-none ${isDarkMode ? 'bg-gray-800 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-900'}`}
                                >
                                    <option value={10}>10</option>
                                    <option value={25}>25</option>
                                    <option value={50}>50</option>
                                    <option value={100}>100</option>
                                </select>
                                <span>entries</span>
                            </div>
                            <span>
                                Showing {((currentPage - 1) * itemsPerPage + 1).toLocaleString()}–{Math.min(currentPage * itemsPerPage, filtered.length).toLocaleString()} of {filtered.length.toLocaleString()} records
                            </span>
                        </div>

                        <div className="flex items-center flex-wrap justify-center gap-1 w-full md:w-auto">
                            <button
                                onClick={() => setCurrentPage(1)}
                                disabled={currentPage === 1}
                                className={`px-2 py-1.5 text-xs border rounded disabled:opacity-30 transition-colors ${isDarkMode ? 'border-gray-600 text-gray-300 hover:bg-gray-700' : 'border-gray-300 text-gray-600 hover:bg-gray-100'
                                    }`}
                            >
                                ««
                            </button>
                            <button
                                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                                disabled={currentPage === 1}
                                className={`flex items-center gap-0.5 px-2 py-1.5 text-xs border rounded disabled:opacity-30 transition-colors ${isDarkMode ? 'border-gray-600 text-gray-300 hover:bg-gray-700' : 'border-gray-300 text-gray-600 hover:bg-gray-100'
                                    }`}
                            >
                                <ChevronLeft size={12} /> Prev
                            </button>

                            {/* Page numbers */}
                            {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
                                let page: number;
                                if (totalPages <= 5) page = i + 1;
                                else if (currentPage <= 3) page = i + 1;
                                else if (currentPage >= totalPages - 2) page = totalPages - 4 + i;
                                else page = currentPage - 2 + i;
                                return (
                                    <button
                                        key={page}
                                        onClick={() => setCurrentPage(page)}
                                        className={`w-8 h-7 text-xs border rounded transition-colors ${page === currentPage
                                            ? 'text-white border-transparent'
                                            : isDarkMode ? 'border-gray-600 text-gray-300 hover:bg-gray-700' : 'border-gray-300 text-gray-600 hover:bg-gray-100'
                                            }`}
                                        style={page === currentPage ? { backgroundColor: primary, borderColor: primary } : {}}
                                    >
                                        {page}
                                    </button>
                                );
                            })}

                            <button
                                onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                                disabled={currentPage === totalPages || totalPages <= 1}
                                className={`flex items-center gap-0.5 px-2 py-1.5 text-xs border rounded disabled:opacity-30 transition-colors ${isDarkMode ? 'border-gray-600 text-gray-300 hover:bg-gray-700' : 'border-gray-300 text-gray-600 hover:bg-gray-100'
                                    }`}
                            >
                                Next <ChevronRight size={12} />
                            </button>
                            <button
                                onClick={() => setCurrentPage(totalPages)}
                                disabled={currentPage === totalPages || totalPages <= 1}
                                className={`px-2 py-1.5 text-xs border rounded disabled:opacity-30 transition-colors ${isDarkMode ? 'border-gray-600 text-gray-300 hover:bg-gray-700' : 'border-gray-300 text-gray-600 hover:bg-gray-100'
                                    }`}
                            >
                                »»
                            </button>
                        </div>

                        <span className={`text-xs whitespace-nowrap ${subText}`}>
                            Page {currentPage} of {totalPages}
                        </span>
                    </div>
                )}
            </div>

            {/* ── Add Report Modal ─────────────────────────────── */}
            <AddReportModal
                isOpen={isAddModalOpen}
                onClose={() => setIsAddModalOpen(false)}
                onSaved={() => fetchReports(true)}
            />
        </>
    );
};

export default Reports;
