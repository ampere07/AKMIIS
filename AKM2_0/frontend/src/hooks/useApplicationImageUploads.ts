import React, { useEffect, useState } from 'react';
import { getActiveImageSize, resizeImage, ImageSizeSetting } from '../services/imageSettingsService';
import { APPLICATION_IMAGE_FIELDS, toImagePreviewUrl } from '../utils/applicationImages';

type ByField<T> = Record<string, T>;

const emptyByField = <T,>(value: T): ByField<T> =>
  APPLICATION_IMAGE_FIELDS.reduce((acc, field) => ({ ...acc, [field.key]: value }), {} as ByField<T>);

const previewsFrom = (applicationData: any): ByField<string | null> =>
  APPLICATION_IMAGE_FIELDS.reduce((acc, field) => ({ ...acc, [field.key]: toImagePreviewUrl(applicationData?.[field.column]) }), {} as ByField<string | null>);

export const useApplicationImageUploads = (isOpen: boolean, applicationData: any) => {
  const [activeImageSize, setActiveImageSize] = useState<ImageSizeSetting | null>(null);
  const [files, setFiles] = useState<ByField<File | null>>(() => emptyByField<File | null>(null));
  const [previews, setPreviews] = useState<ByField<string | null>>(() => emptyByField<string | null>(null));

  useEffect(() => {
    getActiveImageSize().then(setActiveImageSize);
  }, []);

  useEffect(() => {
    setFiles(emptyByField<File | null>(null));
    setPreviews(isOpen && applicationData ? previewsFrom(applicationData) : emptyByField<string | null>(null));
  }, [isOpen, applicationData]);

  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>, field: string) => {
    if (!e.target.files || !e.target.files[0]) return;
    let file = e.target.files[0];
    if (activeImageSize && activeImageSize.status === 'active') {
      try {
        file = await resizeImage(file, activeImageSize.image_size_value);
      } catch (error) {
        console.error('Image resizing failed:', error);
      }
    }
    setFiles(prev => ({ ...prev, [field]: file }));
    const reader = new FileReader();
    reader.onloadend = () => {
      setPreviews(prev => ({ ...prev, [field]: reader.result as string }));
    };
    reader.readAsDataURL(file);
  };

  const clearFile = (field: string) => {
    setFiles(prev => ({ ...prev, [field]: null }));
    setPreviews(prev => ({ ...prev, [field]: null }));
  };

  const pendingUpload = (): FormData | null => {
    const chosen = APPLICATION_IMAGE_FIELDS.filter(field => files[field.key]);
    if (chosen.length === 0) return null;
    const name = `${applicationData?.first_name || ''} ${applicationData?.last_name || ''}`.trim();
    const upload = new FormData();
    upload.append('folder_name', `(application) ${name}`.trim());
    chosen.forEach(field => {
      const file = files[field.key] as File;
      upload.append(field.upload, file, file.name);
    });
    return upload;
  };

  return { previews, handleFileChange, clearFile, pendingUpload };
};
