import React, { useState, useEffect, useRef } from 'react';
import { getBillingRecords, generateCustomBilling, BillingRecord } from '../services/billingService';
import ModalUITemplate from './ui-modal/ModalUITemplate';

interface GenerateBillingModalProps {
  isOpen: boolean;
  onClose: () => void;
  colorPalette?: { primary: string; secondary?: string; accent?: string } | null;
  isDarkMode?: boolean;
}

interface AlertState {
  isOpen: boolean;
  type: 'success' | 'error';
  title: string;
  message: string;
}

const GenerateBillingModal: React.FC<GenerateBillingModalProps> = ({
  isOpen,
  onClose,
  colorPalette,
  isDarkMode = true,
}) => {
  const [accounts, setAccounts] = useState<BillingRecord[]>([]);
  const [loadingAccounts, setLoadingAccounts] = useState(false);
  const [selectedAccountNo, setSelectedAccountNo] = useState('');
  const [serviceCharge, setServiceCharge] = useState('0.00');
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');

  const [isGeneratingBilling, setIsGeneratingBilling] = useState(false);
  const [loadingPct, setLoadingPct] = useState(0);
  const progressIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  // Synchronous guard against a double-submit: the disabled button only takes
  // effect after a re-render, so two clicks fired in the same tick can both
  // reach handleGenerate. This ref blocks the second one immediately.
  const submittingRef = useRef(false);

  const [alert, setAlert] = useState<AlertState>({ isOpen: false, type: 'success', title: '', message: '' });

  const dropdownRef = useRef<HTMLDivElement>(null);
  const primary = colorPalette?.primary || '#7c3aed';

  /* ── theme-aware colours for the modal body ─────────────────────── */
  const surface = isDarkMode ? '#1f2937' : '#f9fafb';
  const border = isDarkMode ? '#374151' : '#e5e7eb';
  const text = isDarkMode ? '#f9fafb' : '#111827';
  const subtext = isDarkMode ? '#9ca3af' : '#6b7280';
  const inputBg = isDarkMode ? '#111827' : '#ffffff';

  /* ── load accounts on open ──────────────────────────────────────── */
  useEffect(() => {
    if (!isOpen) return;
    setLoadingAccounts(true);

    const fetchAllAccounts = async () => {
      const allRecords: BillingRecord[] = [];
      let page = 1;
      let hasMore = true;

      while (hasMore) {
        const { data, hasMore: more } = await getBillingRecords(page, 500);
        allRecords.push(...data);
        hasMore = more;
        page++;
      }

      setAccounts(allRecords);
    };

    fetchAllAccounts()
      .catch(console.error)
      .finally(() => setLoadingAccounts(false));
  }, [isOpen]);

  /* ── close dropdown when clicking outside ───────────────────────── */
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  /* ── reset on close ─────────────────────────────────────────────── */
  useEffect(() => {
    if (!isOpen) {
      setSelectedAccountNo('');
      setServiceCharge('0.00');
      setDropdownOpen(false);
      setSearchQuery('');
      setAlert({ isOpen: false, type: 'success', title: '', message: '' });
      setLoadingPct(0);
      if (progressIntervalRef.current) clearInterval(progressIntervalRef.current);
    }
  }, [isOpen]);

  const filteredAccounts = accounts.filter(a => {
    const q = searchQuery.toLowerCase();
    return (
      a.customerName?.toLowerCase().includes(q) ||
      a.accountNo?.toLowerCase().includes(q) ||
      a.account_no?.toLowerCase().includes(q)
    );
  });

  const selectedAccount = accounts.find(
    a => (a.accountNo || a.account_no) === selectedAccountNo
  );

  const handleServiceChargeChange = (val: string) => {
    // allow only numeric + decimal
    const cleaned = val.replace(/[^0-9.]/g, '');
    setServiceCharge(cleaned);
  };

  const handleServiceChargeBlur = () => {
    const num = parseFloat(serviceCharge) || 0;
    setServiceCharge(num.toFixed(2));
  };

  const startProgress = () => {
    setLoadingPct(0);
    progressIntervalRef.current = setInterval(() => {
      setLoadingPct(prev => {
        if (prev >= 90) return Math.min(99, prev + 0.5);
        return Math.min(90, prev + 8);
      });
    }, 300);
  };

  const finishProgress = (success: boolean) => {
    if (progressIntervalRef.current) {
      clearInterval(progressIntervalRef.current);
      progressIntervalRef.current = null;
    }
    setLoadingPct(success ? 100 : 0);
  };

  const handleGenerate = async () => {
    // Block a duplicate in-flight submission (fast double-click / retry).
    if (submittingRef.current || isGeneratingBilling) return;

    if (!selectedAccountNo) {
      setAlert({ isOpen: true, type: 'error', title: 'Validation Error', message: 'Please select a customer account.' });
      return;
    }

    submittingRef.current = true;
    setIsGeneratingBilling(true);
    setAlert({ isOpen: false, type: 'success', title: '', message: '' });
    startProgress();

    const charge = parseFloat(serviceCharge) || 0;

    try {
      const result = await generateCustomBilling(selectedAccountNo, charge);
      finishProgress(result.success);

      if (result.success) {
        const data = result.data || {};
        const soaId = data.soa?.id ? `#${data.soa.id}` : '';
        const invId = data.invoice?.id ? `#${data.invoice.id}` : '';
        const emailStatus = data.notifications?.email_queued ? '✓ Email queued' : '✗ Email not sent';
        const smsStatus = data.notifications?.sms_sent ? '✓ SMS sent' : '✗ SMS not sent';
        const chargeNote = charge > 0 ? `\nService Charge Applied: ₱${charge.toFixed(2)}` : '';

        setAlert({
          isOpen: true,
          type: 'success',
          title: 'Billing Generated Successfully!',
          message: `Customer: ${data.customer_name || selectedAccountNo}${chargeNote}\nSOA ${soaId} & Invoice ${invId} created.\n${emailStatus} · ${smsStatus}`,
        });
      } else {
        setAlert({ isOpen: true, type: 'error', title: 'Generation Failed', message: result.message || 'An unexpected error occurred.' });
      }
    } catch (err: any) {
      finishProgress(false);
      setAlert({ isOpen: true, type: 'error', title: 'Error', message: err?.message || 'Unexpected error occurred.' });
    } finally {
      submittingRef.current = false;
      setTimeout(() => setIsGeneratingBilling(false), 400);
    }
  };

  return (
    <ModalUITemplate
      isOpen={isOpen}
      // Guard close so the modal cannot be dismissed while generating.
      onClose={() => { if (!isGeneratingBilling) onClose(); }}
      title="Generate Billing"
      isDarkMode={isDarkMode}
      colorPalette={colorPalette as any}
      loading={isGeneratingBilling}
      loadingPercentage={Math.round(loadingPct)}
      maxWidth="max-w-md"
      closeOnOutsideClick={!isGeneratingBilling}
      primaryAction={{
        label: 'Generate Billing',
        onClick: handleGenerate,
        disabled: isGeneratingBilling || !selectedAccountNo,
      }}
      secondaryActionLabel="Cancel"
      alertModal={{
        isOpen: alert.isOpen,
        type: alert.type,
        title: alert.title,
        message: alert.message,
        onConfirm: () => setAlert(a => ({ ...a, isOpen: false })),
      }}
    >
      {/* ── Customer Dropdown ── */}
      <div>
        <label className="block text-sm font-semibold mb-2" style={{ color: text }}>
          Customer Account <span style={{ color: '#ef4444' }}>*</span>
        </label>
        <div ref={dropdownRef} className="relative">
          <button
            id="billing-customer-select"
            onClick={() => !loadingAccounts && setDropdownOpen(p => !p)}
            disabled={loadingAccounts || isGeneratingBilling}
            className="w-full flex items-center justify-between rounded-xl px-4 py-3 text-sm transition-all"
            style={{
              backgroundColor: inputBg,
              border: `1.5px solid ${dropdownOpen ? primary : border}`,
              color: selectedAccount ? text : subtext,
              boxShadow: dropdownOpen ? `0 0 0 3px ${primary}25` : 'none',
              cursor: loadingAccounts ? 'wait' : 'pointer',
            }}
          >
            <span className="truncate">
              {loadingAccounts
                ? 'Loading accounts…'
                : selectedAccount
                  ? `${selectedAccount.customerName} — ${selectedAccount.accountNo || selectedAccount.account_no}`
                  : 'Select a customer…'}
            </span>
            <span
              className="ml-2 text-xs"
              style={{ color: subtext, transition: 'transform 0.2s', transform: dropdownOpen ? 'rotate(180deg)' : 'none' }}
            >
              ▾
            </span>
          </button>

          {dropdownOpen && (
            <div
              className="absolute z-10 w-full mt-1 rounded-xl overflow-hidden"
              style={{
                backgroundColor: surface,
                border: `1.5px solid ${primary}50`,
                boxShadow: `0 8px 32px rgba(0,0,0,0.3)`,
                maxHeight: '280px',
              }}
            >
              {/* Search */}
              <div style={{ padding: '8px', borderBottom: `1px solid ${border}` }}>
                <input
                  autoFocus
                  type="text"
                  placeholder="Search by name or account no…"
                  value={searchQuery}
                  onChange={e => setSearchQuery(e.target.value)}
                  className="w-full text-sm rounded-lg px-3 py-2 outline-none"
                  style={{ backgroundColor: inputBg, border: `1px solid ${border}`, color: text }}
                />
              </div>
              {/* List */}
              <div className="overflow-y-auto" style={{ maxHeight: '210px' }}>
                {filteredAccounts.length === 0 ? (
                  <div className="px-4 py-3 text-sm" style={{ color: subtext }}>
                    No accounts found
                  </div>
                ) : (
                  filteredAccounts.map(account => {
                    const accNo = account.accountNo || account.account_no || '';
                    const isSelected = accNo === selectedAccountNo;
                    return (
                      <button
                        key={accNo}
                        className="w-full text-left flex items-center justify-between px-4 py-3 text-sm transition-colors"
                        style={{
                          backgroundColor: isSelected ? `${primary}20` : 'transparent',
                          color: text,
                          borderLeft: isSelected ? `3px solid ${primary}` : '3px solid transparent',
                        }}
                        onMouseEnter={e => { if (!isSelected) e.currentTarget.style.backgroundColor = `${border}60`; }}
                        onMouseLeave={e => { if (!isSelected) e.currentTarget.style.backgroundColor = 'transparent'; }}
                        onClick={() => {
                          setSelectedAccountNo(accNo);
                          setDropdownOpen(false);
                          setSearchQuery('');
                        }}
                      >
                        <span className="font-medium truncate">{account.customerName}</span>
                        <span className="text-xs ml-2 shrink-0" style={{ color: subtext }}>{accNo}</span>
                      </button>
                    );
                  })
                )}
              </div>
            </div>
          )}
        </div>

        {selectedAccount && (
          <div className="mt-2 text-xs flex items-center gap-1" style={{ color: subtext }}>
            <span>Plan:</span>
            <span style={{ color: primary }}>{selectedAccount.plan || selectedAccount.desiredPlan || 'N/A'}</span>
            <span className="ml-3">Status:</span>
            <span style={{ color: '#10b981' }}>{selectedAccount.billingStatus || 'Active'}</span>
          </div>
        )}
      </div>

      {/* ── Service Charge ── */}
      <div>
        <label className="block text-sm font-semibold mb-2" style={{ color: text }}>
          Service Charge <span className="font-normal text-xs" style={{ color: subtext }}>(optional · ₱)</span>
        </label>
        <div className="relative">
          <div className="absolute left-3 top-1/2 -translate-y-1/2 font-medium" style={{ color: subtext }}>
            ₱
          </div>
          <input
            id="billing-service-charge"
            type="text"
            inputMode="decimal"
            value={serviceCharge}
            onChange={e => handleServiceChargeChange(e.target.value)}
            onFocus={() => { if (serviceCharge === '0.00') setServiceCharge(''); }}
            onBlur={handleServiceChargeBlur}
            disabled={isGeneratingBilling}
            className="w-full pl-9 pr-4 py-3 text-sm rounded-xl outline-none transition-all"
            style={{
              backgroundColor: inputBg,
              border: `1.5px solid ${parseFloat(serviceCharge) > 0 ? primary : border}`,
              color: text,
              boxShadow: parseFloat(serviceCharge) > 0 ? `0 0 0 3px ${primary}25` : 'none',
            }}
          />
        </div>
        <p className="text-xs mt-2" style={{ color: subtext }}>
          {parseFloat(serviceCharge) > 0
            ? `₱${parseFloat(serviceCharge).toFixed(2)} will be added as a service charge to this billing cycle.`
            : 'Leave at 0.00 to generate billing without an additional service charge.'}
        </p>
      </div>

      {/* ── Info box ── */}
      <div
        className="rounded-xl p-4"
        style={{ backgroundColor: `${primary}10`, border: `1px solid ${primary}30` }}
      >
        <p className="text-xs font-semibold mb-1" style={{ color: primary }}>What will happen?</p>
        <ul className="text-xs space-y-1" style={{ color: subtext }}>
          <li>• A Statement of Account (SOA) will be generated</li>
          <li>• An Invoice will be generated for the selected account</li>
          <li>• PDFs will be saved to Google Drive automatically</li>
          <li>• Email &amp; SMS notifications will be sent immediately</li>
          {parseFloat(serviceCharge) > 0 && (
            <li style={{ color: primary }}>• ₱{parseFloat(serviceCharge).toFixed(2)} service charge will be applied to the SOA</li>
          )}
        </ul>
      </div>
    </ModalUITemplate>
  );
};

export default GenerateBillingModal;
