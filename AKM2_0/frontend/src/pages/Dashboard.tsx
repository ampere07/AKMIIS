import React, { useState, useEffect } from 'react';
import { DCNoticeProvider } from '../contexts/DCNoticeContext';
import { StaggeredPaymentProvider } from '../contexts/StaggeredPaymentContext';
// import { DiscountProvider } from '../contexts/DiscountContext';
// import { ApplicationProvider } from '../contexts/ApplicationContext';
// import { ApplicationVisitProvider } from '../contexts/ApplicationVisitContext';
// import { JobOrderProvider } from '../contexts/JobOrderContext';
// import { ServiceOrderProvider } from '../contexts/ServiceOrderContext';
import DCNotice from './DCNotice';
import Discounts from './Discounts';
import Overdue from './Overdue';
import SOChargePage from './SOcharge';
import StaggeredPayment from './StaggeredPayment';
import MassRebate from './Rebate';
import SMSBlast from './SMSBlast';
import SMSBlastLogs from './SMSBlastLogs';
import DisconnectionLogs from './DisconnectionLogs';
import ReconnectionLogs from './ReconnectionLogs';
import SmsLogs from './SmsLogs';
import EmailLogs from './EmailLogs';
import DataLogs from './DataLogs';
import FileLogViewer from './FileLogViewer';
import Sidebar from './Sidebar';
import Header from './Header';
import DashboardContent from '../components/DashboardContent';
import UserManagement from './UserManagement';
import GroupManagement from './GroupManagement';
import ApplicationManagement from './ApplicationManagement';
import Customer from './Customer';
import BillingListView from './BillingListView';
import TransactionList from './TransactionList';
import TransactionsRevert from './TransactionsRevert';
import PaymentPortal from './PaymentPortal';
import JobOrder from './JobOrder';
import WorkOrder from './WorkOrder';
import ServiceOrder from './ServiceOrder';
// import ApplicationVisit from './ApplicationVisit';
import LocationList from './LocationList';
import PlanList from './PlanList';
import PromoList from './PromoList';
import RouterModelList from './RouterModelList';
import LcpList from './LcpList';
import NapList from './NapList';
import Inventory from './Inventory';
import ExpensesLog from './ExpensesLog';
import Logs from './Logs';
import SOA from './SOA';
import Invoice from './Invoice';
import InventoryCategoryList from './InventoryCategoryList';
import SOAGeneration from './SOAGeneration';
import UsageTypeList from './UsageTypeList';
import PaymentMethodList from './PaymentMethodList';
import WorkCategoryList from './WorkCategoryList';
import Ports from './Ports';
import StatusRemarksList from './StatusRemarksList';
import Settings from './Settings';
import LcpNapLocation from './LcpNapLocation';
import BillingConfig from './BillingConfig';
import RadiusConfig from './RadiusConfig';
import SmartOltConfig from './SmartOltConfig';
import SmsConfig from './SmsConfig';
import SMSTemplate from './SMSTemplate';
import EmailTemplates from './EmailTemplates';
import PPPoESetup from './PPPoESetup';
import Support from './Support';
import LiveMonitor from './LiveMonitor';
import ConcernConfig from './ConcernConfig';
import DashboardCustomer from './DashboardCustomer';
import Bills from './Bills';
import Reports from './Reports';
import TechUsers from './TechUsers';
import Organization from './organization';
import TeamAgent from './teamAgent';
import Roles from './roles';
import Commission from './Commission';
import AgentPayout from './AgentPayout';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { roleService } from '../services/userService';

interface DashboardProps {
    onLogout: () => void;
}

