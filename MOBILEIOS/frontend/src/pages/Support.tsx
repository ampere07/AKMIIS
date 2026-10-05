import React, { useState, useEffect, useMemo } from 'react';
import { View, Text, TextInput, Pressable, ScrollView, Modal, ActivityIndicator, Linking, useWindowDimensions, RefreshControl, KeyboardAvoidingView, StyleSheet, DeviceEventEmitter, ActionSheetIOS } from 'react-native';
import { Upload, Clock, CheckCircle, Plus, ChevronDown } from 'lucide-react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { FlashList } from '@shopify/flash-list';
import * as WebBrowser from 'expo-web-browser';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { createServiceOrder } from '../services/serviceOrderService';
import { useCustomerDataContext } from '../contexts/CustomerDataContext';
import SupportDetails from '../components/SupportDetails';
import ImagePreview from '../components/ImagePreview';
import apiClient from '../config/api';
import { useTabBarOffset } from '../hooks/useTabBarOffset';
import { useModalHandoff } from '../hooks/useModalHandoff';

interface SupportRequest {
  id: string;
  date: string;
  requestId: string;
  issue: string;
  issueDetails: string;
  status: string;
  statusNote: string;
  assignedEmail: string;
  visitNote: string;
  visitInfo: {
    status: string;
  };
}

/** Where the ticket dialog is: asking to confirm, sending, or done. */
type SubmissionStep = 'confirm' | 'submitting' | 'success';

const CONCERN_OPTIONS = [
  'No Internet',
  'Slow Internet',
  'Intermittent Connection',
  'Router Issue',
  'Cable Problem',
  'Port Issue',
  'Others'
];

// Check ticket submission limits: 5 per day, 1 hour interval
const MAX_TICKETS_PER_DAY = 5;
const COOLDOWN_HOURS = 1;

// ─── Sub-components ────────────────────────────────────────────────────────────

const SupportCard = React.memo<{
  request: SupportRequest;
  primaryColor: string;
  onPress: (request: SupportRequest) => void;
}>(({ request, primaryColor, onPress }) => {
  return (
    <View style={s.card}>
      {/* Header Row */}
      <View style={s.cardHeaderRow}>
        <View style={s.cardHeaderLeft}>
          <Text style={s.ticketId}>#{request.requestId}</Text>
          <View style={[s.dateBadge, { backgroundColor: primaryColor + '10' }]}>
            <Text style={[s.dateText, { color: primaryColor }]}>{request.date}</Text>
          </View>
        </View>
        <View style={s.statusBadge}>
          <Text style={s.statusText}>{request.status}</Text>
        </View>
      </View>

      {/* Issue Section */}
      <View style={s.issueSection}>
        <Text style={s.issueTitle}>{request.issue}</Text>
        <Text style={s.issueDetails} numberOfLines={2}>
          {request.issueDetails}
        </Text>
      </View>

      <View style={s.cardFooterRow}>
        <View style={s.visitRow}>
          <CheckCircle size={14} color={request.visitInfo.status === 'Done' ? '#10b981' : '#9ca3af'} />
          <Text style={s.visitText}>
            Visit: {request.visitInfo.status}
          </Text>
        </View>
        <Pressable accessibilityRole="button"
          onPress={() => onPress(request)}
          style={[s.detailsBtn, { borderColor: primaryColor + '40' }]}
        >
          <Text style={[s.detailsBtnText, { color: primaryColor }]}>Details</Text>
        </Pressable>
      </View>
    </View>
  );
});

