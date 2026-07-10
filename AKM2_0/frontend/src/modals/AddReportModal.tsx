import React, { useState, useEffect } from 'react';
import { X, Loader2, Plus } from 'lucide-react';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import apiClient from '../config/api';

// ─── Types ────────────────────────────────────────────────────────────────────

interface AddReportModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSaved: () => void;
}

interface ModalConfig {
    isOpen: boolean;
    type: 'success' | 'error' | 'warning' | 'confirm' | 'loading';
    title: string;
    message: string;
    onConfirm?: () => void;
    onCancel?: () => void;
}

// ─── Constants ────────────────────────────────────────────────────────────────

const REPORT_TYPES = [
    'Manual Transaction',
    'Payment Portal',
    'Inventory',
    'Job Order',
    'Service Order',
    'Work Order',
    'Summary',
];

const REPORT_SCHEDULES = [
    'Every Day',
    'Every Month',
    'Every 3 Months',
    'Every Year',
];

const DATE_RANGES = []; // Removed dropdown options

const QUICK_RANGES = [
    { label: 'Everyday', days: 1 },
    { label: 'Weekly', days: 7 },
    { label: 'Monthly', days: 30 },
    { label: 'Quarterly', days: 90 },
];

// ─── Component ────────────────────────────────────────────────────────────────

