import apiClient from '../config/api';
import { BillingDetailRecord } from '../types/billing';
import { accountStatusFrom, sessionStatusFrom } from '../utils/onlineStatus';

export interface CustomerDetailData {
  id: number;
  firstName: string;
  middleInitial?: string;
  lastName: string;
  fullName: string;
  emailAddress?: string;
  contactNumberPrimary: string;
  contactNumberSecondary?: string;
  address: string;
  barangay?: string;
  city?: string;
  region?: string;
  addressCoordinates?: string;
  housingStatus?: string;
  referredBy?: string;
  desiredPlan?: string;
  houseFrontPictureUrl?: string;
  proofOfBillingUrl?: string;
  governmentValidIdUrl?: string;
  secondGovernmentValidIdUrl?: string;
  documentAttachmentUrl?: string;
  otherIspBillUrl?: string;
  accountNoCustomer?: string;
  updatedBy?: string;
  groupId?: number;
  groupName?: string;

  billingAccount?: {
    id: number;
    accountNo: string;
    dateInstalled?: string;
    billingDay: number;
    billingStatusId: number;
    billingStatusName?: string;
    accountBalance: number;
    balanceUpdateDate?: string;
    createdAt?: string;
    createdBy?: string;
    updatedAt?: string;
    updatedBy?: string;
    vip_expiration?: string;
    vip_remarks?: string;
    vat_type?: string;
    vat_enabled?: boolean | null;
    withholding_enabled?: boolean | null;
    withholding_percentage?: number | string | null;
  };

  technicalDetails?: {
    id: number;
    username?: string;
    usernameStatus?: string;
    connectionType?: string;
    routerModel?: string;
    routerModemSn?: string;
    ipAddress?: string;
    // Falls back server-side to the job order that provisioned the line, so
    // this is populated for subscribers onboarded before the column existed.
    pppoePassword?: string;
    lcp?: string;
    nap?: string;
    port?: string;
    vlan?: string;
    lcpnap?: string;
    usageTypeId?: number;
    usageType?: string;
    createdAt?: string;
    createdBy?: string;
    updatedAt?: string;
    updatedBy?: string;
  };

  createdAt?: string;
  updatedAt?: string;
  onlineSessionStatus?: string;
  session_group?: string;
  session_ip?: string;
  onlineStatusData?: any;
}

interface CustomerDetailApiResponse {
  success: boolean;
  data?: CustomerDetailData;
  message?: string;
}

export const getCustomerDetail = async (accountNo: string): Promise<CustomerDetailData | null> => {
  try {
    const response = await apiClient.get<CustomerDetailApiResponse>(`/customer-detail/${accountNo}`);

    if (response.data?.success && response.data?.data) {
      const data = response.data.data;
      return data;
    }

    return null;
  } catch (error) {
    return null;
  }
};

/**
 * The single mapping from a customer-detail payload to the record CustomerDetails renders.
 *
 * This lived as sixteen near-copies - one in every screen that can open the panel through
 * the arrow beside Account No. - and they drifted. The stale copies read the subscriber's
 * IP only from `technicalDetails.ipAddress`, which is null for every dynamic-PPPoE line,
 * and omitted `session_group` altogether: the panel opened from Service Orders,
 * Transactions, Invoices or SOA showed a blank IP and no session group, while the same
 * panel opened from Billing > Customer showed both. Exported from here so there is one
 * mapping to fix rather than sixteen to keep in step.
 *
 * `session_ip` (from `online_status.ip_address`) is the live RADIUS address and wins;
 * `technicalDetails.ipAddress` is the statically provisioned one and is the fallback.
 */