const Dashboard: React.FC<DashboardProps> = ({ onLogout }) => {
    const [userData, setUserData] = useState<any>(() => {
        try {
            const authData = localStorage.getItem('authData');
            return authData ? JSON.parse(authData) : null;
        } catch (error) {
            console.error('Error parsing user data:', error);
            return null;
        }
    });

    const [activeSection, setActiveSection] = useState(() => {
        try {
            const authData = localStorage.getItem('authData');
            if (authData) {
                const user = JSON.parse(authData);
                const normalizedRole = user.role?.toLowerCase().replace(/\s+/g, '') || '';
                if (normalizedRole === 'customer' || String(user.role_id) === '3') {
                    return 'customer-dashboard';
                }
                if (normalizedRole === 'technician' || String(user.role_id) === '2' || normalizedRole === 'agent' || String(user.role_id) === '4') {
                    return 'job-order';
                }
                if (String(user.role_id) === '7' || normalizedRole === 'superadmin') {
                    return 'dashboard';
                }
                if (normalizedRole === 'administrator' || String(user.role_id) === '1') {
                    return 'dashboard';
                }
                if (String(user.role_id) === '8' || normalizedRole === 'headtech') {
                    return 'application-management';
                }
                if (normalizedRole === 'osp' || String(user.role_id) === '6') {
                    return 'work-order';
                }
                if (normalizedRole === 'inventorystaff' || String(user.role_id) === '5') {
                    return 'inventory';
                }
                // Custom roles (role_id > 8): land on the first page in their permissions
                if (user.role_id > 8 && user.permissions && Array.isArray(user.permissions) && user.permissions.length > 0) {
                    return user.permissions[0];
                }
            }
        } catch (e) {
            console.error(e);
        }
        return 'dashboard';
    });

    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
    const [billsInitialTab, setBillsInitialTab] = useState<'soa' | 'invoices' | 'payments'>('soa');
    const [customerInitialSearch, setCustomerInitialSearch] = useState('');
    const [customerAutoOpenAccountNo, setCustomerAutoOpenAccountNo] = useState('');
    const [customerAutoOpenPayModal, setCustomerAutoOpenPayModal] = useState(false);
    const [planInitialSearch, setPlanInitialSearch] = useState('');
    const [isDarkMode, setIsDarkMode] = useState<boolean>(() => {
        try {
            const authData = localStorage.getItem('authData');
            if (authData) {
                const user = JSON.parse(authData);
                if (user.role === 'customer' || String(user.role_id) === '3') {
                    return false;
                }
            }
            const theme = localStorage.getItem('theme');
            return theme === 'dark' || theme === null;
        } catch (e) {
            return true;
        }
    });
    // Track dark mode changes
    useEffect(() => {
        const checkDarkMode = () => {
            const theme = localStorage.getItem('theme');
            setIsDarkMode(theme === 'dark' || theme === null);
        };

        checkDarkMode();

        const observer = new MutationObserver(() => {
            checkDarkMode();
        });

        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class']
        });

        const handleStorageChange = (e: StorageEvent) => {
            if (e.key === 'theme' || !e.key) {
                checkDarkMode();
                // Update document layout as well
                const theme = localStorage.getItem('theme');
                if (theme === 'dark' || theme === null) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            }
        };

        window.addEventListener('storage', handleStorageChange);

        return () => {
            observer.disconnect();
            window.removeEventListener('storage', handleStorageChange);
        };
    }, []);

    // Fetch permissions from API for custom roles if not available in authData
    useEffect(() => {
        if (!userData || !userData.role_id) return;
        const rid = Number(userData.role_id);
        if (rid <= 8) return; // Locked role, skip

        // Check if permissions are already available
        if (userData.permissions && Array.isArray(userData.permissions) && userData.permissions.length > 0) return;

        const fetchRolePermissions = async () => {
            try {
                const response = await roleService.getRoleById(rid);
                if (response.success && response.data) {
                    let perms: string[] = [];
                    const rawPerms = response.data.permissions;
                    if (Array.isArray(rawPerms)) {
                        perms = rawPerms;
                    } else if (typeof rawPerms === 'string') {
                        try {
                            const parsed = JSON.parse(rawPerms);
                            perms = Array.isArray(parsed) ? parsed : [];
                        } catch (e) {
                            perms = rawPerms.split(',').map(p => p.trim()).filter(Boolean);
                        }
                    }

                    if (perms.length > 0) {
                        // Update userData state
                        const updatedUser = { ...userData, permissions: perms };
                        setUserData(updatedUser);
                        // Update localStorage
                        localStorage.setItem('authData', JSON.stringify(updatedUser));
                        // Navigate to the first allowed page if currently on dashboard (default)
                        if (activeSection === 'dashboard') {
                            setActiveSection(perms[0]);
                        }
                    }
                }
            } catch (err) {
                console.error('Failed to fetch role permissions for custom role:', err);
            }
        };

        fetchRolePermissions();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [userData?.role_id]);

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
    }, []);

    const renderContent = () => {
        switch (activeSection) {
            // Customer Routes
            case 'customer-dashboard':
                return <DashboardCustomer
                    onNavigate={(section, tab) => handleSectionChange(section, tab)}
                    autoOpenPayModal={customerAutoOpenPayModal}
                />;
            case 'customer-bills':
                return <Bills initialTab={billsInitialTab} onNavigate={handleSectionChange} />;
            case 'customer-support':
                return <Support forceLightMode={true} />;

            case 'live-monitor':
                return <LiveMonitor />;
            case 'support':
                return <Support />;
            case 'soa':
                return <SOA />;
            case 'invoice':
                return <Invoice />;
            case 'overdue':
                return <Overdue />;
            case 'so-charge':
                return <SOChargePage />;
            case 'dc-notice':
                return <DCNotice />;
            case 'discounts':
                return <Discounts />;
            case 'billing-config':
                return <BillingConfig />;
            case 'radius-config':
                return <RadiusConfig />;
            case 'smart-olt':
                return <SmartOltConfig />;
            case 'sms-config':
                return <SmsConfig />;
            case 'sms-template':
                return <SMSTemplate />;
            case 'email-templates':
                return <EmailTemplates />;
            case 'pppoe-setup':
                return <PPPoESetup />;
            case 'concern-config':
                return <ConcernConfig />;


            case 'staggered-payment':
                return <StaggeredPayment />;
            case 'mass-rebate':
                return <MassRebate />;
            case 'sms-blast':
                return <SMSBlast />;
            case 'sms-blast-logs':
                return <SMSBlastLogs />;
            case 'disconnected-logs':
                return <DisconnectionLogs />;
            case 'reconnection-logs':
                return <ReconnectionLogs />;
            case 'sms-logs':
                return <SmsLogs />;
            case 'email-logs':
                return <EmailLogs />;
            case 'data-logs':
                return <DataLogs />;
            case 'smart-olt-logs':
                return <FileLogViewer type="smartolt" title="Smart OLT Logs" />;
            case 'radius-logs':
                return <FileLogViewer type="radius" title="Radius Logs" />;
            case 'agent-management':
                return <UserManagement agentOnly />;
            case 'user-management':
                return <UserManagement />;
            case 'tech-users':
                return <TechUsers />;
            case 'organization':
                return <Organization />;
            case 'team-agent':
                return <TeamAgent />;
            case 'roles':
                return <Roles />;
            case 'group-management':
                return <GroupManagement />;
            case 'application-management':
                return <ApplicationManagement onNavigate={handleSectionChange} />;
            case 'customer':
                return <Customer initialSearchQuery={customerInitialSearch} autoOpenAccountNo={customerAutoOpenAccountNo} />;
            case 'transaction-list':
                return (
                    <TransactionList onNavigate={(section, search) => handleSectionChange(section, search)} />
                );
            case 'transactions-revert':
                return <TransactionsRevert />;
            case 'payment-portal':
                return <PaymentPortal />;
            case 'job-order':
                return <JobOrder />;
            case 'work-order':
                return <WorkOrder />;
            case 'service-order':
                return <ServiceOrder />;
            case 'reports':
                return <Reports />;
            case 'commission':
                return <Commission />;
            case 'agent-payout':
                return <AgentPayout />;
            // case 'application-visit':
            //     return <ApplicationVisit />;
            case 'location-list':
                return <LocationList />;
            case 'plan-list':
                return <PlanList onNavigate={handleSectionChange} initialSearchQuery={planInitialSearch} />;
            case 'promo-list':
                return <PromoList />;
            case 'router-models':
                return <RouterModelList />;
            case 'lcp':
                return <LcpList />;
            case 'nap':
                return <NapList />;
            case 'lcp-nap-location':
                return <LcpNapLocation />;
            case 'usage-type':
                return <UsageTypeList />;
            case 'payment-method':
                return <PaymentMethodList />;
            case 'work-category':
                return <WorkCategoryList />;
            case 'ports':
                return <Ports />;
            case 'status-remarks-list':
                return <StatusRemarksList />;
            case 'inventory':
                return <Inventory />;
            case 'inventory-category-list':
                return <InventoryCategoryList />;
            case 'expenses-log':
                return <ExpensesLog />;
            case 'system-logs':
                return <Logs />;
            case 'soa-generation':
                return <SOAGeneration />;
            case 'settings':
                return <Settings />;
            case 'dashboard':
            default:
                if (userData && String(userData.role_id) === '3') {
                    return <DashboardCustomer onNavigate={(section, tab) => handleSectionChange(section, tab)} />;
                }
                return <DashboardContent />;
        }
    };

    const handleSearch = (query: string) => {
        setSearchQuery(query);
    };

    const toggleSidebar = () => {
        const isMobile = window.innerWidth < 768;
        if (isMobile) {
            setIsMobileMenuOpen(!isMobileMenuOpen);
        } else {
            setSidebarCollapsed(!sidebarCollapsed);
        }
    };

    const closeMobileMenu = () => {
        setIsMobileMenuOpen(false);
    };

    const handleSectionChange = (section: string, extra?: string) => {
        setActiveSection(section);
        if (section === 'customer-bills') {
            setBillsInitialTab((extra as any) || 'soa');
        } else if (section === 'customer-dashboard') {
            if (extra === 'paynow') {
                setCustomerAutoOpenPayModal(true);
            } else {
                setCustomerAutoOpenPayModal(false);
            }
        } else if (section === 'customer') {
            setCustomerInitialSearch(extra || '');
            setCustomerAutoOpenAccountNo(extra || '');
        } else if (section === 'plan-list') {
            setPlanInitialSearch(extra || '');
        }

        if (window.innerWidth < 768) {
            closeMobileMenu();
        }
    };

    // Helper to determine if we should show sidebar
    const showSidebar = userData && String(userData.role_id) !== '3';

    return (
        <DCNoticeProvider>
            <StaggeredPaymentProvider>
                {/* <DiscountProvider> */}
                {/* <ApplicationProvider> */}
                {/* <ApplicationVisitProvider> */}
                {/* <JobOrderProvider> */}
                {/* <ServiceOrderProvider> */}
                <div className={`h-screen flex flex-col overflow-hidden ${isDarkMode ? 'bg-gray-950' : 'bg-gray-50'
                    }`}>
                    {/* Fixed Header */}
                    <div className="flex-shrink-0">
                        <Header
                            onSearch={handleSearch}
                            onToggleSidebar={toggleSidebar}
                            onNavigate={handleSectionChange}
                            onLogout={onLogout}
                            activeSection={activeSection}
                        />
                    </div>

                    {/* Main Content Area with Fixed Sidebar and Scrollable Content */}
                    <div className="flex-1 flex overflow-hidden">
                        {/* Mobile Overlay */}
                        {isMobileMenuOpen && (
                            <div
                                className="fixed inset-0 bg-black bg-opacity-50 z-40 md:hidden"
                                onClick={closeMobileMenu}
                            />
                        )}

                        {/* Fixed Sidebar */}
                        {showSidebar && (
                            <div className={`flex-shrink-0 fixed md:relative z-50 transition-all duration-300 top-0 md:top-auto left-0 ${isMobileMenuOpen ? 'translate-x-0' : '-translate-x-full'
                                } md:translate-x-0 h-screen md:h-auto`}>
                                <div className="h-full md:h-full">
                                    <Sidebar
                                        activeSection={activeSection}
                                        onSectionChange={handleSectionChange}
                                        onLogout={onLogout}
                                        isCollapsed={sidebarCollapsed}
                                        userRole={userData?.role || ''}
                                        roleId={userData?.role_id}
                                        organizationId={userData?.organization?.id || userData?.organization_id}
                                        userEmail={userData?.email || ''}
                                        permissions={userData?.permissions || null}
                                    />
                                </div>
                            </div>
                        )}

                        {/* Scrollable Content Area Only */}
                        <div className={`flex-1 overflow-hidden ${isDarkMode ? 'bg-gray-950' : 'bg-gray-50'
                            }`}>
                            <div className="h-full overflow-y-auto">
                                {renderContent()}
                            </div>
                        </div>
                    </div>
                </div>
                {/* </ServiceOrderProvider> */}
                {/* </JobOrderProvider> */}
                {/* </ApplicationProvider> */}
                {/* </ApplicationVisitProvider> */}
                {/* </DiscountProvider> */}
            </StaggeredPaymentProvider>
        </DCNoticeProvider >
    );
};

export default Dashboard;