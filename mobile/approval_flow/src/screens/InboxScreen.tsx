// Approvals inbox — Needs you / Done tabs, loading skeleton on open, empty
// states, request cards. Supports multi-select (bulk approve / reject) on the
// Needs-you tab for approver roles.

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { View, Text, StyleSheet, ScrollView, Pressable, Animated } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation, useFocusEffect } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors, reqTypeColor } from '../theme/tokens';
import RequestCard from '../components/RequestCard';
import HistoryRow from '../components/HistoryRow';
import EmptyState from '../components/EmptyState';
import { SkeletonList } from '../components/Skeleton';
import { Button } from '../components/ui';
import { useApp } from '../state/AppContext';
import type { RootStackParamList, MainTabParamList } from '../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList & MainTabParamList>;
type TabKey = 'needs' | 'done';

export default function InboxScreen() {
  const navigation = useNavigation<Nav>();
  const { queue, role } = useApp();
  const [tab, setTab] = useState<TabKey>('needs');
  const [loading, setLoading] = useState(true);
  const [selecting, setSelecting] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());

  const canBulk = role !== 'Reviewer'; // reviewers act via Tandai sesuai / Minta revisi

  const exitSelect = useCallback(() => {
    setSelecting(false);
    setSelected(new Set());
  }, []);

  // Show the loading skeleton each time the inbox is opened, resetting to Needs you.
  useFocusEffect(
    useCallback(() => {
      setTab('needs');
      setLoading(true);
      setSelecting(false);
      setSelected(new Set());
      const t = setTimeout(() => setLoading(false), 550);
      return () => clearTimeout(t);
    }, []),
  );

  const tabs: { key: TabKey; label: string; count: number }[] = [
    { key: 'needs', label: 'Needs you', count: queue.needs.length },
    { key: 'done', label: 'Done', count: queue.done.length },
  ];

  const toggle = (id: string) =>
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const allSelected = queue.needs.length > 0 && selected.size === queue.needs.length;
  const toggleAll = () =>
    setSelected(allSelected ? new Set() : new Set(queue.needs.map((r) => r.id)));

  const ids = [...selected];
  const bulkApprove = () => {
    navigation.navigate('Biometric', { ids });
    exitSelect();
  };
  const bulkReject = () => {
    navigation.navigate('Reject', { ids });
    exitSelect();
  };

  // Quick cross-fade of the list when the active segment (or loading) changes.
  const fade = useRef(new Animated.Value(1)).current;
  useEffect(() => {
    fade.setValue(0);
    Animated.timing(fade, { toValue: 1, duration: 200, useNativeDriver: true }).start();
  }, [tab, loading, fade]);

  const showSelectBtn = canBulk && tab === 'needs' && !loading && queue.needs.length > 0;

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.head}>
        <View style={styles.titleRow}>
          <Text style={styles.title}>Approvals</Text>
          {showSelectBtn &&
            (selecting ? (
              <View style={styles.headActions}>
                <Pressable onPress={toggleAll} hitSlop={8}>
                  <Text style={styles.headAction}>{allSelected ? 'Hapus semua' : 'Pilih semua'}</Text>
                </Pressable>
                <Pressable onPress={exitSelect} hitSlop={8}>
                  <Text style={[styles.headAction, { color: colors.sub }]}>Batal</Text>
                </Pressable>
              </View>
            ) : (
              <Pressable onPress={() => setSelecting(true)} hitSlop={8}>
                <Text style={styles.headAction}>Pilih</Text>
              </Pressable>
            ))}
        </View>
        <View style={styles.tabs}>
          {tabs.map((t) => {
            const on = tab === t.key;
            return (
              <Pressable key={t.key} style={[styles.tab, on && styles.tabOn]} onPress={() => setTab(t.key)}>
                <Text style={[styles.tabText, on && styles.tabTextOn]}>
                  {t.label} · {t.count}
                </Text>
              </Pressable>
            );
          })}
        </View>

        {tab === 'needs' && !loading && (
          <View style={styles.legend}>
            {(Object.keys(reqTypeColor) as string[]).map((name) => (
              <View key={name} style={styles.legendItem}>
                <View style={[styles.legendDot, { backgroundColor: reqTypeColor[name] }]} />
                <Text style={styles.legendText}>{name}</Text>
              </View>
            ))}
          </View>
        )}
      </View>

      <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
        <Animated.View style={{ opacity: fade }}>
          {loading ? (
            <SkeletonList count={3} />
          ) : tab === 'needs' ? (
            queue.needs.length === 0 ? (
              <EmptyState title="All caught up" message="No requests waiting on you right now." />
            ) : (
              queue.needs.map((r) => (
                <RequestCard
                  key={r.id}
                  request={r}
                  selecting={selecting}
                  selected={selected.has(r.id)}
                  onPress={() => (selecting ? toggle(r.id) : navigation.navigate('Detail', { id: r.id }))}
                />
              ))
            )
          ) : queue.done.length === 0 ? (
            <EmptyState title="No decisions yet" message="Approvals and rejections you make appear here." />
          ) : (
            queue.done.map((d, i) => (
              <HistoryRow key={i} entry={d} onPress={() => navigation.navigate('HistoryDetail', { entry: d })} />
            ))
          )}
        </Animated.View>
      </ScrollView>

      {selecting && selected.size > 0 && (
        <View style={styles.actionBar}>
          <Text style={styles.actionCount}>{selected.size} dipilih</Text>
          <View style={styles.actionBtn}>
            <Button label="Tolak" variant="ghost" onPress={bulkReject} />
          </View>
          <View style={styles.actionBtn}>
            <Button label={`Setujui (${selected.size})`} variant="solid" onPress={bulkApprove} />
          </View>
        </View>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  head: { paddingHorizontal: 16, paddingTop: 8 },
  titleRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 9 },
  title: { fontSize: 18, fontWeight: '800', color: colors.ink },
  headActions: { flexDirection: 'row', gap: 16, alignItems: 'center' },
  headAction: { fontSize: 13, fontWeight: '700', color: colors.blue },
  tabs: { flexDirection: 'row', borderBottomWidth: 1, borderBottomColor: colors.line },
  tab: { paddingTop: 8, paddingBottom: 10, marginRight: 9, borderBottomWidth: 2, borderBottomColor: 'transparent' },
  tabOn: { borderBottomColor: colors.blue },
  tabText: { fontSize: 11.5, fontWeight: '700', color: colors.slate400 },
  tabTextOn: { color: colors.blue },
  legend: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, paddingTop: 10, paddingBottom: 2 },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  legendDot: { width: 9, height: 9, borderRadius: 3 },
  legendText: { fontSize: 10.5, color: colors.sub, fontWeight: '600' },
  scroll: { flex: 1 },
  scrollContent: { paddingHorizontal: 16, paddingTop: 12, paddingBottom: 16 },
  actionBar: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 16, paddingVertical: 10, borderTopWidth: 1, borderTopColor: colors.line, backgroundColor: colors.white },
  actionCount: { fontSize: 12.5, fontWeight: '700', color: colors.ink },
  actionBtn: { flex: 1 },
});