const AddReportModal: React.FC<AddReportModalProps> = ({ isOpen, onClose, onSaved }) => {
    const [isDarkMode, setIsDarkMode] = useState(true);
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
    const [loading, setLoading] = useState(false);
    const [loadingPercentage, setLoadingPercentage] = useState(0);

    const [formData, setFormData] = useState({
        report_name: '',
        report_type: '',
        report_schedule: '',
        day: '',
        report_time: '',
        send_to: '',
        date_from: '',
        date_to: '',
    });

    const [errors, setErrors] = useState<Record<string, string>>({});

    const [modal, setModal] = useState<ModalConfig>({
        isOpen: false,
        type: 'success',
        title: '',
        message: '',
    });

    // ── Init ───────────────────────────────────────────────────────────────────

    useEffect(() => {
        const theme = localStorage.getItem('theme');
        setIsDarkMode(theme !== 'light');

        settingsColorPaletteService.getActive().then(p => setColorPalette(p)).catch(() => { });
    }, []);

    useEffect(() => {
        if (isOpen) {
            setFormData({ report_name: '', report_type: '', report_schedule: '', day: '', report_time: '', send_to: '', date_from: '', date_to: '' });
            setErrors({});
        }
    }, [isOpen]);

    if (!isOpen) return null;

    // ── Helpers ────────────────────────────────────────────────────────────────

    const primary = colorPalette?.primary || '#7c3aed';

    const inputCls = (field: string) => {
        const base = "w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none transition-colors";
        if (errors[field]) {
            return `${base} border-red-500 focus:border-red-500 focus:ring-2 focus:ring-red-500/30 ${isDarkMode ? 'bg-gray-800 text-white' : 'bg-white text-gray-900'
                }`;
        }
        return `${base} custom-focus-ring ${isDarkMode
            ? 'bg-gray-800 border-gray-700 text-white placeholder-gray-500'
            : 'bg-white border-gray-300 text-gray-900 placeholder-gray-400'
            }`;
    };

    const labelCls = `block text-sm font-medium mb-1.5 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`;

    const handleChange = (field: string, value: string) => {
        setFormData(prev => ({ ...prev, [field]: value }));
        if (errors[field]) setErrors(prev => ({ ...prev, [field]: '' }));
    };

    const handleRangeSelect = (days: number) => {
        const to = new Date();
        const from = new Date();
        from.setDate(to.getDate() - (days - 1));

        const formatDate = (d: Date) => d.toISOString().split('T')[0];

        setFormData(prev => ({
            ...prev,
            date_from: formatDate(from),
            date_to: formatDate(to)
        }));
        
        if (errors.date_from) setErrors(prev => ({ ...prev, date_from: '' }));
        if (errors.date_to) setErrors(prev => ({ ...prev, date_to: '' }));
    };

    // ── Validation ─────────────────────────────────────────────────────────────

    const validate = () => {
        const e: Record<string, string> = {};
        if (!formData.report_name.trim()) e.report_name = 'Report name is required.';
        if (!formData.report_type) e.report_type = 'Please select a report type.';
        if (!formData.report_schedule) e.report_schedule = 'Please select a schedule.';
        if (!formData.day.trim()) e.day = 'Day is required.';
        if (!formData.report_time) e.report_time = 'Please pick a time.';
        if (!formData.send_to.trim()) e.send_to = 'Send To is required.';
        if (!formData.date_from) e.date_from = 'Start date is required.';
        if (!formData.date_to) e.date_to = 'End date is required.';
        setErrors(e);
        return Object.keys(e).length === 0;
    };

    // ── Save ───────────────────────────────────────────────────────────────────

    const handleSave = async () => {
        if (!validate()) return;

        setLoading(true);
        setLoadingPercentage(0);

        const progressInterval = setInterval(() => {
            setLoadingPercentage(prev => {
                if (prev >= 90) return prev + 1 > 99 ? 99 : prev + 1;
                if (prev >= 70) return prev + 3;
                return prev + 8;
            });
        }, 200);

        try {
            const authData = localStorage.getItem('authData');
            const user = authData ? JSON.parse(authData) : null;
            const createdBy = user?.email_address || user?.email || 'system';

            const payload = {
                report_name: formData.report_name.trim(),
                report_type: formData.report_type,
                report_schedule: formData.report_schedule,
                day: formData.day,
                report_time: formData.report_time,
                send_to: formData.send_to.trim(),
                date_range: `${formData.date_from} to ${formData.date_to}`,
                created_by: createdBy,
            };

            const res = await apiClient.post<{ success: boolean; message?: string }>('/reports', payload);

            if (!res.data?.success) {
                throw new Error(res.data?.message || 'Failed to save report.');
            }

            clearInterval(progressInterval);
            setLoadingPercentage(100);

            setTimeout(() => {
                setModal({
                    isOpen: true,
                    type: 'success',
                    title: 'Report Created',
                    message: `"${formData.report_name}" has been saved successfully.`,
                    onConfirm: () => {
                        setModal(prev => ({ ...prev, isOpen: false }));
                        onSaved();
                        onClose();
                    },
                });
            }, 400);

        } catch (err: any) {
            clearInterval(progressInterval);
            setModal({
                isOpen: true,
                type: 'error',
                title: 'Failed to Create Report',
                message: err?.response?.data?.message || err?.message || 'An unexpected error occurred.',
            });
        } finally {
            setTimeout(() => {
                setLoading(false);
                setLoadingPercentage(0);
            }, 500);
        }
    };

    // ── Render ─────────────────────────────────────────────────────────────────

    return (
        <>
            <style>
                {`
                .custom-focus-ring:focus {
                    border-color: ${primary} !important;
                    box-shadow: 0 0 0 2px ${primary}40 !important; /* 40 is ~25% opacity in hex */
                }
                `}
            </style>

            {/* ── Loading overlay ─────────────────────────────────────────────────── */}
            {loading && (
                <div className="fixed inset-0 bg-black bg-opacity-70 z-[10000] flex items-center justify-center">
                    <div className={`rounded-lg p-8 flex flex-col items-center space-y-6 min-w-[320px] ${isDarkMode ? 'bg-gray-800' : 'bg-white'
                        }`}>
                        <Loader2
                            className="w-20 h-20 animate-spin"
                            style={{ color: colorPalette?.primary || '#7c3aed' }}
                        />
                        <div className="text-center">
                            <p className={`text-4xl font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'
                                }`}>{loadingPercentage}%</p>
                        </div>
                    </div>
                </div>
            )}

            {/* ── Slide-in backdrop ────────────────────────────────────────────────── */}
            <div className="fixed inset-0 bg-black bg-opacity-50 flex justify-end z-[5000]">
                <div
                    className={`h-full w-full max-w-lg flex flex-col shadow-2xl transition-transform animate-slide-in ${isDarkMode ? 'bg-gray-900' : 'bg-white'
                        }`}
                >
                    {/* Header */}
                    <div className={`px-6 py-4 flex items-center justify-between border-b flex-shrink-0 ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}>
                        <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ backgroundColor: `${primary}20` }}>
                                <Plus size={16} style={{ color: primary }} />
                            </div>
                            <div>
                                <h2 className={`text-lg font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                                    Add Report
                                </h2>
                                <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                                    Configure a new scheduled report
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                onClick={onClose}
                                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${isDarkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700'
                                    }`}
                            >
                                Cancel
                            </button>
                            <button
                                onClick={handleSave}
                                disabled={loading}
                                className="px-4 py-2 text-white rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed transition-opacity hover:opacity-90"
                                style={{ backgroundColor: primary }}
                            >
                                Save
                            </button>
                        </div>
                    </div>

                    {/* Body */}
                    <div className="flex-1 overflow-y-auto p-6 space-y-5">

                        {/* Report Name */}
                        <div>
                            <label className={labelCls}>
                                Report Name <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={formData.report_name}
                                onChange={e => handleChange('report_name', e.target.value)}
                                placeholder="e.g. Monthly Service Order Summary"
                                className={inputCls('report_name')}
                            />
                            {errors.report_name && <p className="mt-1 text-xs text-red-400">{errors.report_name}</p>}
                        </div>

                        {/* Report Type */}
                        <div>
                            <label className={labelCls}>
                                Report Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={formData.report_type}
                                onChange={e => handleChange('report_type', e.target.value)}
                                className={inputCls('report_type')}
                            >
                                <option value="">Select report type…</option>
                                {REPORT_TYPES.map(t => (
                                    <option key={t} value={t}>{t}</option>
                                ))}
                            </select>
                            {errors.report_type && <p className="mt-1 text-xs text-red-400">{errors.report_type}</p>}
                        </div>

                        {/* Report Schedule */}
                        <div>
                            <label className={labelCls}>
                                Report Schedule <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={formData.report_schedule}
                                onChange={e => handleChange('report_schedule', e.target.value)}
                                className={inputCls('report_schedule')}
                            >
                                <option value="">Select schedule…</option>
                                {REPORT_SCHEDULES.map(s => (
                                    <option key={s} value={s}>{s}</option>
                                ))}
                            </select>
                            {errors.report_schedule && <p className="mt-1 text-xs text-red-400">{errors.report_schedule}</p>}
                        </div>

                        {/* Quick Range */}
                        <div>
                            <label className={labelCls}>
                                Quick Date Range
                            </label>
                            <select
                                onChange={e => {
                                    const val = e.target.value;
                                    if (val) handleRangeSelect(parseInt(val));
                                }}
                                className={inputCls('quick_range')}
                            >
                                <option value="">Select a range preset…</option>
                                {QUICK_RANGES.map(r => (
                                    <option key={r.label} value={r.days}>{r.label}</option>
                                ))}
                            </select>
                        </div>



                        {/* Day */}
                        <div>
                            <label className={labelCls}>
                                Day <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="number"
                                min="1"
                                max="31"
                                value={formData.day}
                                onChange={e => handleChange('day', e.target.value)}
                                placeholder="e.g. 15"
                                className={inputCls('day')}
                            />
                            {errors.day && <p className="mt-1 text-xs text-red-400">{errors.day}</p>}
                        </div>

                        {/* Report Time */}
                        <div>
                            <label className={labelCls}>
                                Report Time (GMT+8) <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="time"
                                value={formData.report_time}
                                onChange={e => handleChange('report_time', e.target.value)}
                                className={inputCls('report_time')}
                                style={isDarkMode ? { colorScheme: 'dark' } : {}}
                            />
                            {errors.report_time && <p className="mt-1 text-xs text-red-400">{errors.report_time}</p>}
                        </div>

                        {/* Send To */}
                        <div>
                            <label className={labelCls}>
                                Send To <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={formData.send_to}
                                onChange={e => handleChange('send_to', e.target.value)}
                                placeholder="e.g. admin@company.com or multiple emails"
                                className={inputCls('send_to')}
                            />
                            <p className={`mt-1 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                                Enter email address(es) where the report will be sent.
                            </p>
                            {errors.send_to && <p className="mt-1 text-xs text-red-400">{errors.send_to}</p>}
                        </div>

                        {/* Preview card */}
                        {(formData.report_name || formData.report_type || formData.report_schedule) && (
                            <div className={`rounded-xl border p-4 ${isDarkMode ? 'bg-gray-800 border-gray-700' : 'bg-gray-50 border-gray-200'}`}>
                                <p className={`text-xs font-semibold uppercase tracking-wide mb-3 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                                    Preview
                                </p>
                                <div className="space-y-2">
                                    {formData.report_name && (
                                        <div className="flex items-start gap-2">
                                            <span className={`text-xs w-28 flex-shrink-0 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>Name</span>
                                            <span className={`text-xs font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{formData.report_name}</span>
                                        </div>
                                    )}
                                    {formData.report_type && (
                                        <div className="flex items-start gap-2">
                                            <span className={`text-xs w-28 flex-shrink-0 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>Type</span>
                                            <span className={`text-xs font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{formData.report_type}</span>
                                        </div>
                                    )}
                                    {formData.report_schedule && (
                                        <div className="flex items-start gap-2">
                                            <span className={`text-xs w-28 flex-shrink-0 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>Schedule</span>
                                            <span className={`text-xs font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{formData.report_schedule}</span>
                                        </div>
                                    )}
                                    {formData.day && (
                                        <div className="flex items-start gap-2">
                                            <span className={`text-xs w-28 flex-shrink-0 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>Day</span>
                                            <span className={`text-xs font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{formData.day}</span>
                                        </div>
                                    )}
                                    {formData.report_time && (
                                        <div className="flex items-start gap-2">
                                            <span className={`text-xs w-28 flex-shrink-0 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>Time (GMT+8)</span>
                                            <span className={`text-xs font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                                                {(() => {
                                                    try {
                                                        const [h, m] = formData.report_time.split(':');
                                                        let hours = parseInt(h, 10);
                                                        const ampm = hours >= 12 ? 'PM' : 'AM';
                                                        hours = hours % 12;
                                                        hours = hours ? hours : 12;
                                                        return `${hours}:${m} ${ampm} GMT+8`;
                                                    } catch {
                                                        return formData.report_time;
                                                    }
                                                })()}
                                            </span>
                                        </div>
                                    )}
                                    {formData.send_to && (
                                        <div className="flex items-start gap-2">
                                            <span className={`text-xs w-28 flex-shrink-0 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>Send To</span>
                                            <span className={`text-xs font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{formData.send_to}</span>
                                        </div>
                                    )}
                                    {formData.date_from && formData.date_to && (
                                        <div className="flex items-start gap-2">
                                            <span className={`text-xs w-28 flex-shrink-0 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>Date Range</span>
                                            <span className={`text-xs font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{formData.date_from} to {formData.date_to}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                    </div>
                </div>
            </div>

            {/* ── Result modal ────────────────────────────────────────────────────── */}
            {modal.isOpen && (
                <div className="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-[10000]">
                    <div className={`border rounded-lg p-8 max-w-md w-full mx-4 ${isDarkMode ? 'bg-gray-900 border-gray-700' : 'bg-white border-gray-200'
                        }`}>
                        {modal.type === 'loading' ? (
                            <div className="text-center">
                                <div className="flex justify-center mb-4">
                                    <div className="animate-spin rounded-full h-16 w-16 border-b-4" style={{ borderColor: colorPalette?.primary || '#7c3aed' }}></div>
                                </div>
                                <h3 className={`text-xl font-semibold mb-2 ${isDarkMode ? 'text-white' : 'text-gray-900'
                                    }`}>{modal.title}</h3>
                                <p className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                                    }`}>{modal.message}</p>
                            </div>
                        ) : (
                            <>
                                <h3 className={`text-lg font-semibold mb-4 ${isDarkMode ? 'text-white' : 'text-gray-900'
                                    }`}>{modal.title}</h3>
                                <p className={`mb-6 whitespace-pre-line ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
                                    }`}>{modal.message}</p>
                                <div className="flex items-center justify-end gap-3">
                                    <button
                                        onClick={() => {
                                            if (modal.onConfirm) {
                                                modal.onConfirm();
                                            } else {
                                                setModal(prev => ({ ...prev, isOpen: false }));
                                            }
                                        }}
                                        className="px-4 py-2 text-white rounded transition-colors"
                                        style={{
                                            backgroundColor: colorPalette?.primary || '#7c3aed'
                                        }}
                                        onMouseEnter={(e) => {
                                            if (colorPalette?.accent) {
                                                e.currentTarget.style.backgroundColor = colorPalette.accent;
                                            }
                                        }}
                                        onMouseLeave={(e) => {
                                            e.currentTarget.style.backgroundColor = colorPalette?.primary || '#7c3aed';
                                        }}
                                    >
                                        OK
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}
        </>
    );
};

export default AddReportModal;
