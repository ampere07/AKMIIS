import React, { useState, useEffect } from 'react';
import { User, Activity, Clock, Users, CreditCard, HelpCircle, FileText, CheckCircle, XCircle } from 'lucide-react';
import { getCustomerDetail, CustomerDetailData } from '../services/customerDetailService';
import { transactionService } from '../services/transactionService';
import { paymentPortalLogsService } from '../services/paymentPortalLogsService';
import { paymentService, PendingPayment } from '../services/paymentService'; // Import paymentService
import { useCustomerDashboardStore } from '../store/customerDashboardStore';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import pusher from '../services/pusherService';

// Interfaces for data types
interface Payment {
    id: string;
    date: string;
    reference: string;
    amount: number;
    source: string;
}

interface Referral {
    id: string;
    date: string;
    name: string;
    stage: string;
    status: 'Done' | 'Failed' | 'Scheduled' | 'Pending';
}

interface DashboardCustomerProps {
    onNavigate?: (section: string, tab?: string) => void;
    autoOpenPayModal?: boolean;
}

const DashboardCustomer: React.FC<DashboardCustomerProps> = ({ onNavigate, autoOpenPayModal }) => {
    const [user, setUser] = useState<any>(null);
    const [error, setError] = useState('');

    const { customerDetail, paymentRecords, invoiceRecords, isLoading, fetchCustomerData } = useCustomerDashboardStore();
    const payments = paymentRecords.slice(0, 4);
    const [referrals, setReferrals] = useState<Referral[]>([]);

    // Payment State
    const [isPaymentProcessing, setIsPaymentProcessing] = useState<boolean>(false);
    const [showPaymentVerifyModal, setShowPaymentVerifyModal] = useState<boolean>(false);
    const [paymentAmount, setPaymentAmount] = useState<number>(0);
    const [showPaymentLinkModal, setShowPaymentLinkModal] = useState<boolean>(false);
    const [paymentLinkData, setPaymentLinkData] = useState<{ referenceNo: string; amount: number; paymentUrl: string } | null>(null);
    const [showPendingPaymentModal, setShowPendingPaymentModal] = useState<boolean>(false);
    const [pendingPayment, setPendingPayment] = useState<PendingPayment | null>(null);
    const [errorMessage, setErrorMessage] = useState<string>('');
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
    const [showPaymentSuccessModal, setShowPaymentSuccessModal] = useState<boolean>(false);

    useEffect(() => {
        const fetchData = async () => {
            try {
                const storedUser = localStorage.getItem('authData');
                if (storedUser) {
                    const parsedUser = JSON.parse(storedUser);
                    setUser(parsedUser);

                    if (parsedUser.username) {
                        await fetchCustomerData(parsedUser.username, true);

                        // Need the current updated customer details for account number to get pending payment
                        const updatedDetail = useCustomerDashboardStore.getState().customerDetail;
                        if (updatedDetail && updatedDetail.billingAccount) {
                            try {
                                const accNo = updatedDetail.billingAccount.accountNo;
                                const pending = await paymentService.checkPendingPayment(accNo);
                                setPendingPayment(pending);
                            } catch (pendingErr) {
                                console.error("Error checking pending payment on load", pendingErr);
                            }
                        }
                    }
                }
            } catch (err) {
                console.error("Error fetching dashboard data:", err);
                setError('Failed to load dashboard data');
            }
        };

        const fetchColorPalette = async () => {
            try {
                const activePalette = await settingsColorPaletteService.getActive();
                setColorPalette(activePalette);
            } catch (err) {
                console.error('Failed to fetch color palette:', err);
            }
        };

        fetchData();
        fetchColorPalette();
    }, [fetchCustomerData]);

    // Handle auto-opening the pay modal (e.g., from Bills page)
    useEffect(() => {
        if (autoOpenPayModal && !isLoading && customerDetail) {
            handlePayNow();
        }
    }, [autoOpenPayModal, isLoading, customerDetail]);

    // Real-time updates via Pusher/Soketi
    useEffect(() => {
        const handleUpdate = async (data: any) => {
            try {
                const storedUser = localStorage.getItem('authData');
                if (storedUser) {
                    const parsedUser = JSON.parse(storedUser);
                    if (parsedUser.username) {
                        await fetchCustomerData(parsedUser.username, true);
                    }
                }
            } catch (err) {
                console.error('[DashboardCustomer Soketi] Failed to refresh data:', err);
            }
        };

        const handlePaymentUpdate = async (data: any) => {
            // Show success modal when a webhook confirms payment for this account
            if (data?.action === 'webhook_update' && data?.status === 'QUEUED' && data?.reference_no) {
                const currentAccountNo = customerDetail?.billingAccount?.accountNo;
                if (currentAccountNo && data.reference_no.startsWith(currentAccountNo)) {
                    setShowPaymentSuccessModal(true);
                    setPendingPayment(null);
                }
            }
            await handleUpdate(data);
        };

        const txChannel = pusher.subscribe('transactions');
        const invChannel = pusher.subscribe('invoices');
        const soaChannel = pusher.subscribe('soa');
        const payChannel = pusher.subscribe('payments');

        txChannel.bind('transaction-updated', handleUpdate);
        invChannel.bind('invoice-updated', handleUpdate);
        soaChannel.bind('soa-updated', handleUpdate);
        payChannel.bind('payment-updated', handlePaymentUpdate);

        return () => {
            txChannel.unbind('transaction-updated', handleUpdate);
            invChannel.unbind('invoice-updated', handleUpdate);
            soaChannel.unbind('soa-updated', handleUpdate);
            payChannel.unbind('payment-updated', handlePaymentUpdate);
            pusher.unsubscribe('transactions');
            pusher.unsubscribe('invoices');
            pusher.unsubscribe('soa');
            pusher.unsubscribe('payments');
        };
    }, [fetchCustomerData, customerDetail?.billingAccount?.accountNo]);

    if (isLoading && !customerDetail) return <div className="p-8 flex justify-center bg-gray-50 min-h-screen"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div></div>;

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'Done': return 'bg-green-100 text-green-600 border border-green-200';
            case 'Failed': return 'bg-red-100 text-red-600 border border-red-200';
            case 'Scheduled': return 'bg-yellow-100 text-yellow-600 border border-yellow-200';
            default: return 'bg-gray-100 text-gray-600';
        }
    };

    // Use detailed data if available, otherwise fall back to auth data or placeholders
    const displayName = customerDetail?.fullName || user?.full_name || 'Customer';
    const accountNo = customerDetail?.billingAccount?.accountNo || user?.username || 'N/A';
    const planName = customerDetail?.desiredPlan || 'No Plan';
    const address = customerDetail?.address || 'No Address';
    const installationDate = customerDetail?.billingAccount?.dateInstalled || 'Pending';
    const balance = customerDetail?.billingAccount?.accountBalance || 0;

    // Due Date: read from the latest invoice's due_date (not recalculated from billingDay)
    let dueDateString = 'Upon Receipt';
    if (invoiceRecords && invoiceRecords.length > 0) {
        const latestInvoice = invoiceRecords[0]; // already sorted by date descending from the store
        const rawDueDate = latestInvoice.due_date;
        if (rawDueDate) {
            const parsed = new Date(rawDueDate);
            if (!isNaN(parsed.getTime())) {
                dueDateString = parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            }
        }
    }

    // Restriction logic removed as requested

    // Payment Handlers
    const handlePayNow = async () => {

        setErrorMessage('');
        setIsPaymentProcessing(true);

        try {
            // Check for pending payments
            const pending = await paymentService.checkPendingPayment(accountNo);

            if (pending && pending.payment_url) {
                setPendingPayment(pending);
                setShowPendingPaymentModal(true);
            } else {
                setPaymentAmount(Math.abs(balance));
                setShowPaymentVerifyModal(true);
            }
        } catch (error: any) {
            console.error('Error checking pending payment:', error);
            setPaymentAmount(Math.abs(balance));
            setShowPaymentVerifyModal(true);
        } finally {
            setIsPaymentProcessing(false);
        }
    };

    const handleCloseVerifyModal = () => {
        setShowPaymentVerifyModal(false);
        setPaymentAmount(balance);
    };

    const handleProceedToCheckout = async () => {
        if (paymentAmount < balance) {
            setErrorMessage(`Payment amount cannot be lower than your current balance of ₱${balance.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`);
            return;
        }

        if (paymentAmount < 1) {
            setErrorMessage('Payment amount must be at least ₱1.00');
            return;
        }

        if (isPaymentProcessing) return;

        setIsPaymentProcessing(true);
        setErrorMessage('');

        try {
            const response = await paymentService.createPayment(accountNo, paymentAmount);

            if (response.status === 'success' && response.payment_url) {
                setShowPaymentVerifyModal(false);
                setPaymentLinkData({
                    referenceNo: response.reference_no || '',
                    amount: response.amount || paymentAmount,
                    paymentUrl: response.payment_url
                });
                setShowPaymentLinkModal(true);
            } else {
                throw new Error(response.message || 'Failed to create payment link');
            }
        } catch (error: any) {
            console.error('Payment error:', error);
            setErrorMessage(error.message || 'Failed to create payment. Please try again.');
        } finally {
            setIsPaymentProcessing(false);
        }
    };

    const handleOpenPaymentLink = () => {
        if (paymentLinkData?.paymentUrl) {
            window.open(paymentLinkData.paymentUrl, '_blank');
            setShowPaymentLinkModal(false);
            setPaymentLinkData(null);
        }
    };

    const handleCancelPaymentLink = () => {
        setShowPaymentLinkModal(false);
        setPaymentLinkData(null);
    };

    const handleResumePendingPayment = () => {
        if (pendingPayment && pendingPayment.payment_url) {
            window.open(pendingPayment.payment_url, '_blank');
            setShowPendingPaymentModal(false);
            setPendingPayment(null);
        }
    };

    const handleClosePendingPaymentModal = () => {
        setErrorMessage('');
        setShowPendingPaymentModal(false);
        setPendingPayment(null);
    };

    const handleCancelPendingPaymentFromDb = async () => {
        if (!pendingPayment) return;
        setIsPaymentProcessing(true);
        setErrorMessage('');
        try {
            const response = await paymentService.cancelPayment(pendingPayment.reference_no);
            if (response.status === 'success') {
                setPendingPayment(null);
                setShowPendingPaymentModal(false);
            } else {
                throw new Error(response.message || 'Failed to cancel payment');
            }
        } catch (error: any) {
            console.error('Cancel payment error:', error);
            setErrorMessage(error.message || 'Failed to cancel payment. Please try again.');
        } finally {
            setIsPaymentProcessing(false);
        }
    };

    return (
        <div className="min-h-screen bg-gray-50 p-6 md:p-12 font-sans relative">
            {/* Welcome Header */}
            <div className="mb-8">
                <h1 className="text-3xl font-bold text-gray-900">Hello, {displayName.split(' ')[0]}!</h1>
                <p className="text-gray-500 mt-1">Welcome back to your dashboard.</p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {/* Left Column: Profile Card */}
                <div className="lg:col-span-1 space-y-6">
                    <div className="bg-white rounded-3xl shadow-sm p-8 text-center border border-gray-100">
                        <div className="relative inline-block mb-4">
                            <div className="w-24 h-24 bg-gray-200 rounded-full mx-auto flex items-center justify-center">
                                <User className="w-12 h-12 text-gray-400" />
                            </div>
                            <div className="absolute -bottom-2 transform -translate-x-1/2 left-1/2 bg-green-600 text-white text-xs px-3 py-1 rounded-full font-medium">
                                Active
                            </div>
                        </div>

                        <h2 className="text-xl font-bold text-gray-900 mt-4">{displayName}</h2>
                        <p className="text-sm font-semibold text-gray-900 mt-1">{accountNo}</p>

                        <div className="mt-8 space-y-4 text-left">
                            <div className="flex justify-between border-b border-gray-50 pb-3">
                                <span className="text-gray-400 text-sm">Plan</span>
                                <span className="text-gray-900 font-bold text-sm uppercase">{planName}</span>
                            </div>
                            <div className="flex justify-between border-b border-gray-50 pb-3">
                                <span className="text-gray-400 text-sm">Installed</span>
                                <span className="text-gray-900 font-bold text-sm">{installationDate}</span>
                            </div>
                            <div className="flex justify-between pb-3">
                                <span className="text-gray-400 text-sm">Location</span>
                                <span className="text-gray-900 font-bold text-sm text-right">{address}</span>
                            </div>
                        </div>

                        <div className="mt-8 space-y-3">
                            <button
                                onClick={() => onNavigate?.('customer-bills')}
                                className="w-full flex items-center justify-center space-x-2 py-3 border rounded-full font-semibold hover:bg-gray-50 transition"
                                style={{ borderColor: colorPalette?.primary || '#0f172a', color: colorPalette?.primary || '#0f172a' }}
                            >
                                <FileText className="w-4 h-4" />
                                <span>My Bills</span>
                            </button>
                            <button
                                onClick={() => onNavigate?.('customer-support')}
                                className="w-full flex items-center justify-center space-x-2 py-3 border rounded-full font-semibold hover:bg-gray-50 transition"
                                style={{ borderColor: colorPalette?.primary || '#0f172a', color: colorPalette?.primary || '#0f172a' }}
                            >
                                <HelpCircle className="w-4 h-4" />
                                <span>Help & Support</span>
                            </button>
                        </div>
                    </div>
                </div>

                {/* Right Column: Balance & History */}
                <div className="lg:col-span-2 space-y-8">
                    {/* Balance Card */}
                    <div className="rounded-3xl p-8 md:p-12 text-center text-white relative overflow-hidden" style={{ background: `linear-gradient(135deg, ${colorPalette?.primary || '#0f172a'} 0%, #000000 100%)` }}>
                        <h3 className="text-white text-sm font-medium tracking-wide uppercase mb-2 opacity-80">Total Amount Due</h3>
                        <div className="text-5xl md:text-6xl font-bold mb-4">₱{balance.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</div>
                        <div className="text-white text-sm mb-8 flex items-center justify-center space-x-2 opacity-90">
                            <span>Reference: <span className="text-white font-medium">{accountNo}</span></span>
                            {/* Due date could come from SOA service ideally */}
                            <span>|</span>
                            <span>Due: <span className="text-white">{dueDateString}</span></span>
                        </div>

                        <div className="flex justify-center space-x-4">
                            <button
                                onClick={handlePayNow}
                                disabled={isPaymentProcessing}
                                className="bg-white text-slate-900 px-8 py-3 rounded-full font-bold hover:bg-gray-100 transition min-w-[140px] disabled:opacity-50 disabled:cursor-not-allowed flex flex-col items-center justify-center leading-tight"
                                style={{ color: colorPalette?.primary || '#0f172a' }}
                            >
                                <span>{isPaymentProcessing ? 'Processing' : (pendingPayment && pendingPayment.payment_url) ? 'PROCEED PAYMENT' : 'PAY NOW'}</span>
                            </button>
                            <button
                                onClick={() => onNavigate?.('customer-bills', 'payments')}
                                className="bg-transparent border border-white text-white px-8 py-3 rounded-full font-bold hover:bg-white/10 transition min-w-[140px]"
                            >
                                History
                            </button>
                        </div>
                    </div>

                    {/* Recent Payments - Still Mocked for Now */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100 flex items-center space-x-2">
                            <Clock className="w-5 h-5" style={{ color: colorPalette?.primary || '#0f172a' }} />
                            <h3 className="font-bold" style={{ color: colorPalette?.primary || '#0f172a' }}>Recent Payments</h3>
                        </div>
                        <div>
                            {payments.length === 0 ? (
                                <div className="p-4 text-center text-gray-500">No payment history found.</div>
                            ) : (
                                payments.map((payment) => (
                                    <div key={payment.id} className="flex justify-between items-center p-4 border-b border-gray-50 last:border-0 hover:bg-gray-50 transition">
                                        <div className="text-sm text-gray-500">{payment.date}</div>
                                        <div className="text-sm font-mono text-gray-600 hidden md:block">{payment.reference}</div>
                                        <div className="text-sm font-bold text-green-600">+ ₱{payment.amount.toFixed(2)}</div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>

                    {/* My Referrals - Still Mocked for Now */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100 flex justify-between items-center">
                            <div className="flex items-center space-x-2">
                                <Users className="w-5 h-5" style={{ color: colorPalette?.primary || '#0f172a' }} />
                                <h3 className="font-bold" style={{ color: colorPalette?.primary || '#0f172a' }}>My Referrals</h3>
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="text-left text-xs font-bold text-gray-500 uppercase tracking-wider py-3 px-6">Date</th>
                                        <th className="text-left text-xs font-bold text-gray-500 uppercase tracking-wider py-3 px-6">Name</th>
                                        <th className="text-left text-xs font-bold text-gray-500 uppercase tracking-wider py-3 px-6">Stage</th>
                                        <th className="text-right text-xs font-bold text-gray-500 uppercase tracking-wider py-3 px-6">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {referrals.slice(0, 4).map((referral) => (
                                        <tr key={referral.id}>
                                            <td className="py-4 px-6 text-sm text-gray-500">{referral.date}</td>
                                            <td className="py-4 px-6 text-sm font-bold text-gray-900">{referral.name}</td>
                                            <td className="py-4 px-6 text-sm">
                                                <span className="bg-blue-100 text-blue-700 px-2 py-1 rounded text-xs font-medium">
                                                    {referral.stage}
                                                </span>
                                            </td>
                                            <td className="py-4 px-6 text-sm text-right">
                                                <span className={`px-3 py-1 rounded-full text-xs font-bold ${getStatusColor(referral.status)}`}>
                                                    {referral.status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>


            {/* PAYMENT VERIFY MODAL */}
            {
                showPaymentVerifyModal && (
                    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div className="bg-white rounded-lg shadow-xl max-w-md w-full">
                            <div className="p-6 border-b border-gray-200">
                                <h3 className="text-xl font-bold text-gray-900 text-center">Confirm Payment</h3>
                            </div>
                            <div className="p-6">
                                <div className="bg-gray-100 p-4 rounded mb-4">
                                    <div className="flex justify-between mb-2 text-gray-700">
                                        <span>Account:</span>
                                        <span className="font-bold">{displayName}</span>
                                    </div>
                                    <div className="flex justify-between text-gray-700">
                                        <span>Current Balance:</span>
                                        <span className={`font-bold ${balance > 0 ? 'text-red-500' : 'text-green-500'}`}>
                                            ₱{balance.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                                        </span>
                                    </div>
                                </div>

                                {errorMessage && (
                                    <div className="bg-red-50 p-3 rounded mb-4 border border-red-200">
                                        <p className="text-red-500 text-sm text-center">{errorMessage}</p>
                                    </div>
                                )}

                                <div className="mb-4">
                                    <label className="block font-bold mb-2 text-gray-700">Payment Amount</label>
                                    <input
                                        type="text"
                                        inputMode="decimal"
                                        value={paymentAmount || ''}
                                        onChange={(e) => {
                                            const value = e.target.value;
                                            if (value === '' || /^\d*\.?\d*$/.test(value)) {
                                                const newAmount = value === '' ? 0 : parseFloat(value) || 0;
                                                setPaymentAmount(newAmount);

                                                if (newAmount > 0 && newAmount < balance) {
                                                    setErrorMessage(`Payment amount cannot be lower than your balance of ₱${balance.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`);
                                                } else if (newAmount > 0 && newAmount < 1) {
                                                    setErrorMessage('Payment amount must be at least ₱1.00');
                                                } else {
                                                    setErrorMessage('');
                                                }
                                            }
                                        }}
                                        placeholder="0.00"
                                        className={`w-full px-4 py-3 rounded text-lg font-bold border ${paymentAmount > 0 && (paymentAmount < balance || paymentAmount < 1) ? 'border-red-500 ring-red-500' : 'border-gray-300'
                                            } text-gray-900 focus:outline-none focus:ring-2`}
                                        style={{ '--tw-ring-color': paymentAmount > 0 && (paymentAmount < balance || paymentAmount < 1) ? '#ef4444' : (colorPalette?.primary || '#0f172a') } as React.CSSProperties}
                                    />
                                    <div className="text-sm text-right mt-1 text-gray-500">
                                        {balance > 0 ? (
                                            <span>Outstanding: ₱{balance.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span>
                                        ) : (
                                            <span>Minimum: ₱1.00</span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex gap-3">
                                    <button
                                        onClick={handleCloseVerifyModal}
                                        disabled={isPaymentProcessing}
                                        className="flex-1 px-4 py-3 rounded font-bold bg-gray-200 text-gray-900 hover:bg-gray-300 transition-colors disabled:opacity-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        onClick={handleProceedToCheckout}
                                        disabled={isPaymentProcessing || (paymentAmount > 0 && paymentAmount < balance) || paymentAmount < 1}
                                        className="flex-1 px-4 py-3 rounded font-bold text-white transition-colors disabled:opacity-50"
                                        style={{ background: `linear-gradient(135deg, ${colorPalette?.primary || '#0f172a'} 0%, #000000 100%)` }}
                                    >
                                        {isPaymentProcessing ? 'Processing...' : 'Proceed to Pay'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )
            }

            {/* PAYMENT LINK MODAL */}
            {
                showPaymentLinkModal && paymentLinkData && (
                    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div className="bg-white rounded-lg shadow-xl max-w-md w-full text-center">
                            <div className="p-6 border-b border-gray-200">
                                <div className="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100 mb-4">
                                    <CheckCircle className="h-6 w-6 text-green-600" />
                                </div>
                                <h3 className="text-xl font-bold text-gray-900">Payment Link Created!</h3>
                                <p className="text-gray-500 mt-2">Reference: {paymentLinkData.referenceNo}</p>
                            </div>
                            <div className="p-6">
                                <p className="text-gray-600 mb-6">
                                    Please click the button below to complete your payment of
                                    <span className="font-bold text-gray-900"> ₱{paymentLinkData.amount.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span>
                                </p>
                                <button
                                    onClick={handleOpenPaymentLink}
                                    className="w-full px-4 py-3 rounded font-bold bg-green-600 text-white hover:bg-green-700 transition-colors mb-3"
                                >
                                    Open Payment Portal
                                </button>
                                <button
                                    onClick={handleCancelPaymentLink}
                                    className="text-gray-500 underline text-sm hover:text-gray-700"
                                >
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                )
            }

            {/* PENDING PAYMENT MODAL */}
            {
                showPendingPaymentModal && pendingPayment && (
                    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div className="bg-white rounded-lg shadow-xl max-w-md w-full text-center">
                            <div className="p-6 border-b border-gray-200">
                                <div className="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 mb-4">
                                    <Activity className="h-6 w-6 text-yellow-600" />
                                </div>
                                <h3 className="text-xl font-bold text-gray-900">Pending Payment Found</h3>
                            </div>
                            <div className="p-6">
                                <p className="text-gray-600 mb-6">
                                    You have a pending payment of
                                    <span className="font-bold text-gray-900"> ₱{pendingPayment.amount.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span>.
                                    Would you like to complete it?
                                </p>
                                
                                {errorMessage && (
                                    <div className="bg-red-50 p-3 rounded mb-4 border border-red-200">
                                        <p className="text-red-500 text-sm text-center">{errorMessage}</p>
                                    </div>
                                )}

                                <div className="flex flex-col sm:flex-row gap-3">
                                    <button
                                        onClick={handleClosePendingPaymentModal}
                                        disabled={isPaymentProcessing}
                                        className="sm:flex-1 px-4 py-3 rounded font-bold bg-gray-200 text-gray-900 hover:bg-gray-300 transition-colors disabled:opacity-50"
                                    >
                                        Close
                                    </button>
                                    <button
                                        onClick={handleCancelPendingPaymentFromDb}
                                        disabled={isPaymentProcessing}
                                        className="sm:flex-1 px-4 py-3 rounded font-bold bg-red-600 text-white hover:bg-red-700 transition-colors disabled:opacity-50"
                                    >
                                        {isPaymentProcessing ? 'Processing...' : 'Cancel Payment'}
                                    </button>
                                    <button
                                        onClick={handleResumePendingPayment}
                                        disabled={isPaymentProcessing}
                                        className="sm:flex-1 px-4 py-3 rounded font-bold text-white transition-colors disabled:opacity-50"
                                        style={{ background: `linear-gradient(135deg, ${colorPalette?.primary || '#0f172a'} 0%, #000000 100%)` }}
                                    >
                                        Resume Payment
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )
            }
            {/* PAYMENT SUCCESS MODAL */}
            {
                showPaymentSuccessModal && (
                    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div className="bg-white rounded-lg shadow-xl max-w-md w-full text-center">
                            <div className="p-6 border-b border-gray-200">
                                <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4">
                                    <CheckCircle className="h-8 w-8 text-green-600" />
                                </div>
                                <h3 className="text-xl font-bold text-gray-900">Payment Successful!</h3>
                            </div>
                            <div className="p-6">
                                <p className="text-gray-600 mb-6">
                                    Your payment has been received and is being processed. Your balance will be updated shortly.
                                </p>
                                <button
                                    onClick={() => setShowPaymentSuccessModal(false)}
                                    className="w-full px-4 py-3 rounded font-bold text-white transition-colors"
                                    style={{ background: `linear-gradient(135deg, ${colorPalette?.primary || '#0f172a'} 0%, #000000 100%)` }}
                                >
                                    OK
                                </button>
                            </div>
                        </div>
                    </div>
                )
            }
        </div >
    );
};

export default DashboardCustomer;
