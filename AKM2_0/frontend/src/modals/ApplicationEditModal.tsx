import React, { useState, useEffect, useMemo } from 'react';
import { X, Layers, MapPin, Tag } from 'lucide-react';
import { updateApplication, uploadApplicationImages } from '../services/applicationService';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import LocationPicker from '../components/LocationPicker';
import SearchableField from '../components/common/SearchableField';
import { planService, Plan } from '../services/planService';
import apiClient from '../config/api';
import { useAddressOptions } from '../hooks/useAddressOptions';
import ImageUploadField from '../components/common/ImageUploadField';
import { useApplicationImageUploads } from '../hooks/useApplicationImageUploads';
import { APPLICATION_IMAGE_FIELDS } from '../utils/applicationImages';

interface ApplicationEditModalProps {
  isOpen: boolean;
  application: any;
  onClose: () => void;
  onSaved: () => void;
}

const TEXT_KEYS = [
  'first_name', 'middle_initial', 'last_name', 'email_address', 'mobile_number', 'secondary_mobile_number',
  'installation_address', 'landmark', 'region', 'city', 'barangay', 'location', 'desired_plan', 'promo',
  'long_lat'
] as const;

type FormKey = typeof TEXT_KEYS[number];
type FormState = Record<FormKey, string>;

const formFrom = (application: any): FormState =>
  TEXT_KEYS.reduce((form, key) => ({ ...form, [key]: application?.[key] ? String(application[key]) : '' }), {} as FormState);

