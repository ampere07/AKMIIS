export interface ApplicationImageField {
  key: string;
  label: string;
  upload: string;
  column: string;
}

export const APPLICATION_IMAGE_FIELDS: ApplicationImageField[] = [
  { key: 'proofOfBilling', label: 'Proof of Billing', upload: 'proof_of_billing', column: 'proof_of_billing_url' },
  { key: 'governmentValidId', label: 'Government Valid ID', upload: 'government_valid_id', column: 'government_valid_id_url' },
  { key: 'secondaryGovernmentValidId', label: 'Secondary Government Valid ID', upload: 'secondary_government_valid_id', column: 'second_government_valid_id_url' },
  { key: 'houseFrontPicture', label: 'House Front Picture', upload: 'house_front_image', column: 'house_front_picture_url' },
  { key: 'promoImage', label: 'Promo Image', upload: 'promo_image', column: 'promo_url' },
  { key: 'nearestLandmark1', label: 'Nearest Landmark 1', upload: 'nearest_landmark1', column: 'nearest_landmark1_url' },
  { key: 'nearestLandmark2', label: 'Nearest Landmark 2', upload: 'nearest_landmark2', column: 'nearest_landmark2_url' },
  { key: 'documentAttachment', label: 'Document Attachment', upload: 'document_attachment', column: 'document_attachment_url' },
  { key: 'otherIspBill', label: 'Other ISP Bill', upload: 'other_isp_bill', column: 'other_isp_bill_url' },
];

export const toImagePreviewUrl = (url: string | null | undefined): string | null => {
  if (!url) return null;
  if (url.startsWith('data:') || url.startsWith('file:')) return url;
  const fileIdMatch = url.match(/\/d\/([a-zA-Z0-9_-]+)/);
  if (fileIdMatch && fileIdMatch[1]) {
    return `https://lh3.googleusercontent.com/d/${fileIdMatch[1]}=s1000`;
  }
  const idMatch = url.match(/[?&]id=([a-zA-Z0-9_-]+)/);
  if (idMatch && idMatch[1]) {
    return `https://lh3.googleusercontent.com/d/${idMatch[1]}=s1000`;
  }
  return url;
};

export const mapLinkFor = (longLat: string | null | undefined): string | null => {
  const parts = (longLat || '').split(',').map(part => part.trim());
  if (parts.length !== 2 || parts.some(part => part === '' || isNaN(Number(part)))) return null;
  return `https://www.google.com/maps?q=${parts[0]},${parts[1]}`;
};
