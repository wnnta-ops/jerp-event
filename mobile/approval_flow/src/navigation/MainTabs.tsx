// Bottom tab bar shown on Home / Approvals / History / Profile.
// Built out with the custom-styled tab bar (badge counts) in Step 1.

import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import type { MainTabParamList } from './types';
import TabBar from './TabBar';
import FadeInOnFocus from '../components/FadeInOnFocus';
import HomeScreen from '../screens/HomeScreen';
import InboxScreen from '../screens/InboxScreen';
import HistoryScreen from '../screens/HistoryScreen';
import ProfileScreen from '../screens/ProfileScreen';

const Tab = createBottomTabNavigator<MainTabParamList>();

// Wrap each tab screen so its content fades in on focus — smooth, consistent
// transitions on both native and web.
const withFade = (Screen: React.ComponentType) => () => (
  <FadeInOnFocus>
    <Screen />
  </FadeInOnFocus>
);

const Home = withFade(HomeScreen);
const Approvals = withFade(InboxScreen);
const History = withFade(HistoryScreen);
const Profile = withFade(ProfileScreen);

export default function MainTabs() {
  return (
    <Tab.Navigator screenOptions={{ headerShown: false }} tabBar={(props) => <TabBar {...props} />}>
      <Tab.Screen name="Home" component={Home} />
      <Tab.Screen name="Approvals" component={Approvals} />
      <Tab.Screen name="History" component={History} />
      <Tab.Screen name="Profile" component={Profile} />
    </Tab.Navigator>
  );
}