const ApplicationEditModal: React.FC<ApplicationEditModalProps> = ({ isOpen, application, onClose, onSaved }) => {
  const [formData, setFormData] = useState<FormState>(() => formFrom(application));
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isDarkMode, setIsDarkMode] = useState(localStorage.getItem('theme') === 'dark');
  const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [promos, setPromos] = useState<{ id: number; name: string }[]>([]);
  const address = useAddressOptions(isOpen, application?.region || '', application?.city || '');
  const planOptions = useMemo(
    () => plans.map(p => ({ id: p.id, name: `${p.name} - P${Number(p.price || 0).toFixed(2)}` })),
    [plans]
  );
  const { previews, handleFileChange, clearFile, pendingUpload } = useApplicationImageUploads(isOpen, application);

  useEffect(() => {
    const observer = new MutationObserver(() => {
      setIsDarkMode(localStorage.getItem('theme') === 'dark');
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    settingsColorPaletteService.getActive().then(setColorPalette).catch(err => console.error('Failed to fetch color palette:', err));
    planService.getAllPlans().then(setPlans).catch(err => console.error('Failed to fetch plans:', err));
    apiClient.get<{ success: boolean; data?: { id: number; name: string }[] }>('/promos')
      .then(response => setPromos(response.data.data || []))
      .catch(err => console.error('Failed to fetch promos:', err));
  }, []);

  useEffect(() => {
    if (isOpen) {
      setFormData(formFrom(application));
      setErrors({});
    }
  }, [isOpen, application]);

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};
    if (!formData.first_name.trim()) newErrors.first_name = 'First name is required';
    if (!formData.last_name.trim()) newErrors.last_name = 'Last name is required';
    if (!formData.email_address.trim()) {
      newErrors.email_address = 'Email address is required';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email_address)) {
      newErrors.email_address = 'Invalid email address format';
    }
    if (!formData.mobile_number.trim()) {
      newErrors.mobile_number = 'Mobile number is required';
    } else if (!/^[0-9]{10,11}$/.test(formData.mobile_number)) {
      newErrors.mobile_number = 'Mobile number must be 10-11 digits';
    }
    if (formData.secondary_mobile_number && !/^[0-9]{10,11}$/.test(formData.secondary_mobile_number)) {
      newErrors.secondary_mobile_number = 'Secondary mobile number must be 10-11 digits';
    }
    if (!formData.installation_address.trim()) newErrors.installation_address = 'Installation address is required';
    if (!formData.desired_plan.trim()) newErrors.desired_plan = 'Desired plan is required';
    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async () => {
    if (!validateForm()) return;
    setLoading(true);
    try {
      const payload = TEXT_KEYS.reduce((body, key) => ({ ...body, [key]: formData[key].trim() }), {});
      await updateApplication(application.id, payload);
      const upload = pendingUpload();
      if (upload) await uploadApplicationImages(application.id, upload);
      onSaved();
      onClose();
    } catch (error: any) {
      const serverErrors = error?.response?.data?.errors;
      if (serverErrors) {
        alert('Validation errors:\n' + Object.values(serverErrors).flat().join('\n'));
      } else {
        alert(error?.response?.data?.message || `Failed to update application: ${error instanceof Error ? error.message : 'Unknown error'}`);
      }
    } finally {
      setLoading(false);
    }
  };

  if (!isOpen) return null;

  const pickerIcon = (Icon: typeof MapPin) => <Icon size={16} className={`mr-2 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`} />;
  const labelClass = `block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`;
  const inputClass = (key: string) => `w-full px-3 py-2 border rounded focus:outline-none focus:border-orange-500 ${errors[key] ? 'border-red-500' : isDarkMode ? 'border-gray-700' : 'border-gray-300'
    } ${isDarkMode ? 'bg-gray-800 text-white' : 'bg-white text-gray-900'} disabled:opacity-60 disabled:cursor-not-allowed`;

  const field = (key: FormKey, label: string, options: { required?: boolean; placeholder?: string; type?: string; digits?: boolean; maxLength?: number; multiline?: boolean } = {}) => (
    <div>
      <label htmlFor={`application-edit-${key}`} className={labelClass}>
        {label}{options.required && <span className="text-red-500">*</span>}
      </label>
      {options.multiline ? (
        <textarea
          id={`application-edit-${key}`}
          value={formData[key]}
          onChange={(e) => setFormData({ ...formData, [key]: e.target.value })}
          rows={3}
          className={`${inputClass(key)} resize-none`}
          placeholder={options.placeholder}
        />
      ) : (
        <input
          id={`application-edit-${key}`}
          type={options.type || 'text'}
          value={formData[key]}
          maxLength={options.maxLength}
          onChange={(e) => setFormData({ ...formData, [key]: options.digits ? e.target.value.replace(/\D/g, '') : e.target.value })}
          className={inputClass(key)}
          placeholder={options.placeholder}
        />
      )}
      {errors[key] && <p className="text-red-500 text-xs mt-1">{errors[key]}</p>}
    </div>
  );

  const readOnly = (id: string, label: string, value: string) => (
    <div>
      <label htmlFor={`application-edit-${id}`} className={labelClass}>{label}</label>
      <input id={`application-edit-${id}`} type="text" value={value} disabled className={inputClass(id)} />
    </div>
  );

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-end z-50" onClick={onClose}>
      <div
        role="dialog"
        aria-label="Edit Application"
        className={`h-full w-full md:w-full md:max-w-2xl shadow-2xl transform transition-transform duration-300 ease-in-out overflow-hidden flex flex-col ${isDarkMode ? 'bg-gray-900' : 'bg-white'
          }`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className={`px-6 py-4 flex items-center justify-between border-b ${isDarkMode
          ? 'bg-gray-800 border-gray-700'
          : 'bg-gray-100 border-gray-300'
          }`}>
          <h2 className={`text-xl font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>Edit Application</h2>
          <div className="flex items-center space-x-3">
            <button
              onClick={onClose}
              className={`px-4 py-2 rounded text-sm ${isDarkMode
                ? 'bg-gray-700 hover:bg-gray-600 text-white'
                : 'bg-gray-200 hover:bg-gray-300 text-gray-900'
                }`}
            >
              Cancel
            </button>
            <button
              onClick={handleSubmit}
              disabled={loading}
              className="px-4 py-2 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded text-sm flex items-center"
              style={{ backgroundColor: colorPalette?.primary || '#7c3aed' }}
              onMouseEnter={(e) => {
                if (colorPalette?.accent && !loading) {
                  e.currentTarget.style.backgroundColor = colorPalette.accent;
                }
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = colorPalette?.primary || '#7c3aed';
              }}
            >
              {loading ? (
                <>
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                  Saving...
                </>
              ) : (
                'Save'
              )}
            </button>
            <button
              onClick={onClose}
              aria-label="Close"
              className={isDarkMode ? 'text-gray-400 hover:text-white transition-colors' : 'text-gray-600 hover:text-gray-900 transition-colors'}
            >
              <X size={24} />
            </button>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-6 space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="md:col-span-2">{field('first_name', 'First Name', { required: true, placeholder: 'Enter first name' })}</div>
            {field('middle_initial', 'M.I.', { placeholder: 'M.I.', maxLength: 1 })}
          </div>
          {field('last_name', 'Last Name', { required: true, placeholder: 'Enter last name' })}
          {field('email_address', 'Email Address', { required: true, type: 'email', placeholder: 'example@email.com' })}
          {field('mobile_number', 'Mobile Number', { required: true, type: 'tel', digits: true, maxLength: 11, placeholder: '09123456789' })}
          {field('secondary_mobile_number', 'Second Mobile Number', { type: 'tel', digits: true, maxLength: 11, placeholder: '09123456789' })}
          {field('installation_address', 'Installation Address', { required: true, multiline: true, placeholder: 'Enter full installation address' })}
          {field('landmark', 'Landmark', { placeholder: 'Nearby landmark' })}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <SearchableField
              label="Region"
              value={formData.region}
              onSelect={(value, option) => {
                setFormData(prev => ({ ...prev, region: value, city: '', barangay: '' }));
                address.selectRegion(option);
              }}
              options={address.regions}
              optionLabelKey="name"
              isDarkMode={isDarkMode}
              colorPalette={colorPalette}
              placeholder="Select region"
              icon={pickerIcon(MapPin)}
            />
            <SearchableField
              label="City/Municipality"
              value={formData.city}
              onSelect={(value, option) => {
                setFormData(prev => ({ ...prev, city: value, barangay: '' }));
                address.selectCity(option);
              }}
              options={address.cities}
              optionLabelKey="name"
              isDarkMode={isDarkMode}
              colorPalette={colorPalette}
              placeholder={formData.region ? 'Select city/municipality' : 'Select a region first'}
              disabled={!formData.region}
              icon={pickerIcon(MapPin)}
            />
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <SearchableField
              label="Barangay"
              value={formData.barangay}
              onSelect={(value) => setFormData(prev => ({ ...prev, barangay: value }))}
              options={address.barangays}
              optionLabelKey="name"
              isDarkMode={isDarkMode}
              colorPalette={colorPalette}
              placeholder={formData.city ? 'Select barangay' : 'Select a city first'}
              disabled={!formData.city}
              icon={pickerIcon(MapPin)}
            />
            {field('location', 'Location', { placeholder: 'Specific location' })}
          </div>
          <LocationPicker
            value={formData.long_lat}
            onChange={(coordinates) => setFormData(prev => ({ ...prev, long_lat: coordinates }))}
            isDarkMode={isDarkMode}
            label="Map Pin"
            showCurrentLocation={false}
          />
          <SearchableField
            label="Desired Plan"
            required
            value={formData.desired_plan}
            onSelect={(value) => setFormData(prev => ({ ...prev, desired_plan: value }))}
            options={planOptions}
            optionLabelKey="name"
            isDarkMode={isDarkMode}
            colorPalette={colorPalette}
            error={errors.desired_plan}
            placeholder="Select plan"
            icon={pickerIcon(Layers)}
          />
          <SearchableField
            label="Promo"
            value={formData.promo}
            onSelect={(value) => setFormData(prev => ({ ...prev, promo: value }))}
            options={promos}
            optionLabelKey="name"
            isDarkMode={isDarkMode}
            colorPalette={colorPalette}
            placeholder="Select promo"
            icon={pickerIcon(Tag)}
          />
          {readOnly('referred_by', 'Referred By', application?.referred_by || 'None')}
          {readOnly('terms_agreed', 'Terms and Conditions', application?.terms_agreed ? 'Agreed' : 'Not agreed')}
          <div className="space-y-6">
            {APPLICATION_IMAGE_FIELDS.map(image => (
              <ImageUploadField key={image.key} label={image.label} field={image.key} preview={previews[image.key]} isDarkMode={isDarkMode} handleFileChange={handleFileChange} clearFile={clearFile} />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};

export default ApplicationEditModal;
