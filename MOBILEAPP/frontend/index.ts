import { registerRootComponent } from 'expo';

// Temporarily disabled for Play Store review — do not register the location task
// (technician live-location tracking is off until location permissions are re-added).
// import './src/services/locationTask';

import App from './App';

// registerRootComponent calls AppRegistry.registerComponent('main', () => App);
// It also ensures that whether you load the app in Expo Go or in a native build,
// the environment is set up appropriately
registerRootComponent(App);
