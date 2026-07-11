import React, { useState, useEffect } from 'react';
import { Role, ApiResponse } from '../types/api';
import { roleService } from '../services/userService';
import ModalUITemplate, { useModalTheme } from './ui-modal/ModalUITemplate';

interface RoleModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSave: (role: Role) => void;
  role?: Role | null;
}

const SYSTEM_PAGES = [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'live-monitor', label: 'Monitoring' },
  { id: 'customer', label: 'Customer' },
  { id: 'transaction-list', label: 'Transaction List' },
  { id: 'transactions-revert', label: 'Revert Requests' },
  { id: 'payment-portal', label: 'Payment Portal' },
  { id: 'soa', label: 'Statements' },
  { id: 'invoice', label: 'Invoice' },
  { id: 'overdue', label: 'Overdue' },
  { id: 'so-charge', label: 'SO Charge' },
  { id: 'dc-notice', label: 'DC Notice' },
  { id: 'mass-rebate', label: 'Rebates' },
  { id: 'staggered-payment', label: 'Staggered' },
  { id: 'discounts', label: 'Discounts' },
  { id: 'application-management', label: 'Application' },
  { id: 'job-order', label: 'Job Order' },
  { id: 'service-order', label: 'Service Order' },
  { id: 'work-order', label: 'Work Order' },
  { id: 'lcp-nap-location', label: 'LCP/NAP Location' },
  { id: 'sms-blast', label: 'SMS Blast' },
  { id: 'inventory', label: 'Inventory' },
  { id: 'inventory-category-list', label: 'Inventory Category List' },
  { id: 'promo-list', label: 'Promo' },
  { id: 'plan-list', label: 'Plan' },
  { id: 'location-list', label: 'Location' },
  { id: 'lcp', label: 'LCP' },
  { id: 'nap', label: 'NAP' },
  { id: 'usage-type', label: 'Usage Type' },
  { id: 'vlan-config', label: 'VLAN Config' },
  { id: 'payment-method', label: 'Payment Method' },
  { id: 'work-category', label: 'Work Category' },
  { id: 'radius-config', label: 'Radius Config' },
  { id: 'smart-olt', label: 'SmartOLT Config' },
  { id: 'sms-config', label: 'SMS Config' },
  { id: 'sms-template', label: 'SMS Template' },
  { id: 'email-templates', label: 'Email Templates' },
  { id: 'pppoe-setup', label: 'PPPoE Setup' },
  { id: 'concern-config', label: 'Concern Config' },
  { id: 'billing-config', label: 'Billing Configurations' },
  { id: 'user-management', label: 'Users Management' },
  { id: 'tech-users', label: 'Tech Users' },
  { id: 'team-agent', label: 'Team Agents' },
  { id: 'organization', label: 'Organization' },
  { id: 'roles', label: 'Roles Management' },
  { id: 'disconnected-logs', label: 'Disconnected Logs' },
  { id: 'reconnection-logs', label: 'Reconnection Logs' },
  { id: 'sms-logs', label: 'SMS Logs' },
  { id: 'email-logs', label: 'Email Logs' },
  { id: 'smart-olt-logs', label: 'Smart OLT Logs' },
  { id: 'radius-logs', label: 'Radius Logs' },
  { id: 'system-logs', label: 'System Logs' },
  { id: 'settings', label: 'Settings' },
];

const JOB_ORDER_SUB_PERMISSIONS = [
  { id: 'job-order.approve', label: 'Approve' },
  { id: 'job-order.failed', label: 'Failed' },
  { id: 'job-order.tech-edit', label: 'Tech Edit' },
  { id: 'job-order.admin-edit', label: 'Admin Edit' },
  { id: 'job-order.attachment', label: 'Attachment' },
];

const CUSTOMER_SUB_PERMISSIONS = [
  { id: 'customer.so-request', label: 'SO Request' },
  { id: 'customer.details-edit', label: 'Details Edit' },
  { id: 'customer.attachment', label: 'Attachment' },
  { id: 'customer.transact', label: 'Transact' },
];

const TRANSACTION_SUB_PERMISSIONS = [
  { id: 'transaction-list.batch-approve', label: 'Batch Approve' },
  { id: 'transaction-list.approve', label: 'Approve' },
  { id: 'transaction-list.revert-request', label: 'Revert Request' },
];

