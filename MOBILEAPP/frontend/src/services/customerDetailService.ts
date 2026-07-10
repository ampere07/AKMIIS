import apiClient from '../config/api';

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
  proof_of_billing_url?: string;
  proofOfBillingUrl?: string;
  government_valid_id_url?: string;
  governmentValidIdUrl?: string;
  second_government_valid_id_url?: string;
  secondGovernmentValidIdUrl?: string;
  document_attachment_url?: string;
  documentAttachmentUrl?: string;
  other_isp_bill_url?: string;
  otherIspBillUrl?: string;
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
  };

  technicalDetails?: {
    id: number;
    username?: string;
    usernameStatus?: string;
    connectionType?: string;
    routerModel?: string;
    routerModemSn?: string;
    ipAddress?: string;
    lcp?: string;
    nap?: string;
    port?: string;
    vlan?: string;
    lcpnap?: string;
    usageTypeId?: number;
    usageType?: string;
  };

  createdAt?: string;
  updatedAt?: string;
  onlineSessionStatus?: string;
  onlineStatusData?: any;
}

interface CustomerDetailApiResponse {
  success: boolean;
  data?: CustomerDetailData;
  message?: string;
}

export const getCustomerDetail = async (accountNo: string): Promise<CustomerDetailData | null> => {
  try {
    console.log('Fetching customer detail for account:', accountNo);
    const response = await apiClient.get<CustomerDetailApiResponse>(`/customer-detail/${accountNo}`);

    console.log('Customer detail API response:', response.data);

    if (response.data?.success && response.data?.data) {
      const data = response.data.data;
      console.log('House front picture URL from API:', data.houseFrontPictureUrl);
      return data;
    }

    return null;
  } catch (error) {
    console.error('Error fetching customer detail:', error);
    return null;
  }
};
