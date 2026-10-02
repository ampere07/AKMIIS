import React, { useState, useEffect } from 'react';
import { CheckCircle } from 'lucide-react';
import ModalUITemplate from './ui-modal/ModalUITemplate';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { uploadApplicationImages } from '../services/applicationService';
import LoadingModalGlobal from '../components/common/LoadingModalGlobal';
import ImageUploadField from '../components/common/ImageUploadField';
import { useApplicationImageUploads } from '../hooks/useApplicationImageUploads';
import { APPLICATION_IMAGE_FIELDS } from '../utils/applicationImages';

interface ApplicationAttachmentModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSave: (formData: any) => void;
    applicationData?: any;
    loading?: boolean;
}

const ApplicationAttachmentModal: React.FC<ApplicationAttachmentModalProps> = ({
    isOpen,
    onClose,
    onSave,
    applicationData,
    loading = false
}) => {
    const [isDarkMode, setIsDarkMode] = useState<boolean>(true);
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
    const { previews, handleFileChange, clearFile, pendingUpload } = useApplicationImageUploads(isOpen, applicationData);

    useEffect(() => {
        const checkDarkMode = () => {
            const theme = localStorage.getItem('theme');
            setIsDarkMode(theme === 'dark' || theme === null);
        };
        checkDarkMode();
        const observer = new MutationObserver(checkDarkMode);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        const fetchPalette = async () => {
            try {
                const active = await settingsColorPaletteService.getActive();
                setColorPalette(active);
            } catch (err) {
                console.error('Failed to fetch palette:', err);
            }
        };
        fetchPalette();
    }, []);

    const [loadingInfo, setLoadingInfo] = useState({
        isOpen: false,
        type: 'loading' as 'loading' | 'success' | 'error',
        title: '',
        message: '',
        percentage: 0
    });

    const handleSave = async () => {
        if (!applicationData?.id) {
            console.error('Application ID is missing');
            return;
        }

        const upload = pendingUpload();
        if (!upload) {
            onSave({});
            return;
        }

        setLoadingInfo({
            isOpen: true,
            type: 'loading',
            title: 'Saving Attachments',
            message: 'Uploading images to Google Drive...',
            percentage: 10
        });

        const progressInterval = setInterval(() => {
            setLoadingInfo(prev => ({
                ...prev,
                percentage: prev.percentage >= 90 ? 90 : prev.percentage + 5
            }));
        }, 500);

        try {
            const uploadResponse = await uploadApplicationImages(applicationData.id, upload);
            clearInterval(progressInterval);
            setLoadingInfo(uploadResponse?.success
                ? { isOpen: true, type: 'success', title: 'Success', message: 'Attachments saved successfully!', percentage: 100 }
                : { isOpen: true, type: 'error', title: 'Upload Failed', message: uploadResponse?.message || 'Failed to upload images.', percentage: 0 });
        } catch (error: any) {
            clearInterval(progressInterval);
            setLoadingInfo({
                isOpen: true,
                type: 'error',
                title: 'Error',
                message: error.response?.data?.message || error.message || 'An unexpected error occurred.',
                percentage: 0
            });
        }
    };

    return (
        <>
            <ModalUITemplate
                isOpen={isOpen}
                onClose={onClose}
                title="Application Attachments"
                maxWidth="max-w-xl"
                primaryAction={{
                    label: 'Save Attachments',
                    onClick: handleSave,
                    disabled: loading
                }}
                loading={loading}
            >
                <div className="space-y-6">
                    {APPLICATION_IMAGE_FIELDS.map(field => (
                        <ImageUploadField key={field.key} label={field.label} field={field.key} preview={previews[field.key]} isDarkMode={isDarkMode} handleFileChange={handleFileChange} clearFile={clearFile} />
                    ))}
                </div>

                <div className={`p-4 rounded-lg mt-6 flex items-start gap-3 ${isDarkMode ? 'bg-blue-900/20 text-blue-300' : 'bg-blue-50 text-blue-700'}`}>
                    <CheckCircle size={18} className="mt-0.5 flex-shrink-0" />
                    <p className="text-xs leading-relaxed">
                        Ensure all documents are clear and readable. High-quality images help speed up the application process.
                    </p>
                </div>
            </ModalUITemplate>

            <LoadingModalGlobal
                isOpen={loadingInfo.isOpen}
                type={loadingInfo.type}
                title={loadingInfo.title}
                message={loadingInfo.message}
                loadingPercentage={loadingInfo.percentage}
                isDarkMode={isDarkMode}
                colorPalette={colorPalette}
                onConfirm={() => {
                    if (loadingInfo.type === 'success') {
                        onSave({});
                    }
                    setLoadingInfo(prev => ({ ...prev, isOpen: false }));
                }}
            />
        </>
    );
};

export default ApplicationAttachmentModal;