const REBATE_SUB_PERMISSIONS = [
  { id: 'mass-rebate.add', label: 'Add Rebate' },
];

const STAGGERED_SUB_PERMISSIONS = [
  { id: 'staggered-payment.add', label: 'Add Staggered' },
];

const DISCOUNT_SUB_PERMISSIONS = [
  { id: 'discounts.add', label: 'Add Discount' },
];

const APPLICATION_SUB_PERMISSIONS = [
  { id: 'application-management.move-to-jo', label: 'Move to JO' },
  { id: 'application-management.quick-status', label: 'Quick Status' },
];

const SERVICE_ORDER_SUB_PERMISSIONS = [
  { id: 'service-order.tech-edit', label: 'Tech Edit' },
  { id: 'service-order.admin-edit', label: 'Admin Edit' },
];

const RoleForm: React.FC<{
  formData: any;
  handleInputChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => void;
  handlePermissionChange: (pageId: string, checked: boolean) => void;
  errors: Record<string, string>;
  selectedPermissions: string[];
}> = ({ formData, handleInputChange, handlePermissionChange, errors, selectedPermissions }) => {
  const { isDarkMode } = useModalTheme();

  const inputClass = (error?: string) => `w-full px-4 py-2.5 rounded-lg border transition-all duration-200 outline-none focus:ring-2 focus:ring-opacity-50 
    ${isDarkMode
      ? `bg-gray-800 text-white ${error ? 'border-red-500 focus:ring-red-500/20' : 'border-gray-700 focus:ring-blue-500/20'}`
      : `bg-white text-gray-900 ${error ? 'border-red-500 focus:ring-red-500/20' : 'border-gray-200 focus:ring-blue-500/20'}`
    }`;

  const labelClass = `block text-sm font-medium mb-1.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`;

  return (
    <div className="space-y-6">
      {errors.general && (
        <div className={`p-4 border rounded-xl text-sm font-medium ${isDarkMode ? 'bg-red-900/20 border-red-800/30 text-red-400' : 'bg-red-50 border-red-200 text-red-600'}`}>
          {errors.general}
        </div>
      )}

      <div className="space-y-4">
        <div>
          <label className={labelClass}>Role Name*</label>
          <input
            name="role_name"
            value={formData.role_name}
            onChange={handleInputChange}
            className={inputClass(errors.role_name)}
            placeholder="e.g. Administrator, Agent"
          />
          {errors.role_name && <p className="text-red-500 text-xs mt-1.5 font-medium ml-1">{errors.role_name}</p>}
        </div>

        <div>
          <label className={labelClass}>Description</label>
          <textarea
            name="description"
            value={formData.description}
            onChange={handleInputChange}
            className={inputClass()}
            placeholder="Briefly describe the role's responsibilities"
            rows={2}
          />
        </div>

        <div>
          <label className={labelClass}>Permissions (Page Access)</label>
          <div className={`border rounded-lg overflow-hidden ${isDarkMode ? 'border-gray-700' : 'border-gray-200'}`}>
            <div className={`grid grid-cols-[1.5fr_80px_2fr] px-4 py-2 text-xs font-bold uppercase tracking-wider border-b ${isDarkMode ? 'bg-gray-800 text-gray-400 border-gray-700' : 'bg-gray-50 text-gray-500 border-gray-200'}`}>
              <div>Page Name</div>
              <div className="text-center">Access</div>
              <div></div>
            </div>
            <div className="max-h-[300px] overflow-y-auto divide-y divide-gray-200 dark:divide-gray-700">
              {SYSTEM_PAGES.map((page) => (
                <React.Fragment key={page.id}>
                  <div className={`grid grid-cols-[1.5fr_80px_2fr] px-4 py-3 items-center transition-colors`}>
                    <div className={`text-sm ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>{page.label}</div>
                    <div className="flex justify-center">
                      <input
                        type="checkbox"
                        checked={selectedPermissions.includes(page.id)}
                        onChange={(e) => handlePermissionChange(page.id, e.target.checked)}
                        className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                      />
                    </div>
                    <div className="flex items-center gap-x-6">
                      {page.id === 'job-order' && JOB_ORDER_SUB_PERMISSIONS.map((sub) => (
                        <div key={sub.id} className="flex flex-col items-center gap-1.5">
                          <span className={`text-[10px] font-bold uppercase tracking-tight leading-none whitespace-nowrap ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{sub.label}</span>
                          <input
                            type="checkbox"
                            checked={selectedPermissions.includes(sub.id)}
                            onChange={(e) => handlePermissionChange(sub.id, e.target.checked)}
                            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                          />
                        </div>
                      ))}
                      {page.id === 'customer' && CUSTOMER_SUB_PERMISSIONS.map((sub) => (
                        <div key={sub.id} className="flex flex-col items-center gap-1.5">
                          <span className={`text-[10px] font-bold uppercase tracking-tight leading-none whitespace-nowrap ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{sub.label}</span>
                          <input
                            type="checkbox"
                            checked={selectedPermissions.includes(sub.id)}
                            onChange={(e) => handlePermissionChange(sub.id, e.target.checked)}
                            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                          />
                        </div>
                      ))}
                      {page.id === 'transaction-list' && TRANSACTION_SUB_PERMISSIONS.map((sub) => (
                        <div key={sub.id} className="flex flex-col items-center gap-1.5">
                          <span className={`text-[10px] font-bold uppercase tracking-tight leading-none whitespace-nowrap ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{sub.label}</span>
                          <input
                            type="checkbox"
                            checked={selectedPermissions.includes(sub.id)}
                            onChange={(e) => handlePermissionChange(sub.id, e.target.checked)}
                            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                          />
                        </div>
                      ))}
                      {page.id === 'mass-rebate' && REBATE_SUB_PERMISSIONS.map((sub) => (
                        <div key={sub.id} className="flex flex-col items-center gap-1.5">
                          <span className={`text-[10px] font-bold uppercase tracking-tight leading-none whitespace-nowrap ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{sub.label}</span>
                          <input
                            type="checkbox"
                            checked={selectedPermissions.includes(sub.id)}
                            onChange={(e) => handlePermissionChange(sub.id, e.target.checked)}
                            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                          />
                        </div>
                      ))}
                      {page.id === 'staggered-payment' && STAGGERED_SUB_PERMISSIONS.map((sub) => (
                        <div key={sub.id} className="flex flex-col items-center gap-1.5">
                          <span className={`text-[10px] font-bold uppercase tracking-tight leading-none whitespace-nowrap ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{sub.label}</span>
                          <input
                            type="checkbox"
                            checked={selectedPermissions.includes(sub.id)}
                            onChange={(e) => handlePermissionChange(sub.id, e.target.checked)}
                            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                          />
                        </div>
                      ))}
                      {page.id === 'discounts' && DISCOUNT_SUB_PERMISSIONS.map((sub) => (
                        <div key={sub.id} className="flex flex-col items-center gap-1.5">
                          <span className={`text-[10px] font-bold uppercase tracking-tight leading-none whitespace-nowrap ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{sub.label}</span>
                          <input
                            type="checkbox"
                            checked={selectedPermissions.includes(sub.id)}
                            onChange={(e) => handlePermissionChange(sub.id, e.target.checked)}
                            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                          />
                        </div>
                      ))}
                      {page.id === 'application-management' && APPLICATION_SUB_PERMISSIONS.map((sub) => (
                        <div key={sub.id} className="flex flex-col items-center gap-1.5">
                          <span className={`text-[10px] font-bold uppercase tracking-tight leading-none whitespace-nowrap ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{sub.label}</span>
                          <input
                            type="checkbox"
                            checked={selectedPermissions.includes(sub.id)}
                            onChange={(e) => handlePermissionChange(sub.id, e.target.checked)}
                            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                          />
                        </div>
                      ))}
                      {page.id === 'service-order' && SERVICE_ORDER_SUB_PERMISSIONS.map((sub) => (
                        <div key={sub.id} className="flex flex-col items-center gap-1.5">
                          <span className={`text-[10px] font-bold uppercase tracking-tight leading-none whitespace-nowrap ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{sub.label}</span>
                          <input
                            type="checkbox"
                            checked={selectedPermissions.includes(sub.id)}
                            onChange={(e) => handlePermissionChange(sub.id, e.target.checked)}
                            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                          />
                        </div>
                      ))}
                    </div>
                  </div>
                </React.Fragment>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

const RoleModal: React.FC<RoleModalProps> = ({ isOpen, onClose, onSave, role }) => {
  const isEditMode = !!role;
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [formData, setFormData] = useState({
    role_name: '',
    description: '',
  });

  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([]);

  useEffect(() => {
    if (isOpen) {
      if (role) {
        setFormData({
          role_name: role.role_name || '',
          description: role.description || '',
        });
        
        // Handle permissions (could be array from Laravel or string from legacy)
        let perms: string[] = [];
        if (role.permissions) {
          if (Array.isArray(role.permissions)) {
            perms = role.permissions;
          } else if (typeof role.permissions === 'string') {
            try {
              const parsed = JSON.parse(role.permissions);
              perms = Array.isArray(parsed) ? parsed : [];
            } catch (e) {
              perms = role.permissions.split(',').map(p => p.trim()).filter(Boolean);
            }
          }
        }
        setSelectedPermissions(perms);
      } else {
        setFormData({
          role_name: '',
          description: '',
        });
        setSelectedPermissions([]);
      }
      setErrors({});
    }
  }, [isOpen, role]);

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
    if (errors[name]) {
      setErrors(prev => ({ ...prev, [name]: '' }));
    }
  };

  const handlePermissionChange = (pageId: string, checked: boolean) => {
    setSelectedPermissions(prev => {
      let newPermissions = [...prev];
      
      if (checked) {
        if (!newPermissions.includes(pageId)) {
          newPermissions.push(pageId);
        }
        
        // If it's a sub-permission, auto-check the parent
        if (pageId.includes('.')) {
          const parentId = pageId.split('.')[0];
          if (!newPermissions.includes(parentId)) {
            newPermissions.push(parentId);
          }
        }

        // Handle mutual exclusivity for job-order.tech-edit and job-order.admin-edit
        if (pageId === 'job-order.tech-edit') {
          newPermissions = newPermissions.filter(id => id !== 'job-order.admin-edit');
        } else if (pageId === 'job-order.admin-edit') {
          newPermissions = newPermissions.filter(id => id !== 'job-order.tech-edit');
        }

        // Handle mutual exclusivity for service-order.tech-edit and service-order.admin-edit
        if (pageId === 'service-order.tech-edit') {
          newPermissions = newPermissions.filter(id => id !== 'service-order.admin-edit');
        } else if (pageId === 'service-order.admin-edit') {
          newPermissions = newPermissions.filter(id => id !== 'service-order.tech-edit');
        }
      } else {
        newPermissions = newPermissions.filter(id => id !== pageId);
        
        // If it's a parent, auto-uncheck all sub-permissions
        if (!pageId.includes('.')) {
          newPermissions = newPermissions.filter(id => !id.startsWith(pageId + '.'));
        }
      }
      
      return newPermissions;
    });
  };

  const validate = () => {
    const newErrors: Record<string, string> = {};
    if (!formData.role_name.trim()) newErrors.role_name = 'Required';

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSave = async () => {
    if (!validate()) return;
    setLoading(true);

    try {
      const payload = {
        role_name: formData.role_name,
        description: formData.description,
        permissions: selectedPermissions
      };

      const authData = localStorage.getItem('authData');
      const currentUser = authData ? JSON.parse(authData) : null;
      if (currentUser?.organization_id) {
        (payload as any).organization_id = currentUser.organization_id;
      }

      let response: ApiResponse<Role>;
      if (isEditMode && role) {
        response = await roleService.updateRole(role.id, payload as any);
      } else {
        response = await roleService.createRole(payload as any);
      }

      if (response.success && response.data) {
        onSave(response.data);
        onClose();
      } else {
        setErrors({ general: response.message || 'Something went wrong' });
      }
    } catch (error: any) {
      setErrors({ general: error.message || 'An unexpected error occurred' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <ModalUITemplate
      isOpen={isOpen}
      onClose={onClose}
      title={isEditMode ? 'Edit Role' : 'Add New Role'}
      loading={loading}
      maxWidth="max-w-4xl"
      primaryAction={{
        label: isEditMode ? 'Update' : 'Save',
        onClick: handleSave,
        disabled: loading
      }}
    >
      <RoleForm
        formData={formData}
        handleInputChange={handleInputChange}
        handlePermissionChange={handlePermissionChange}
        errors={errors}
        selectedPermissions={selectedPermissions}
      />
    </ModalUITemplate>
  );
};

export default RoleModal;