export const convertCustomerDataToBillingDetail = (customerData: CustomerDetailData): BillingDetailRecord => {
  return {
    id: customerData.billingAccount?.accountNo || '',
    applicationId: customerData.billingAccount?.accountNo || '',
    customerName: customerData.fullName,
    firstName: customerData.firstName,
    middleInitial: customerData.middleInitial,
    lastName: customerData.lastName,
    address: customerData.address,
    // Billing status and session status are separate facts and are resolved separately -
    // several of the old copies derived both from `billingStatusId`, and three of them
    // compared it against 2 (Blacklisted) rather than 1 (Active).
    status: accountStatusFrom(customerData),
    balance: customerData.billingAccount?.accountBalance || 0,
    onlineStatus: sessionStatusFrom(customerData),
    cityId: null,
    regionId: null,
    timestamp: customerData.updatedAt || '',
    billingStatus: customerData.billingAccount?.billingStatusName || (customerData.billingAccount?.billingStatusId ? `Status ${customerData.billingAccount.billingStatusId}` : ''),
    billing_status_id: customerData.billingAccount?.billingStatusId,
    dateInstalled: customerData.billingAccount?.dateInstalled || '',
    contactNumber: customerData.contactNumberPrimary,
    secondContactNumber: customerData.contactNumberSecondary || '',
    emailAddress: customerData.emailAddress || '',
    plan: customerData.desiredPlan || '',
    username: customerData.technicalDetails?.username || '',
    // Sourced from the account's job order by CustomerDetailController when technical_details
    // has none - PPPoE credentials were not backfilled onto that table.
    pppoePassword: (customerData.technicalDetails as any)?.pppoePassword || (customerData as any).pppoePassword || '',
    connectionType: customerData.technicalDetails?.connectionType || '',
    routerModel: customerData.technicalDetails?.routerModel || '',
    routerModemSN: customerData.technicalDetails?.routerModemSn || '',
    lcpnap: customerData.technicalDetails?.lcpnap || '',
    port: customerData.technicalDetails?.port || '',
    vlan: customerData.technicalDetails?.vlan || '',
    billingDay: customerData.billingAccount?.billingDay || 0,
    totalPaid: (customerData as any).totalPaid || (customerData as any).total_paid || 0,
    provider: customerData.groupName || '',
    lcp: customerData.technicalDetails?.lcp || '',
    nap: customerData.technicalDetails?.nap || '',
    modifiedBy: (customerData.billingAccount as any)?.updatedBy || '',
    modifiedDate: customerData.updatedAt || '',
    barangay: customerData.barangay || '',
    city: customerData.city || '',
    region: customerData.region || '',

    usageType: customerData.technicalDetails?.usageType || '',
    referredBy: customerData.referredBy || '',
    referralContactNo: '',
    groupName: customerData.groupName || '',
    mikrotikId: '',
    houseFrontPicture: customerData.houseFrontPictureUrl || '',
    accountBalance: customerData.billingAccount?.accountBalance || 0,
    housingStatus: customerData.housingStatus || '',
    addressCoordinates: customerData.addressCoordinates || '',
    lcpnapport: `${customerData.technicalDetails?.lcpnap || ''} ${customerData.technicalDetails?.port || ''}`.trim(),
    balanceUpdateDate: customerData.billingAccount?.balanceUpdateDate || '',
    billingAccountCreatedBy: customerData.billingAccount?.createdBy || '',
    billingAccountCreatedAt: customerData.billingAccount?.createdAt || '',
    billingAccountUpdatedBy: customerData.billingAccount?.updatedBy || '',
    billingAccountUpdatedAt: customerData.billingAccount?.updatedAt || '',
    proofOfBillingUrl: customerData.proofOfBillingUrl || '',
    governmentValidIdUrl: customerData.governmentValidIdUrl || '',
    secondGovernmentValidIdUrl: customerData.secondGovernmentValidIdUrl || '',
    documentAttachmentUrl: customerData.documentAttachmentUrl || '',
    otherIspBillUrl: customerData.otherIspBillUrl || '',
    houseFrontPictureUrl: customerData.houseFrontPictureUrl || '',
    accountNoCustomer: customerData.accountNoCustomer || '',
    customerUpdatedBy: customerData.updatedBy || '',
    customerUpdatedAt: customerData.updatedAt || '',
    techUpdatedBy: customerData.technicalDetails?.updatedBy || '',
    techUpdatedAt: customerData.technicalDetails?.updatedAt || '',
    sessionGroup: (customerData as any).session_group || '',
    // The live RADIUS address first, the provisioned one only as a fallback. Both keys are
    // populated because the panel and its related-data overlay read different casings.
    sessionIp: (customerData as any).session_ip || customerData.technicalDetails?.ipAddress || '',
    sessionIP: (customerData as any).session_ip || customerData.technicalDetails?.ipAddress || '',
    vip_expiration: customerData.billingAccount?.vip_expiration || '',
    vip_remarks: customerData.billingAccount?.vip_remarks || '',
    vatType: customerData.billingAccount?.vat_type || '',
    // Left as null when absent rather than coerced to false, so the UI can fall back to the
    // legacy vatType text for accounts predating the boolean column.
    vatEnabled: customerData.billingAccount?.vat_enabled ?? null,
    withholdingEnabled: customerData.billingAccount?.withholding_enabled ?? null,
    withholdingPercentage: customerData.billingAccount?.withholding_percentage != null
      ? Number(customerData.billingAccount.withholding_percentage)
      : null,
  };
};
