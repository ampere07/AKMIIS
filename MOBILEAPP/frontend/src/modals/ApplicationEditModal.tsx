import React, { useState, useEffect, useMemo } from 'react';
import {
  View,
  Text,
  Modal,
  TouchableOpacity,
  ScrollView,
  TextInput,
  ActivityIndicator,
  Alert,
} from 'react-native';
import { X } from 'lucide-react-native';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { updateApplication, uploadApplicationImages } from '../services/applicationService';
import LocationPicker from '../components/LocationPicker';
import ImagePreview from '../components/ImagePreview';
import { APPLICATION_IMAGE_FIELDS, toImagePreviewUrl } from '../utils/applicationImages';
import { SearchablePicker, SearchablePickerTrigger } from '../components/SearchablePicker';
import { planService, Plan } from '../services/planService';
import apiClient from '../config/api';
import { useAddressOptions } from '../hooks/useAddressOptions';

type PickerKey = 'region' | 'city' | 'barangay' | 'desired_plan' | 'promo';

interface PickerOption {
  id: string;
  label: string;
  value: string;
}

const optionsFrom = (list: { id: number; name: string }[]): PickerOption[] =>
  list.map((item) => ({ id: String(item.id), label: item.name, value: item.name }));

interface ApplicationEditModalProps {
  isOpen: boolean;
  application: any;
  onClose: () => void;
  onSaved: () => void;
}

interface PickedImage {
  uri: string;
  name: string;
  type: string;
}

const TEXT_KEYS = [
  'first_name', 'middle_initial', 'last_name', 'email_address', 'mobile_number', 'secondary_mobile_number',
  'installation_address', 'landmark', 'region', 'city', 'barangay', 'location', 'desired_plan', 'promo',
  'long_lat',
] as const;

type FormKey = typeof TEXT_KEYS[number];
type FormState = Record<FormKey, string>;

const formFrom = (application: any): FormState =>
  TEXT_KEYS.reduce((form, key) => ({ ...form, [key]: application?.[key] ? String(application[key]) : '' }), {} as FormState);

