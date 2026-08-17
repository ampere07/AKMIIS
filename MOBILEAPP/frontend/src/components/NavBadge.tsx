import React from 'react';
import { View, Text } from 'react-native';

interface NavBadgeProps {
  count: number;
  /** Tighter geometry for the bottom bar, where the icon is smaller. */
  compact?: boolean;
}

/**
 * The red count that sits on a navigation icon.
 *
 * Absolutely positioned, so whatever renders it must be `position: 'relative'`.
 * Renders nothing at zero rather than an empty circle — a badge means "there is
 * something here", and one showing 0 says the opposite of what it looks like.
 */
const NavBadge: React.FC<NavBadgeProps> = ({ count, compact = false }) => {
  if (!count || count < 1) return null;

  const label = count > 99 ? '99+' : String(count);
  // Three glyphs need more room than one; without this "99+" is clipped by the
  // circle on narrower devices.
  const minWidth = label.length > 2 ? (compact ? 24 : 26) : (compact ? 16 : 18);

  return (
    <View
      style={{
        position: 'absolute',
        top: compact ? -4 : -6,
        right: compact ? -8 : -10,
        minWidth,
        height: compact ? 16 : 18,
        paddingHorizontal: 4,
        borderRadius: 9,
        backgroundColor: '#ef4444',
        alignItems: 'center',
        justifyContent: 'center',
        // Keeps the badge legible where it overlaps the icon beneath it.
        borderWidth: 1.5,
        borderColor: '#ffffff',
      }}
      pointerEvents="none"
    >
      <Text
        style={{
          color: '#ffffff',
          fontSize: compact ? 9 : 10,
          fontWeight: '700',
          lineHeight: compact ? 11 : 12,
        }}
        numberOfLines={1}
      >
        {label}
      </Text>
    </View>
  );
};

export default NavBadge;
