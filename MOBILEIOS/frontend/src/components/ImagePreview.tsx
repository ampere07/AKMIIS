import React, { useState } from 'react';
import { View, Text, Pressable, Image, Alert, Modal, StyleSheet } from 'react-native';
import { Camera, X, Upload, Image as ImageIcon } from 'lucide-react-native';
import * as ImagePicker from 'expo-image-picker';

interface ImagePreviewProps {
    label: string;
    imageUrl?: string | null;
    onUpload: (file: any) => void;
    error?: string;
    colorPrimary?: string;
    required?: boolean;
}

/**
 * A photo field: a thumbnail, and a sheet offering the camera or the library.
 *
 * Two things differ from the full app's component, both deliberately:
 *
 *  - Choosing from the library asks for no permission first. On iOS 14 and
 *    later the picker runs out of process (PHPickerViewController) and hands
 *    back only the photo chosen, so a library-wide permission prompt would be
 *    asking for access the app never uses.
 *  - A photo taken with the camera is not also saved to the gallery. That copy
 *    is the technicians' photo backup for job orders; a customer attaching a
 *    picture to a ticket did not ask for one, and it would cost a second
 *    permission prompt to make it.
 */
const ImagePreview: React.FC<ImagePreviewProps> = ({
    label,
    imageUrl,
    onUpload,
    error,
    colorPrimary = '#7c3aed',
    required = false,
}) => {
    const [modalVisible, setModalVisible] = useState(false);

    const toFile = (asset: ImagePicker.ImagePickerAsset, fallbackName: string) => ({
        uri: asset.uri,
        name: asset.fileName || fallbackName,
        type: asset.mimeType || (asset.type === 'image' ? 'image/jpeg' : asset.type)
    });

    const pickImage = async () => {
        const result = await ImagePicker.launchImageLibraryAsync({
            mediaTypes: ['images'],
            allowsEditing: false,
            quality: 0.7,
        });

        if (!result.canceled && result.assets && result.assets.length > 0) {
            onUpload(toFile(result.assets[0], 'upload.jpg'));
            setModalVisible(false);
        }
    };

    const takePhoto = async () => {
        const { status } = await ImagePicker.requestCameraPermissionsAsync();
        if (status !== 'granted') {
            Alert.alert(
                'Camera access needed',
                'Allow AKM to use the camera in Settings to take a photo for your ticket.'
            );
            return;
        }

        const result = await ImagePicker.launchCameraAsync({
            allowsEditing: false,
            quality: 0.7,
        });

        if (!result.canceled && result.assets && result.assets.length > 0) {
            onUpload(toFile(result.assets[0], 'photo.jpg'));
            setModalVisible(false);
        }
    };

    return (
        <View style={s.container}>
            <Text style={s.label}>
                {label}
                {required && <Text style={{ color: '#ef4444' }}> *</Text>}
            </Text>

            <View style={s.row}>
                {/* Preview Area */}
                {imageUrl ? (
                    <View style={s.previewFilled}>
                        <Image
                            source={{ uri: imageUrl }}
                            style={s.previewImage}
                            resizeMode="cover"
                        />
                        <Pressable accessibilityRole="button" accessibilityLabel="Remove photo"
                            onPress={() => onUpload(null)}
                            style={s.removeBtn}
                            hitSlop={8}
                        >
                            <X size={14} color="#ffffff" />
                        </Pressable>
                    </View>
                ) : (
                    <View style={s.previewEmpty}>
                        <ImageIcon size={40} color="#9ca3af" />
                    </View>
                )}

                {/* Upload Button */}
                <Pressable accessibilityRole="button" onPress={() => setModalVisible(true)} style={s.uploadBtn}>
                    <Camera size={20} color="#6b7280" />
                    <Text style={s.uploadBtnText}>Click to upload</Text>
                </Pressable>
            </View>

            {error && (
                <View style={s.errorRow}>
                    <View style={[s.errorDot, { backgroundColor: colorPrimary }]}>
                        <Text style={s.errorDotText}>!</Text>
                    </View>
                    <Text style={[s.errorText, { color: colorPrimary }]}>{error}</Text>
                </View>
            )}

            {/* Selection Modal */}
            <Modal
                animationType="slide"
                transparent={true}
                visible={modalVisible}
                onRequestClose={() => setModalVisible(false)}
            >
                <View style={s.sheetOverlay}>
                    <View style={s.sheet}>
                        <View style={s.sheetHeader}>
                            <Text style={s.sheetTitle}>Upload Photo</Text>
                            <Pressable accessibilityRole="button" accessibilityLabel="Close" onPress={() => setModalVisible(false)} hitSlop={8}>
                                <X size={24} color="#000" />
                            </Pressable>
                        </View>

                        <View style={s.options}>
                            <Pressable accessibilityRole="button" onPress={takePhoto} style={s.option}>
                                <View style={s.optionIcon}>
                                    <Camera size={24} color={colorPrimary} />
                                </View>
                                <View>
                                    <Text style={s.optionTitle}>Take Photo</Text>
                                    <Text style={s.optionDesc}>Use your camera to take a new photo</Text>
                                </View>
                            </Pressable>

                            <Pressable accessibilityRole="button" onPress={pickImage} style={s.option}>
                                <View style={s.optionIcon}>
                                    <Upload size={24} color={colorPrimary} />
                                </View>
                                <View>
                                    <Text style={s.optionTitle}>Choose from Library</Text>
                                    <Text style={s.optionDesc}>Select an existing photo from your gallery</Text>
                                </View>
                            </Pressable>
                        </View>
                    </View>
                </View>
            </Modal>
        </View>
    );
};

