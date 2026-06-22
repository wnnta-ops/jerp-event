// Root navigation: auth gate (Login) -> Main tabs, with full-screen pushes for
// Detail / Reject / Result and a transparent modal for the Biometric sheet.

import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import type { RootStackParamList } from './types';
import { useApp } from '../state/AppContext';
import MainTabs from './MainTabs';
import LoginScreen from '../screens/LoginScreen';
import DetailScreen from '../screens/DetailScreen';
import RejectScreen from '../screens/RejectScreen';
import ResultScreen from '../screens/ResultScreen';
import BiometricScreen from '../screens/BiometricScreen';
import HistoryDetailScreen from '../screens/HistoryDetailScreen';
import DocPreviewScreen from '../screens/DocPreviewScreen';
import BppScreen from '../screens/BppScreen';

const Stack = createNativeStackNavigator<RootStackParamList>();

export default function RootNavigator() {
  const { authed } = useApp();

  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      {!authed ? (
        <Stack.Screen name="Login" component={LoginScreen} />
      ) : (
        <>
          <Stack.Screen name="Main" component={MainTabs} />
          <Stack.Screen name="Detail" component={DetailScreen} />
          <Stack.Screen name="Reject" component={RejectScreen} />
          <Stack.Screen name="HistoryDetail" component={HistoryDetailScreen} />
          <Stack.Screen name="DocPreview" component={DocPreviewScreen} />
          <Stack.Screen name="Bpp" component={BppScreen} />
          <Stack.Screen name="Result" component={ResultScreen} options={{ gestureEnabled: false }} />
          <Stack.Screen
            name="Biometric"
            component={BiometricScreen}
            options={{ presentation: 'transparentModal', animation: 'fade' }}
          />
        </>
      )}
    </Stack.Navigator>
  );
}
