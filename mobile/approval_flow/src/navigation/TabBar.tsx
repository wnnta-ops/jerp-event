// Custom bottom tab bar (.tabbar) — small icon + label, blue when active,
// red count badge on Approvals (the "Needs you" count).

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { colors } from '../theme/tokens';
import { useApp } from '../state/AppContext';
import { HomeIcon, ApprovalsIcon, HistoryIcon, ProfileIcon } from '../components/Icons';

const ICONS = {
  Home: HomeIcon,
  Approvals: ApprovalsIcon,
  History: HistoryIcon,
  Profile: ProfileIcon,
} as const;

const LABELS = {
  Home: 'Home',
  Approvals: 'Approvals',
  History: 'History',
  Profile: 'Profile',
} as const;

export default function TabBar({ state, navigation }: BottomTabBarProps) {
  const insets = useSafeAreaInsets();
  const { queue } = useApp();
  const needs = queue.needs.length;

  return (
    <View style={[styles.bar, { paddingBottom: Math.max(insets.bottom, 6) }]}>
      {state.routes.map((route, index) => {
        const focused = state.index === index;
        const name = route.name as keyof typeof ICONS;
        const Icon = ICONS[name];
        const color = focused ? colors.blue : colors.slate400;
        const showBadge = name === 'Approvals' && needs > 0;

        const onPress = () => {
          const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
          if (!focused && !event.defaultPrevented) navigation.navigate(route.name);
        };

        return (
          <Pressable key={route.key} style={styles.tab} onPress={onPress}>
            <View>
              <Icon size={18} color={color} />
              {showBadge && (
                <View style={styles.badge}>
                  <Text style={styles.badgeText}>{needs}</Text>
                </View>
              )}
            </View>
            <Text style={[styles.label, { color }]}>{LABELS[name]}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    flexDirection: 'row',
    borderTopWidth: 1,
    borderTopColor: colors.line,
    backgroundColor: colors.white,
    paddingTop: 9,
  },
  tab: { flex: 1, alignItems: 'center', gap: 3 },
  label: { fontSize: 9.5, fontWeight: '600' },
  badge: {
    position: 'absolute',
    top: -6,
    left: 12,
    backgroundColor: colors.red,
    borderRadius: 999,
    paddingHorizontal: 4,
    minWidth: 14,
    height: 14,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { color: colors.white, fontSize: 8, fontWeight: '800' },
});
