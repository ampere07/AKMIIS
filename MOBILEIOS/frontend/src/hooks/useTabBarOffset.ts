import { useSafeAreaInsets } from 'react-native-safe-area-context';

export const TAB_BAR_HEIGHT = 68;

const BASE_TAB_BAR_BOTTOM = 25;

export const useTabBarOffset = () => {
  const { bottom } = useSafeAreaInsets();
  const tabBarBottom = Math.max(BASE_TAB_BAR_BOTTOM, bottom);

  return { tabBarBottom, lift: tabBarBottom - BASE_TAB_BAR_BOTTOM };
};
