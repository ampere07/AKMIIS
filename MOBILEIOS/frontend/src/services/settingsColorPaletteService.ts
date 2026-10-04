import api from '../config/api';
import { requestCache } from '../utils/requestCache';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { DeviceEventEmitter } from 'react-native';

export interface ColorPalette {
  id: number;
  palette_name: string;
  primary: string;
  secondary: string;
  accent: string;
  status: 'active' | 'inactive';
  created_at?: string;
  updated_at?: string;
  updated_by?: string;
}

const STORAGE_KEY = 'active_color_palette';

/**
 * The organisation's active branding. Read-only here: palettes are managed from
 * the web portal's Settings page, which customers have no access to.
 */
export const settingsColorPaletteService = {
  getActive: async (): Promise<ColorPalette | null> => {
    // Try to get from cache first
    const cached = requestCache.getSync<ColorPalette>('color_palette_active', 30000);
    if (cached) return cached;

    // Try to get from storage next
    try {
      const stored = await AsyncStorage.getItem(STORAGE_KEY);
      if (stored) {
        const parsed = JSON.parse(stored);
        // Pre-fill cache but don't return yet if we want fresh data
        // For now, let's return stored data for speed
        requestCache.set('color_palette_active', parsed);
      }
    } catch (e) {
      console.error('Error reading palette from storage:', e);
    }

    return requestCache.get(
      'color_palette_active',
      async () => {
        const response = await api.get<ColorPalette | null>('/settings-color-palette/active');
        if (response.data) {
          await AsyncStorage.setItem(STORAGE_KEY, JSON.stringify(response.data));
          DeviceEventEmitter.emit('colorPaletteChanged', response.data);
        }
        return response.data;
      },
      30000
    );
  },

  getActiveSync: (): ColorPalette | null => {
    return requestCache.getSync('color_palette_active', 30000);
  },
};
