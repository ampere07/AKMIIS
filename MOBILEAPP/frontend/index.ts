import { registerRootComponent } from 'expo';

// Registers the technician background-location task. This import MUST stay here, above
// the App import: TaskManager.defineTask has to run during bundle evaluation, in true
// global scope, so the OS can hand positions to the task on a headless background
// relaunch (when no React tree is mounted). Relying on the App -> Dashboard -> hook
// import chain to pull it in works by accident and breaks the moment Dashboard is
// lazy-loaded or the hook moves.
import './src/services/locationTask';

import App from './App';

// registerRootComponent calls AppRegistry.registerComponent('main', () => App);
// It also ensures that whether you load the app in Expo Go or in a native build,
// the environment is set up appropriately
registerRootComponent(App);