const ApplicationEditModal: React.FC<ApplicationEditModalProps> = ({ isOpen, application, onClose, onSaved }) => {
  const [formData, setFormData] = useState<FormState>(() => formFrom(application));
  const [images, setImages] = useState<Record<string, PickedImage>>({});
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [promos, setPromos] = useState<{ id: number; name: string }[]>([]);
  const [activePicker, setActivePicker] = useState<PickerKey | null>(null);
  const [pickerSearch, setPickerSearch] = useState('');
  const address = useAddressOptions(isOpen, application?.region || '', application?.city || '');
  const pickerOptions: Record<PickerKey, PickerOption[]> = useMemo(() => ({
    region: optionsFrom(address.regions),
    city: optionsFrom(address.cities),
    barangay: optionsFrom(address.barangays),
    desired_plan: plans.map((p) => ({ id: String(p.id), label: p.description || p.name, value: `${p.name} - P${Number(p.price || 0).toFixed(2)}` })),
    promo: optionsFrom(promos),
  }), [address.regions, address.cities, address.barangays, plans, promos]);
  const pickerTitles: Record<PickerKey, string> = { region: 'Select Region', city: 'Select City/Municipality', barangay: 'Select Barangay', desired_plan: 'Select Plan', promo: 'Select Promo' };

  const primary = colorPalette?.primary || '#7c3aed';

  useEffect(() => {
    settingsColorPaletteService.getActive().then(setColorPalette).catch(() => {});
    planService.getAllPlans().then(setPlans).catch(() => {});
    apiClient.get<{ success: boolean; data?: { id: number; name: string }[] }>('/promos')
      .then((response) => setPromos(response.data.data || []))
      .catch(() => {});
  }, []);

  const openPicker = (key: PickerKey) => {
    setPickerSearch('');
    setActivePicker(key);
  };

  const choose = (key: PickerKey, option: PickerOption) => {
    if (key === 'region') {
      setFormData((prev) => ({ ...prev, region: option.value, city: '', barangay: '' }));
      address.selectRegion({ id: Number(option.id) });
    } else if (key === 'city') {
      setFormData((prev) => ({ ...prev, city: option.value, barangay: '' }));
      address.selectCity({ id: Number(option.id) });
    } else {
      setFormData((prev) => ({ ...prev, [key]: option.value }));
    }
    setActivePicker(null);
  };

  const pickerParent: Partial<Record<PickerKey, { key: PickerKey; prompt: string }>> = {
    city: { key: 'region', prompt: 'Select a region first' },
    barangay: { key: 'city', prompt: 'Select a city first' },
  };

  const picker = (key: PickerKey, label: string, required?: boolean) => (
    <View style={{ marginBottom: 16 }}>
      <SearchablePickerTrigger
        label={label}
        required={required}
        value={formData[key]}
        placeholder={pickerParent[key] && !formData[pickerParent[key]!.key] ? pickerParent[key]!.prompt : pickerTitles[key]}
        disabled={!!pickerParent[key] && !formData[pickerParent[key]!.key]}
        error={errors[key]}
        onPress={() => openPicker(key)}
      />
    </View>
  );

  useEffect(() => {
    if (isOpen) {
      setFormData(formFrom(application));
      setImages({});
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

  const pendingUpload = (): FormData | null => {
    const chosen = APPLICATION_IMAGE_FIELDS.filter((image) => images[image.key]);
    if (chosen.length === 0) return null;
    const upload = new FormData();
    upload.append('folder_name', `(application) ${formData.first_name} ${formData.last_name}`.trim());
    chosen.forEach((image) => upload.append(image.upload, images[image.key] as any));
    return upload;
  };

  const handleSubmit = async () => {
    if (!validateForm()) return;
    setLoading(true);
    try {
      const payload = TEXT_KEYS.reduce((body, key) => ({ ...body, [key]: formData[key].trim() }), {});
      await updateApplication(application.id, payload);
      const upload = pendingUpload();
      if (upload) await uploadApplicationImages(application.id, upload);
      Alert.alert('Success', 'Application updated successfully');
      onSaved();
      onClose();
    } catch (error: any) {
      const serverErrors = error?.response?.data?.errors;
      if (serverErrors) {
        Alert.alert('Validation Error', Object.values(serverErrors).flat().join('\n'));
      } else {
        Alert.alert('Error', error?.response?.data?.message || `Failed to update application: ${error?.message || 'Unknown error'}`);
      }
    } finally {
      setLoading(false);
    }
  };

  const labelStyle = { fontSize: 13, fontWeight: '500' as const, color: '#374151', marginBottom: 6 };
  const boxStyle = (key: string, editable: boolean, multiline?: boolean) => ({
    borderWidth: 1,
    borderColor: errors[key] ? '#ef4444' : '#d1d5db',
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: multiline ? 10 : 8,
    fontSize: 14,
    color: '#111827',
    backgroundColor: editable ? '#fff' : '#f3f4f6',
    textAlignVertical: (multiline ? 'top' : 'center') as 'top' | 'center',
    minHeight: multiline ? 72 : undefined,
  });

  const field = (
    label: string,
    key: FormKey,
    opts: { required?: boolean; placeholder?: string; keyboardType?: 'default' | 'email-address' | 'numeric' | 'phone-pad'; maxLength?: number; multiline?: boolean } = {}
  ) => (
    <View style={{ marginBottom: 16 }}>
      <Text style={labelStyle}>
        {label}
        {opts.required && <Text style={{ color: '#ef4444' }}> *</Text>}
      </Text>
      <TextInput
        accessibilityLabel={label}
        value={formData[key]}
        onChangeText={(v) => setFormData((prev) => ({ ...prev, [key]: v }))}
        placeholder={opts.placeholder || ''}
        placeholderTextColor="#9ca3af"
        keyboardType={opts.keyboardType || 'default'}
        maxLength={opts.maxLength}
        multiline={opts.multiline}
        numberOfLines={opts.multiline ? 3 : 1}
        style={boxStyle(key, true, opts.multiline)}
      />
      {errors[key] && <Text style={{ fontSize: 11, color: '#ef4444', marginTop: 4 }}>{errors[key]}</Text>}
    </View>
  );

  const readOnly = (label: string, value: string) => (
    <View style={{ marginBottom: 16 }}>
      <Text style={labelStyle}>{label}</Text>
      <TextInput accessibilityLabel={label} value={value} editable={false} style={boxStyle(label, false)} />
    </View>
  );

  return (
    <Modal visible={isOpen} animationType="slide" transparent onRequestClose={onClose}>
      <View style={{ flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'flex-end' }}>
        <View style={{ backgroundColor: '#fff', borderTopLeftRadius: 20, borderTopRightRadius: 20, maxHeight: '95%', flex: 1, marginTop: 60 }}>
          <View
            style={{
              flexDirection: 'row',
              alignItems: 'center',
              justifyContent: 'space-between',
              paddingHorizontal: 20,
              paddingVertical: 16,
              borderBottomWidth: 1,
              borderBottomColor: '#e5e7eb',
              backgroundColor: '#f9fafb',
              borderTopLeftRadius: 20,
              borderTopRightRadius: 20,
            }}
          >
            <Text style={{ fontSize: 18, fontWeight: '700', color: '#111827' }}>Edit Application</Text>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
              <TouchableOpacity
                onPress={handleSubmit}
                disabled={loading}
                style={{ backgroundColor: primary, paddingHorizontal: 16, paddingVertical: 8, borderRadius: 8, opacity: loading ? 0.5 : 1, flexDirection: 'row', alignItems: 'center', gap: 6 }}
              >
                {loading && <ActivityIndicator size="small" color="#fff" />}
                <Text style={{ color: '#fff', fontSize: 14, fontWeight: '600' }}>{loading ? 'Saving...' : 'Save'}</Text>
              </TouchableOpacity>
              <TouchableOpacity onPress={onClose} accessibilityLabel="Close">
                <X size={22} color="#6b7280" />
              </TouchableOpacity>
            </View>
          </View>

          <ScrollView style={{ flex: 1, paddingHorizontal: 20, paddingTop: 20 }}>

            <View style={{ flexDirection: 'row', gap: 12 }}>
              <View style={{ flex: 2 }}>{field('First Name', 'first_name', { required: true, placeholder: 'First name' })}</View>
              <View style={{ flex: 1 }}>{field('M.I.', 'middle_initial', { placeholder: 'M.I.', maxLength: 1 })}</View>
            </View>

            {field('Last Name', 'last_name', { required: true, placeholder: 'Enter last name' })}
            {field('Email Address', 'email_address', { required: true, placeholder: 'example@email.com', keyboardType: 'email-address' })}
            {field('Mobile Number', 'mobile_number', { required: true, placeholder: '09123456789', keyboardType: 'phone-pad', maxLength: 11 })}
            {field('Second Mobile Number', 'secondary_mobile_number', { placeholder: '09123456789', keyboardType: 'phone-pad', maxLength: 11 })}
            {field('Installation Address', 'installation_address', { required: true, placeholder: 'Enter full installation address', multiline: true })}
            {field('Landmark', 'landmark', { placeholder: 'Nearby landmark' })}

            <View style={{ flexDirection: 'row', gap: 12 }}>
              <View style={{ flex: 1 }}>{picker('region', 'Region')}</View>
              <View style={{ flex: 1 }}>{picker('city', 'City/Municipality')}</View>
            </View>

            <View style={{ flexDirection: 'row', gap: 12 }}>
              <View style={{ flex: 1 }}>{picker('barangay', 'Barangay')}</View>
              <View style={{ flex: 1 }}>{field('Location', 'location', { placeholder: 'Specific location' })}</View>
            </View>

            <View style={{ marginBottom: 16 }}>
              <LocationPicker
                value={formData.long_lat}
                onChange={(coordinates) => setFormData((prev) => ({ ...prev, long_lat: coordinates }))}
                isDarkMode={false}
                label="Map Pin"
            showCurrentLocation={false}
              />
            </View>

            {picker('desired_plan', 'Desired Plan', true)}
            {picker('promo', 'Promo')}
            {readOnly('Referred By', application?.referred_by || 'None')}
            {readOnly('Terms and Conditions', application?.terms_agreed ? 'Agreed' : 'Not agreed')}

            {APPLICATION_IMAGE_FIELDS.map((image) => (
              <View key={image.key} style={{ marginBottom: 16 }}>
                <ImagePreview
                  label={image.label}
                  imageUrl={images[image.key]?.uri || toImagePreviewUrl(application?.[image.column])}
                  onUpload={(file) => setImages((prev) => ({ ...prev, [image.key]: file }))}
                  colorPrimary={primary}
                />
              </View>
            ))}
            <View style={{ height: 32 }} />
          </ScrollView>
          {activePicker && (
            <SearchablePicker
              isOpen
              onClose={() => setActivePicker(null)}
              title={pickerTitles[activePicker]}
              data={pickerOptions[activePicker].filter((option) => option.label.toLowerCase().includes(pickerSearch.trim().toLowerCase()))}
              onSelect={(option) => choose(activePicker, option)}
              keyExtractor={(option) => option.id}
              searchValue={pickerSearch}
              onSearchChange={setPickerSearch}
              placeholder="Search..."
              selectedItemValue={formData[activePicker]}
              activeColor={primary}
            />
          )}
        </View>
      </View>
    </Modal>
  );
};

export default ApplicationEditModal;