const s = StyleSheet.create({
    container: { marginBottom: 16 },
    label: { fontSize: 14, fontWeight: '500', marginBottom: 8, color: '#374151' },
    row: { flexDirection: 'row', alignItems: 'center', gap: 16 },
    previewFilled: { position: 'relative', width: 96, height: 96, borderRadius: 8, overflow: 'hidden', borderWidth: 1, borderColor: '#d1d5db' },
    previewImage: { width: '100%', height: '100%' },
    removeBtn: { position: 'absolute', top: 4, right: 4, backgroundColor: 'rgba(0,0,0,0.6)', borderRadius: 9999, padding: 4 },
    previewEmpty: { width: 96, height: 96, borderRadius: 8, borderWidth: 2, borderStyle: 'dashed', borderColor: '#d1d5db', backgroundColor: '#f9fafb', alignItems: 'center', justifyContent: 'center' },
    uploadBtn: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', paddingVertical: 12, paddingHorizontal: 16, borderRadius: 8, borderWidth: 1, borderStyle: 'dashed', borderColor: '#d1d5db', backgroundColor: '#f9fafb' },
    uploadBtnText: { marginLeft: 8, fontSize: 14, fontWeight: '500', color: '#4b5563' },
    errorRow: { flexDirection: 'row', alignItems: 'center', marginTop: 4 },
    errorDot: { alignItems: 'center', justifyContent: 'center', width: 16, height: 16, borderRadius: 9999, marginRight: 8 },
    errorDotText: { color: '#ffffff', fontSize: 10, fontWeight: 'bold' },
    errorText: { fontSize: 12 },
    sheetOverlay: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(0,0,0,0.5)' },
    sheet: { borderTopLeftRadius: 12, borderTopRightRadius: 12, padding: 24, backgroundColor: '#ffffff' },
    sheetHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 },
    sheetTitle: { fontSize: 18, fontWeight: 'bold', color: '#111827' },
    options: { gap: 16, paddingBottom: 32 },
    option: { flexDirection: 'row', alignItems: 'center', padding: 16, borderRadius: 12, borderWidth: 1, borderColor: '#e5e7eb', backgroundColor: '#f9fafb' },
    optionIcon: { padding: 12, borderRadius: 9999, marginRight: 16, backgroundColor: '#ffffff' },
    optionTitle: { fontWeight: '600', fontSize: 18, color: '#111827' },
    optionDesc: { fontSize: 14, color: '#6b7280' },
});

export default ImagePreview;