/**
 * The customer's support centre: their latest ticket, and the form to raise one.
 *
 * Differences from the full app's screen, all for iOS:
 *
 *  - Confirm, Submitting and Submitted are steps of one dialog rather than three
 *    modals opened one after another. iOS cannot present a modal while another
 *    is still closing (see useModalHandoff), so the original chain stopped at
 *    the first step on an iPhone — the ticket form closed and nothing appeared.
 *    The one remaining handoff, from the form to that dialog, waits for the
 *    form to finish closing.
 *  - A failed submission reopens the form with the error. The full app wrote the
 *    error into the form after it had closed, where nobody could read it.
 *  - The concern is chosen from an action sheet. The full app's dropdown is a
 *    Picker, which iOS draws as an always-open scroll wheel inside the form.
 *  - The full app also carries an unreachable copy of the dashboard's Pay Now
 *    flow and a pagination control for a list that only ever shows one ticket.
 *    Neither is carried over.
 */
const Support: React.FC = () => {
  const { width, height } = useWindowDimensions();
  const isMobile = width < 768;
  const isShort = height < 700;
  const insets = useSafeAreaInsets();
  const { lift } = useTabBarOffset();
  const handoff = useModalHandoff();
  const { customerDetail, serviceOrders: requests, isLoading: contextLoading, silentRefresh } = useCustomerDataContext();
  const userAccountNo = customerDetail?.billingAccount?.accountNo || '';
  const [colorPalette, setColorPalette] = useState<ColorPalette | null>(() => settingsColorPaletteService.getActiveSync());
  const primaryColor = colorPalette?.primary || '#ef4444';
  const [selectedConcern, setSelectedConcern] = useState<string>('No Internet');
  const [details, setDetails] = useState<string>('');
  const [image4File, setImage4File] = useState<any | null>(null);
  const [submitMessage, setSubmitMessage] = useState<string>('');
  const [cooldownTime, setCooldownTime] = useState<number>(0);
  const [showNewRequestModal, setShowNewRequestModal] = useState<boolean>(false);
  const [submissionStep, setSubmissionStep] = useState<SubmissionStep | null>(null);
  const [refreshing, setRefreshing] = useState<boolean>(false);
  const [selectedRequest, setSelectedRequest] = useState<SupportRequest | null>(null);
  const [userEmail, setUserEmail] = useState<string>('');

  const todayTicketInfo = useMemo(() => {
    if (!requests || requests.length === 0) return { count: 0, lastSubmitTime: null as Date | null };
    const today = new Date();
    const todayStr = `${String(today.getMonth() + 1).padStart(2, '0')}/${String(today.getDate()).padStart(2, '0')}/${today.getFullYear()}`;
    let count = 0;
    let lastSubmitTime: Date | null = null;
    for (const req of requests) {
      if (req.date === todayStr) {
        count++;
        if (!lastSubmitTime) {
          // First match is the latest since requests are sorted newest-first.
          // Use the precise submission timestamp so the cooldown reflects the
          // actual submit time — NOT "now" (which would show a bogus fresh
          // countdown on every page open even without submitting).
          const raw = (req as any).rawTimestamp || (req as any).timestamp || (req as any).created_at;
          if (raw) {
            const parsed = new Date(raw);
            if (!isNaN(parsed.getTime())) lastSubmitTime = parsed;
          }
          // Fallback: the ticket's calendar date at midnight (never the current
          // moment). A ticket created earlier today will then be well past the
          // 1-hour cooldown instead of restarting it.
          if (!lastSubmitTime && req.date) {
            const [mm, dd, yyyy] = req.date.split('/').map((n) => parseInt(n, 10));
            if (mm && dd && yyyy) {
              const midnight = new Date(yyyy, mm - 1, dd);
              if (!isNaN(midnight.getTime())) lastSubmitTime = midnight;
            }
          }
        }
      }
    }
    return { count, lastSubmitTime };
  }, [requests]);

  const hasReachedDailyLimit = todayTicketInfo.count >= MAX_TICKETS_PER_DAY;

  const cooldownRemaining = useMemo(() => {
    if (!todayTicketInfo.lastSubmitTime) return 0;
    const now = new Date();
    const elapsed = now.getTime() - todayTicketInfo.lastSubmitTime.getTime();
    const cooldownMs = COOLDOWN_HOURS * 60 * 60 * 1000;
    return Math.max(0, cooldownMs - elapsed);
  }, [todayTicketInfo.lastSubmitTime, cooldownTime]); // cooldownTime triggers re-compute

  const isInCooldown = cooldownRemaining > 0;
  const remainingRequests = (hasReachedDailyLimit || isInCooldown) ? 0 : MAX_TICKETS_PER_DAY - todayTicketInfo.count;

  // Countdown timer
  useEffect(() => {
    if (!isInCooldown) return;
    const timer = setInterval(() => {
      setCooldownTime(prev => prev + 1); // trigger re-render to update cooldownRemaining
    }, 1000);
    return () => clearInterval(timer);
  }, [isInCooldown]);

  useEffect(() => {
    const initPage = async () => {
      try {
        const [activePalette, authData] = await Promise.all([
          settingsColorPaletteService.getActive(),
          AsyncStorage.getItem('authData')
        ]);
        setColorPalette(activePalette);
        if (authData) {
          const user = JSON.parse(authData);
          setUserEmail(user.email || '');
        }
      } catch (err) {
        console.error('Support page init error:', err);
      }
    };
    initPage();

    const paletteSub = DeviceEventEmitter.addListener('colorPaletteChanged', (newPalette) => {
      setColorPalette(newPalette);
    });

    return () => paletteSub.remove();
  }, []);

  const onRefresh = React.useCallback(async () => {
    setRefreshing(true);
    try {
      await silentRefresh();
    } catch (error) {
      console.error('Refresh failed:', error);
    } finally {
      setRefreshing(false);
    }
  }, [silentRefresh]);

  const flashSubmitMessage = (message: string) => {
    setSubmitMessage(message);
    setTimeout(() => setSubmitMessage(''), 3000);
  };

  const chooseConcern = () => {
    ActionSheetIOS.showActionSheetWithOptions(
      {
        title: 'Concern',
        options: [...CONCERN_OPTIONS, 'Cancel'],
        cancelButtonIndex: CONCERN_OPTIONS.length,
        tintColor: primaryColor,
      },
      (index) => {
        if (index < CONCERN_OPTIONS.length) setSelectedConcern(CONCERN_OPTIONS[index]);
      }
    );
  };

  const handleSubmit = () => {
    if (!details.trim()) {
      flashSubmitMessage('Please provide details about your issue');
      return;
    }

    if (!userAccountNo) {
      flashSubmitMessage('Account number not found. Please log in again.');
      return;
    }

    if (hasReachedDailyLimit) {
      flashSubmitMessage(`Daily limit of ${MAX_TICKETS_PER_DAY} tickets reached. Please try again tomorrow.`);
      return;
    }

    if (isInCooldown) {
      const mins = Math.ceil(cooldownRemaining / 60000);
      flashSubmitMessage(`Please wait ${mins} minute${mins !== 1 ? 's' : ''} before submitting another ticket.`);
      return;
    }

    handoff.after(() => setSubmissionStep('confirm'));
    setShowNewRequestModal(false);
  };

  const handleConfirmSubmit = async () => {
    setSubmissionStep('submitting');

    let failure = '';
    try {
      let image4Url = '';
      if (image4File) {
        const fd = new FormData();
        fd.append('file', {
          uri: image4File.uri,
          name: image4File.name || `image4_${Date.now()}.jpg`,
          type: image4File.type || 'image/jpeg'
        } as any);

        const uploadRes = await apiClient.post('/google-drive/upload', fd, {
          headers: { 'Content-Type': 'multipart/form-data' }
        });

        if (uploadRes.data?.success) {
          image4Url = uploadRes.data.data.url;
        } else {
          throw new Error(uploadRes.data?.message || 'Failed to upload image');
        }
      }

      const response = await createServiceOrder({
        account_no: userAccountNo,
        concern: selectedConcern,
        concern_remarks: details,
        created_by_user: userEmail,
        requested_by: userEmail,
        support_status: 'Open',
        image4: image4Url || undefined
      });

      if (response.success) {
        setSubmissionStep('success');
        setDetails('');
        setImage4File(null);
        await silentRefresh();
        return;
      }

      failure = response.message || 'Failed to submit request. Please try again.';
    } catch (error: any) {
      console.error('Failed to submit request:', error);
      failure = error?.message || 'Failed to submit request. Please try again.';
    }

    // Back to the form, which still holds what they wrote, with the reason.
    handoff.after(() => {
      setShowNewRequestModal(true);
      flashSubmitMessage(failure);
    });
    setSubmissionStep(null);
  };

  const handleOpenChat = async () => {
    const webUrl = 'https://m.me/akmiis';
    const messengerAppUrl = 'fb-messenger://user-thread/';
    try {
      // fb-messenger is declared in LSApplicationQueriesSchemes (app.json);
      // without that, iOS answers false here whether Messenger is installed or not.
      const canOpenMessenger = await Linking.canOpenURL(messengerAppUrl);
      if (canOpenMessenger) {
        await Linking.openURL(webUrl);
      } else {
        await WebBrowser.openBrowserAsync(webUrl);
      }
    } catch (error) {
      await WebBrowser.openBrowserAsync(webUrl);
    }
  };

  const handleRequestPlanUpdate = handleOpenChat;

  const latestRequest = useMemo(() => {
    return requests.length > 0 ? [requests[0]] : [];
  }, [requests]);

  const isErrorMessage = submitMessage.includes('Failed') || submitMessage.includes('limit') || submitMessage.includes('not found');

  return (
    <View style={s.container}>
      <View style={[s.header, { paddingTop: isMobile ? (isShort ? 20 : 60) : 20, gap: isShort ? 12 : 24, paddingHorizontal: isMobile ? 16 : 24 }]}>
        <View style={s.titleContainer}>
          <Text style={s.title}>Support Center</Text>
        </View>
        <Text style={s.subtitle}>Track and manage your service requests</Text>
      </View>

      <FlashList
        data={latestRequest}
        keyExtractor={(item: any) => item.id}
        contentContainerStyle={{
          paddingHorizontal: isMobile ? 16 : 24,
          paddingBottom: 120 + lift,
        }}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={onRefresh}
            tintColor={primaryColor}
          />
        }
        ListEmptyComponent={() => (
          contextLoading ? (
            <View style={s.emptyState}>
              <ActivityIndicator size="large" color={primaryColor} />
              <Text style={s.emptyText}>Loading records...</Text>
            </View>
          ) : (
            <View style={s.emptyState}>
              <Text style={s.emptyTitle}>No Requests Yet</Text>
              <Text style={s.emptySubtitle}>If you have any issues with your connection, please submit a ticket.</Text>
            </View>
          )
        )}
        renderItem={({ item }) => (
          <SupportCard
            request={item}
            primaryColor={primaryColor}
            onPress={setSelectedRequest}
          />
        )}
      />

      {/* Support Details View */}
      {selectedRequest && (
        <View style={[StyleSheet.absoluteFill, { zIndex: 100 }]}>
          <SupportDetails
            request={selectedRequest}
            onClose={() => setSelectedRequest(null)}
          />
        </View>
      )}

      {/* Floating Action Button. Above the tab bar on every width: the full app
          dropped it to 30pt on tablets, underneath the bar it was meant to clear. */}
      <Pressable
        onPress={() => setShowNewRequestModal(true)}
        accessibilityRole="button"
        accessibilityLabel="New support request"
        style={[s.fab, { bottom: 110 + lift, right: isMobile ? 20 : 30, backgroundColor: primaryColor }]}
      >
        <Plus size={28} color="#ffffff" />
      </Pressable>

      {/* New Request Modal */}
      <Modal
        visible={showNewRequestModal}
        animationType="slide"
        onRequestClose={() => setShowNewRequestModal(false)}
        onDismiss={handoff.onDismiss}
      >
        <KeyboardAvoidingView behavior="padding" style={s.formRoot}>
          <View style={[s.formBody, { paddingTop: Math.max(insets.top, 16) + 8 }]}>
            <View style={s.formHeader}>
              <Pressable accessibilityRole="button" onPress={() => setShowNewRequestModal(false)} style={{ padding: 8 }}>
                <Text style={{ color: primaryColor, fontWeight: '600' }}>Cancel</Text>
              </Pressable>
              <Text style={s.formTitle}>New Request</Text>
            </View>

            <ScrollView showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled">
              <View style={{ marginBottom: 16 }}>
                <Text style={s.fieldLabel}>Concern</Text>
                <Pressable
                  onPress={chooseConcern}
                  accessibilityRole="button"
                  accessibilityLabel={`Concern: ${selectedConcern}`}
                  style={s.selectField}
                >
                  <Text style={s.selectFieldText}>{selectedConcern}</Text>
                  <ChevronDown size={18} color="#6b7280" />
                </Pressable>
              </View>

              <View style={{ marginBottom: 24 }}>
                <Text style={s.fieldLabel}>Details</Text>
                <TextInput
                  value={details}
                  onChangeText={setDetails}
                  placeholder="Describe your issue..."
                  placeholderTextColor="#9ca3af"
                  multiline
                  numberOfLines={5}
                  textAlignVertical="top"
                  style={s.detailsInput}
                />
              </View>

              <View style={{ marginBottom: 24 }}>
                <ImagePreview
                  label="Upload Image (Optional)"
                  imageUrl={image4File?.uri || null}
                  onUpload={(file) => {
                    setImage4File(file);
                  }}
                  colorPrimary={primaryColor}
                />
              </View>

              <Pressable accessibilityRole="button"
                onPress={handleSubmit}
                disabled={remainingRequests <= 0}
                style={[s.submitBtn, {
                  backgroundColor: remainingRequests <= 0 ? '#6b7280' : (colorPalette?.primary || '#1e40af'),
                  opacity: remainingRequests <= 0 ? 0.5 : 1
                }]}
              >
                <Text style={{ color: 'white', fontWeight: '500' }}>
                  SUBMIT TICKET
                </Text>
              </Pressable>

              <View style={{ marginTop: 12, alignItems: 'center' }}>
                <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                  <Clock size={14} color="#4b5563" style={{ marginRight: 4 }} />
                  <Text style={{ fontSize: 14, color: '#4b5563' }}>
                    {hasReachedDailyLimit
                      ? `Daily limit reached (${todayTicketInfo.count}/${MAX_TICKETS_PER_DAY}).`
                      : isInCooldown
                        ? `Next ticket available in ${Math.floor(cooldownRemaining / 60000)}m ${Math.floor((cooldownRemaining % 60000) / 1000)}s`
                        : `Limit: ${MAX_TICKETS_PER_DAY} requests/day. (${todayTicketInfo.count}/${MAX_TICKETS_PER_DAY} used)`}
                  </Text>
                </View>
              </View>

              {!!submitMessage && (
                <View style={{
                  marginTop: 12,
                  padding: 12,
                  borderRadius: 4,
                  backgroundColor: isErrorMessage ? primaryColor + '15' : '#10b98115'
                }}>
                  <Text style={{
                    fontSize: 14,
                    textAlign: 'center',
                    color: isErrorMessage ? primaryColor : '#10b981'
                  }}>
                    {submitMessage}
                  </Text>
                </View>
              )}

              <Pressable accessibilityRole="button" onPress={handleRequestPlanUpdate} style={s.planUpdateBtn}>
                <Upload size={16} color="#374151" style={{ marginRight: 8 }} />
                <Text style={{ fontWeight: '500', color: '#374151' }}>
                  Request Plan Update
                </Text>
              </Pressable>

              <View style={{ height: Math.max(insets.bottom, 16) }} />
            </ScrollView>
          </View>
        </KeyboardAvoidingView>
      </Modal>

      {/* Ticket dialog: confirm, then submitting, then submitted */}
      <Modal
        visible={submissionStep !== null}
        transparent
        animationType="fade"
        onRequestClose={() => { if (submissionStep !== 'submitting') setSubmissionStep(null); }}
        onDismiss={handoff.onDismiss}
      >
        <View style={s.dialogOverlay}>
          {submissionStep === 'confirm' && (
            <View style={[s.dialogCard, { padding: 24, maxWidth: 448 }]}>
              <View style={{ marginBottom: 16 }}>
                <Text style={s.dialogTitle}>Confirm Submission</Text>
              </View>
              <Text style={{ marginBottom: 24, color: '#374151' }}>
                Are you sure you want to submit this support ticket?
              </Text>
              <View style={s.confirmSummary}>
                <Text style={{ fontSize: 14, fontWeight: '500', marginBottom: 4, color: '#374151' }}>
                  Concern: {selectedConcern}
                </Text>
                <Text style={{ fontSize: 14, color: '#4b5563' }}>
                  Details: {details.substring(0, 100)}{details.length > 100 ? '...' : ''}
                </Text>
              </View>
              <View style={{ flexDirection: 'row', gap: 12 }}>
                <Pressable accessibilityRole="button" onPress={() => setSubmissionStep(null)} style={[s.dialogBtn, { backgroundColor: '#e5e7eb' }]}>
                  <Text style={{ fontWeight: '500', color: '#374151' }}>Cancel</Text>
                </Pressable>
                <Pressable accessibilityRole="button" onPress={handleConfirmSubmit} style={[s.dialogBtn, { backgroundColor: primaryColor }]}>
                  <Text style={{ fontWeight: '500', color: 'white' }}>Confirm</Text>
                </Pressable>
              </View>
            </View>
          )}

          {submissionStep === 'submitting' && (
            <View style={[s.dialogCard, { padding: 32, maxWidth: 384 }]}>
              <View style={{ flexDirection: 'column', alignItems: 'center' }}>
                <ActivityIndicator size="large" color={primaryColor} style={{ marginBottom: 16 }} />
                <Text style={[s.dialogTitle, { marginBottom: 8 }]}>Submitting Ticket</Text>
                <Text style={{ fontSize: 14, color: '#4b5563' }}>Please wait...</Text>
              </View>
            </View>
          )}

          {submissionStep === 'success' && (
            <View style={[s.dialogCard, { padding: 24, maxWidth: 448 }]}>
              <View style={{ flexDirection: 'column', alignItems: 'center' }}>
                <View style={{ borderRadius: 9999, padding: 12, marginBottom: 16, backgroundColor: `${primaryColor}33` }}>
                  <CheckCircle size={48} color={primaryColor} />
                </View>
                <Text style={[s.dialogTitle, { marginBottom: 8 }]}>Ticket Submitted Successfully</Text>
                <Text style={{ fontSize: 14, textAlign: 'center', marginBottom: 24, color: '#4b5563' }}>
                  Your support ticket has been created. We will get back to you soon.
                </Text>
                <Pressable accessibilityRole="button" onPress={() => setSubmissionStep(null)} style={[s.dialogBtn, { flex: 0, width: '100%', backgroundColor: primaryColor }]}>
                  <Text style={{ fontWeight: '500', color: 'white' }}>OK</Text>
                </Pressable>
              </View>
            </View>
          )}
        </View>
      </Modal>
    </View>
  );
};

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f9fafb' },
  header: { paddingHorizontal: 24, paddingBottom: 20, alignItems: 'center' },
  titleContainer: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 4 },
  title: { fontSize: 26, fontWeight: '800', color: '#111827' },
  subtitle: { fontSize: 14, color: '#6b7280', fontWeight: '500' },
  card: {
    backgroundColor: '#ffffff', borderRadius: 16, padding: 18, marginBottom: 16,
    shadowColor: '#000', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.04, shadowRadius: 8,
  },
  cardHeaderRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 },
  cardHeaderLeft: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  ticketId: { fontSize: 16, fontWeight: '800', color: '#111827' },
  dateBadge: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 8 },
  dateText: { fontSize: 11, fontWeight: '700' },
  statusBadge: { backgroundColor: '#f3f4f6', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 8 },
  statusText: { fontSize: 11, fontWeight: '700', color: '#374151' },
  issueSection: { marginBottom: 16 },
  issueTitle: { fontSize: 15, fontWeight: '700', color: '#1f2937', marginBottom: 4 },
  issueDetails: { fontSize: 13, color: '#4b5563', lineHeight: 20 },
  cardFooterRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', borderTopWidth: 1, borderTopColor: '#f3f4f6', paddingTop: 14 },
  visitRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  visitText: { fontSize: 12, fontWeight: '600', color: '#6b7280' },
  detailsBtn: { paddingVertical: 8, paddingHorizontal: 16, borderRadius: 10, borderWidth: 1.5 },
  detailsBtnText: { fontSize: 12, fontWeight: '700' },
  emptyState: { paddingVertical: 80, alignItems: 'center', width: '100%', paddingHorizontal: 40 },
  emptyTitle: { fontSize: 20, fontWeight: '800', color: '#6b7280', marginBottom: 8 },
  emptySubtitle: { fontSize: 14, color: '#6b7280', textAlign: 'center', lineHeight: 22 },
  emptyText: { marginTop: 16, color: '#6b7280', fontSize: 14 },
  fab: {
    position: 'absolute',
    width: 60,
    height: 60,
    borderRadius: 30,
    justifyContent: 'center',
    alignItems: 'center',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.3,
    shadowRadius: 6,
  },
  formRoot: { flex: 1, backgroundColor: '#f9fafb' },
  formBody: { flex: 1, paddingHorizontal: 24, paddingBottom: 0 },
  formHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 24 },
  // marginRight offsets the Cancel button so the title sits centred.
  formTitle: { fontSize: 18, fontWeight: '600', color: '#111827', flex: 1, textAlign: 'center', marginRight: 60 },
  fieldLabel: { fontSize: 14, fontWeight: '500', marginBottom: 8, color: '#374151' },
  selectField: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    paddingHorizontal: 12, paddingVertical: 14,
    backgroundColor: '#ffffff', borderWidth: 1, borderColor: '#d1d5db', borderRadius: 4,
  },
  selectFieldText: { fontSize: 16, color: '#111827' },
  detailsInput: {
    width: '100%', paddingHorizontal: 12, paddingVertical: 8, borderRadius: 4, borderWidth: 1,
    backgroundColor: '#ffffff', color: '#111827', borderColor: '#d1d5db', minHeight: 120,
  },
  submitBtn: { width: '100%', paddingVertical: 12, borderRadius: 4, alignItems: 'center' },
  planUpdateBtn: {
    width: '100%', marginTop: 16, paddingVertical: 12, borderRadius: 4, borderWidth: 2, borderColor: '#d1d5db',
    alignItems: 'center', flexDirection: 'row', justifyContent: 'center',
  },
  dialogOverlay: { flex: 1, backgroundColor: 'transparent', justifyContent: 'center', alignItems: 'center' },
  dialogCard: {
    backgroundColor: '#ffffff', borderRadius: 8, width: '90%',
    shadowColor: '#000', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.3, shadowRadius: 12,
  },
  dialogTitle: { fontSize: 20, fontWeight: '600', color: '#111827' },
  confirmSummary: { marginBottom: 16, padding: 12, borderRadius: 4, backgroundColor: '#f3f4f6' },
  dialogBtn: { flex: 1, paddingVertical: 8, paddingHorizontal: 16, borderRadius: 4, alignItems: 'center' },
});

export default Support;
