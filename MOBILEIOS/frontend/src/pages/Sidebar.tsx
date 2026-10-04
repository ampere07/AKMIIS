import React, { useState, useEffect, useRef } from 'react';
import { View, Text, Pressable, Animated, useWindowDimensions, DeviceEventEmitter } from 'react-native';
import { LayoutDashboard, ReceiptText, LifeBuoy, Menu as MenuIcon } from 'lucide-react-native';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { TAB_BAR_HEIGHT, useTabBarOffset } from '../hooks/useTabBarOffset';

interface SidebarProps {
  activeSection: string;
  onSectionChange: (section: string) => void;
}

interface TabItem {
  id: string;
  label: string;
  icon: React.ElementType;
}

/**
 * The customer's tabs, in the order the full app's tab bar lists them for a
 * customer: Dashboard, Bills, Support, Menu.
 *
 * The full app derives its bar from the permission table for every role, with
 * an expandable "More" grid for roles holding more than four entries. A
 * customer holds exactly these four, so the grid never appears for them and is
 * not carried over.
 */
const TABS: TabItem[] = [
  { id: 'customer-dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { id: 'customer-bills', label: 'Bills', icon: ReceiptText },
  { id: 'customer-support', label: 'Support', icon: LifeBuoy },
  { id: 'menu', label: 'Menu', icon: MenuIcon },
];

/**
 * Label typography for the bar. The block is two lines tall whatever the label
 * says, which is what keeps the icons on one baseline across a row.
 */
const LABEL_FONT_SIZE = 10;
const LABEL_LINE_HEIGHT = 12;
const LABEL_BLOCK_HEIGHT = LABEL_LINE_HEIGHT * 2;

/**
 * Split a label into exactly two lines, one word per line. A single word keeps
 * the second line as a non-breaking space so the block never collapses.
 */
const twoLineLabel = (label: string): string => {
  const words = String(label ?? '').trim().split(/\s+/).filter(Boolean);
  const [first = '', ...rest] = words;
  return `${first}\n${rest.join(' ') || ' '}`;
};

const Sidebar: React.FC<SidebarProps> = ({ activeSection, onSectionChange }) => {
  const [colorPalette, setColorPalette] = useState<ColorPalette | null>(() => settingsColorPaletteService.getActiveSync());
  const { width } = useWindowDimensions();
  const { tabBarBottom } = useTabBarOffset();
  const glideAnim = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    settingsColorPaletteService.getActive()
      .then(setColorPalette)
      .catch((err) => console.error('Failed to fetch color palette:', err));

    const paletteSub = DeviceEventEmitter.addListener('colorPaletteChanged', (newPalette) => {
      setColorPalette(newPalette);
    });

    return () => paletteSub.remove();
  }, []);

  // 'dashboard' is where a restored session lands before its home resolves,
  // and it renders the customer dashboard, so Dashboard is the tab to light.
  const activeTab = activeSection === 'dashboard' ? 'customer-dashboard' : activeSection;
  const activeIndex = TABS.findIndex(item => item.id === activeTab);

  useEffect(() => {
    if (activeIndex !== -1) {
      Animated.spring(glideAnim, {
        toValue: activeIndex,
        useNativeDriver: true,
        friction: 8,
        tension: 60
      }).start();
    }
  }, [activeIndex, glideAnim]);

  const primaryColor = colorPalette?.primary || '#7c3aed';
  const itemWidth = (width - 32 - 24) / TABS.length;

  return (
    <View style={{
      position: 'absolute',
      bottom: tabBarBottom,
      left: 16,
      right: 16,
      height: TAB_BAR_HEIGHT,
      backgroundColor: '#ffffff',
      borderRadius: 34,
      shadowColor: '#000',
      shadowOffset: { width: 0, height: 8 },
      shadowOpacity: 0.12,
      shadowRadius: 12,
      borderWidth: 1,
      borderColor: '#f1f5f9',
      zIndex: 1000,
      overflow: 'hidden',
      justifyContent: 'flex-end',
    }}>
      <View
        accessibilityRole="tablist"
        style={{
          height: TAB_BAR_HEIGHT,
          flexDirection: 'row',
          justifyContent: 'space-around',
          alignItems: 'center',
          paddingHorizontal: 12,
        }}
      >
        {/* Gliding Pill Indicator */}
        {activeIndex !== -1 && (
          <Animated.View
            style={{
              position: 'absolute',
              height: 48,
              width: itemWidth - 8,
              backgroundColor: primaryColor + '15',
              borderRadius: 24,
              transform: [{
                translateX: glideAnim.interpolate({
                  inputRange: TABS.map((_, i) => i),
                  outputRange: TABS.map((_, i) => (i * itemWidth) + 12 + 4),
                })
              }],
              left: 0,
            }}
          />
        )}

        {TABS.map((item) => {
          const isActive = activeTab === item.id;
          const IconComponent = item.icon;
          return (
            <Pressable
              key={item.id}
              onPress={() => onSectionChange(item.id)}
              accessibilityRole="tab"
              accessibilityLabel={item.label}
              aria-selected={isActive}
              style={{
                flex: 1,
                alignItems: 'center',
                justifyContent: 'center',
                height: '100%',
                zIndex: 10,
              }}
            >
              <IconComponent size={22} color={isActive ? primaryColor : '#4b5563'} />
              <Text style={{
                width: '100%',
                textAlign: 'center',
                fontSize: LABEL_FONT_SIZE,
                lineHeight: LABEL_LINE_HEIGHT,
                height: LABEL_BLOCK_HEIGHT,
                marginTop: 4,
                fontWeight: isActive ? '700' : '500',
                color: isActive ? primaryColor : '#4b5563'
              }} numberOfLines={2}>
                {twoLineLabel(item.label)}
              </Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
};

export default Sidebar;
