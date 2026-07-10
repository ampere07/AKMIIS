import React, { useState, useEffect, useMemo } from 'react';
import {
  View,
  Text,
  FlatList,
  TouchableOpacity,
  ActivityIndicator,
  RefreshControl,
  Alert,
  Dimensions,
} from 'react-native';
import { Picker } from '@react-native-picker/picker';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  Plus,
  RefreshCw,
  ChevronsLeft,
  ChevronsRight,
  ChevronLeft,
  ChevronRight,
  Trash2,
  Edit,
  Users,
  Banknote,
} from 'lucide-react-native';
import GlobalSearch from './globalfunctions/GlobalSearch';
import { Agent } from '../types/api';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import AgentModal from '../modals/AgentModal';
import CommissionPayoutModal from '../modals/CommissionPayoutModal';
import { useAgentStore } from '../store/agentStore';
import { agentService } from '../services/agentService';

const { width } = Dimensions.get('window');
const isTablet = width >= 768;

const TeamAgent: React.FC = () => {
  // FORCED LIGHT MODE
  const isDarkMode = false;

  const [searchQuery, setSearchQuery] = useState('');
  const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const {
    agents,
    isLoading,
    error,
    fetchAgents,
    refreshAgents,
    addAgent,
    updateAgent,
    removeAgent,
  } = useAgentStore();

  const [selectedAgent, setSelectedAgent] = useState<Agent | null>(null);
  const [showModal, setShowModal] = useState(false);
  const [showPayoutModal, setShowPayoutModal] = useState(false);
  const [payoutAgent, setPayoutAgent] = useState<Agent | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage, setItemsPerPage] = useState(25);

  const [userOrgId, setUserOrgId] = useState<number | null>(null);

  useEffect(() => {
    const init = async () => {
      try {
        const palette = await settingsColorPaletteService.getActive();
        setColorPalette(palette);
      } catch (err) {
        console.error('Failed to fetch color palette:', err);
      }
      try {
        const raw = await AsyncStorage.getItem('authData');
        const authData = raw ? JSON.parse(raw) : {};
        const orgId =
          authData.organization_id ||
          authData.user?.organization_id ||
          authData.organization?.id ||
          authData.user?.organization?.id ||
          null;
        setUserOrgId(orgId);
      } catch {
        setUserOrgId(null);
      }
    };
    init();
  }, []);

  useEffect(() => {
    fetchAgents();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // Auto silent-refresh every 15 minutes
  useEffect(() => {
    const id = setInterval(() => {
      refreshAgents().catch((e) => console.error('Idle refresh failed:', e));
    }, 15 * 60 * 1000);
    return () => clearInterval(id);
  }, [refreshAgents]);

  const primaryColor = colorPalette?.primary || '#7c3aed';

  const handleRefresh = async () => {
    setRefreshing(true);
    await refreshAgents();
    setRefreshing(false);
  };

  const filteredAgents = useMemo(() => {
    return agents.filter((agent) => {
      // Organization filter — mirrors web logic exactly
      if (userOrgId) {
        if (agent.organization_id !== userOrgId) return false;
      } else {
        if (agent.organization_id) return false;
      }

      const teamName = (agent.team_name || '').toLowerCase();
      const query = searchQuery.toLowerCase().trim();
      return teamName.includes(query);
    });
  }, [agents, searchQuery, userOrgId]);

  const totalPages = Math.ceil(filteredAgents.length / itemsPerPage);
  const paginatedAgents = useMemo(() => {
    const start = (currentPage - 1) * itemsPerPage;
    return filteredAgents.slice(start, start + itemsPerPage);
  }, [filteredAgents, currentPage, itemsPerPage]);

  const handlePageChange = (newPage: number) => {
    if (newPage >= 1 && newPage <= totalPages) setCurrentPage(newPage);
  };

  const handleSaveAgent = (savedAgent: Agent) => {
    const exists = agents.find((a) => a.id === savedAgent.id);
    if (exists) {
      updateAgent(savedAgent);
    } else {
      addAgent(savedAgent);
    }
  };

  const handleDeleteAgent = (id: number) => {
    Alert.alert('Delete Agent', 'Are you sure you want to delete this agent?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          try {
            const res = await agentService.deleteAgent(id);
            if (res.success) {
              removeAgent(id);
            } else {
              Alert.alert('Error', res.message || 'Failed to delete agent');
            }
          } catch (err: any) {
            Alert.alert('Error', err.message || 'An error occurred');
          }
        },
      },
    ]);
  };

  const renderAgent = ({ item: agent }: { item: Agent }) => (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 12,
        paddingHorizontal: 16,
        backgroundColor: '#ffffff',
        borderBottomWidth: 1,
        borderBottomColor: '#f3f4f6',
      }}
    >
      <View
        style={{
          width: 40,
          height: 40,
          borderRadius: 20,
          backgroundColor: '#f3f4f6',
          alignItems: 'center',
          justifyContent: 'center',
          marginRight: 12,
        }}
      >
        <Users size={18} color="#6b7280" />
      </View>
      <View style={{ flex: 1, minWidth: 0 }}>
        <Text style={{ fontSize: 14, fontWeight: '600', color: '#111827' }} numberOfLines={1}>
          {agent.team_name}
        </Text>
        <Text style={{ fontSize: 12, color: '#9ca3af', marginTop: 2 }} numberOfLines={1}>
          {agent.created_at ? new Date(agent.created_at).toLocaleDateString() : 'N/A'}
          {agent.created_by ? ` • ${agent.created_by}` : ''}
        </Text>
      </View>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
        <TouchableOpacity
          onPress={() => { setPayoutAgent(agent); setShowPayoutModal(true); }}
          style={{ padding: 8, borderRadius: 8, backgroundColor: '#f0fdf4' }}
        >
          <Banknote size={16} color="#16a34a" />
        </TouchableOpacity>
        <TouchableOpacity
          onPress={() => { setSelectedAgent(agent); setShowModal(true); }}
          style={{ padding: 8, borderRadius: 8, backgroundColor: '#eff6ff' }}
        >
          <Edit size={16} color="#2563eb" />
        </TouchableOpacity>
        <TouchableOpacity
          onPress={() => handleDeleteAgent(agent.id)}
          style={{ padding: 8, borderRadius: 8, backgroundColor: '#fef2f2' }}
        >
          <Trash2 size={16} color="#dc2626" />
        </TouchableOpacity>
      </View>
    </View>
  );

  const PaginationControls = () => {
    if (totalPages <= 1) return null;
    const start = Math.min((currentPage - 1) * itemsPerPage + 1, filteredAgents.length);
    const end = Math.min(currentPage * itemsPerPage, filteredAgents.length);
    return (
      <View
        style={{
          borderTopWidth: 1,
          borderTopColor: '#e5e7eb',
          backgroundColor: '#ffffff',
          padding: 12,
        }}
      >
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
            <Text style={{ fontSize: 12, color: '#6b7280' }}>Show</Text>
            <View style={{ borderWidth: 1, borderColor: '#e5e7eb', borderRadius: 6, overflow: 'hidden', minWidth: 70 }}>
              <Picker
                selectedValue={String(itemsPerPage)}
                onValueChange={(v) => { setItemsPerPage(Number(v)); setCurrentPage(1); }}
                style={{ height: 36, fontSize: 12 }}
              >
                {[10, 25, 50, 100].map((v) => (
                  <Picker.Item key={v} label={String(v)} value={String(v)} />
                ))}
              </Picker>
            </View>
            <Text style={{ fontSize: 12, color: '#6b7280' }}>
              {start}-{end} of {filteredAgents.length}
            </Text>
          </View>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
            <TouchableOpacity onPress={() => handlePageChange(1)} disabled={currentPage === 1} style={{ padding: 4, opacity: currentPage === 1 ? 0.3 : 1 }}>
              <ChevronsLeft size={16} color="#374151" />
            </TouchableOpacity>
            <TouchableOpacity onPress={() => handlePageChange(currentPage - 1)} disabled={currentPage === 1} style={{ padding: 4, opacity: currentPage === 1 ? 0.3 : 1 }}>
              <ChevronLeft size={16} color="#374151" />
            </TouchableOpacity>
            <Text style={{ fontSize: 12, color: '#374151', paddingHorizontal: 8 }}>
              Page {currentPage} of {totalPages}
            </Text>
            <TouchableOpacity onPress={() => handlePageChange(currentPage + 1)} disabled={currentPage === totalPages} style={{ padding: 4, opacity: currentPage === totalPages ? 0.3 : 1 }}>
              <ChevronRight size={16} color="#374151" />
            </TouchableOpacity>
            <TouchableOpacity onPress={() => handlePageChange(totalPages)} disabled={currentPage === totalPages} style={{ padding: 4, opacity: currentPage === totalPages ? 0.3 : 1 }}>
              <ChevronsRight size={16} color="#374151" />
            </TouchableOpacity>
          </View>
        </View>
      </View>
    );
  };

  const ListContent = () => {
    if (isLoading && agents.length === 0) {
      return (
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', paddingVertical: 64 }}>
          <ActivityIndicator size="large" color={primaryColor} />
          <Text style={{ marginTop: 12, fontSize: 14, color: '#6b7280' }}>Loading agents...</Text>
        </View>
      );
    }
    if (error) {
      return (
        <View style={{ padding: 40, alignItems: 'center' }}>
          <Text style={{ fontSize: 14, color: '#ef4444' }}>{error}</Text>
        </View>
      );
    }
    if (filteredAgents.length === 0) {
      return (
        <View style={{ padding: 48, alignItems: 'center', opacity: 0.4 }}>
          <Users size={48} color="#6b7280" />
          <Text style={{ marginTop: 12, fontSize: 14, color: '#6b7280' }}>No agents found</Text>
        </View>
      );
    }
    return null;
  };

  return (
    <View style={{ flex: 1, backgroundColor: '#f9fafb' }}>
      {/* Header */}
      <View
        style={{
          backgroundColor: '#ffffff',
          borderBottomWidth: 1,
          borderBottomColor: '#e5e7eb',
          paddingTop: isTablet ? 16 : 60,
          paddingHorizontal: 16,
          paddingBottom: 12,
        }}
      >
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
          <View>
            <Text style={{ fontSize: 18, fontWeight: '700', color: '#111827' }}>Team Agent Management</Text>
            <Text style={{ fontSize: 12, color: '#9ca3af', marginTop: 2 }}>Manage system agents and teams</Text>
          </View>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
            <TouchableOpacity
              onPress={() => refreshAgents()}
              style={{ padding: 8, borderRadius: 8, backgroundColor: '#f3f4f6' }}
            >
              <RefreshCw size={18} color="#6b7280" />
            </TouchableOpacity>
            <TouchableOpacity
              onPress={() => { setSelectedAgent(null); setShowModal(true); }}
              style={{ padding: 8, borderRadius: 8, backgroundColor: primaryColor }}
            >
              <Plus size={20} color="#ffffff" />
            </TouchableOpacity>
          </View>
        </View>

        <GlobalSearch
          searchQuery={searchQuery}
          setSearchQuery={setSearchQuery}
          isDarkMode={isDarkMode}
          colorPalette={colorPalette}
          placeholder="Search team name..."
        />
      </View>

      {/* List */}
      <View style={{ flex: 1 }}>
        <ListContent />
        {filteredAgents.length > 0 && (
          <FlatList
            data={paginatedAgents}
            keyExtractor={(item) => String(item.id)}
            renderItem={renderAgent}
            refreshControl={
              <RefreshControl refreshing={refreshing} onRefresh={handleRefresh} colors={[primaryColor]} />
            }
            contentContainerStyle={{ backgroundColor: '#ffffff' }}
          />
        )}
        {!isLoading && filteredAgents.length > 0 && <PaginationControls />}
      </View>

      {/* Add/Edit Agent Modal */}
      <AgentModal
        isOpen={showModal}
        onClose={() => setShowModal(false)}
        onSave={handleSaveAgent}
        agent={selectedAgent}
      />

      {/* Commission Payout Modal */}
      {payoutAgent && (
        <CommissionPayoutModal
          isOpen={showPayoutModal}
          onClose={() => { setShowPayoutModal(false); setPayoutAgent(null); }}
          onSuccess={() => {
            Alert.alert('Success', 'Commission payout recorded successfully!');
          }}
          agentId={payoutAgent.id}
          agentName={payoutAgent.team_name}
        />
      )}
    </View>
  );
};

export default TeamAgent;
