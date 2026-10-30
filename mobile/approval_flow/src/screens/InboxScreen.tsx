// Approvals inbox — Needs you / Done tabs, loading skeleton on open, empty
// states, request cards. Supports multi-select (bulk approve / reject) on the
// Needs-you tab for approver roles.

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { View, Text, StyleSheet, ScrollView, Pressable, Animated, useWindowDimensions } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation, useFocusEffect } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors, reqTypeColor, headerTop, radius, moduleColor, moduleLabel } from '../theme/tokens';
import RequestCard from '../components/RequestCard';
import RequestTable from '../components/RequestTable';
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
  const [modFilter, setModFilter] = useState<string | null>(null); // active Javacore module filter

  const { width } = useWindowDimensions();
  const wide = width >= 760; // Javacore-style table on web/tablet, cards on phone

  const canBulk = role !== 'Reviewer'; // reviewers act via Tandai sesuai / Minta revisi

  // Distinct Javacore modules present in the Needs-you queue, with counts (for the
  // filter chips that double as the color legend). Empty for non-Javacore queues.
  const javaModules = useMemo(() => {
    const counts = new Map<string, number>();
    queue.needs.forEach((r) => {
      if (r.moduleKey) counts.set(r.moduleKey, (counts.get(r.moduleKey) ?? 0) + 1);
    });
    return [...counts.entries()].map(([key, count]) => ({ key, count }));
  }, [queue.needs]);

  const needsList = modFilter ? queue.needs.filter((r) => r.moduleKey === modFilter) : queue.needs;

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
      setModFilter(null);
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

  const allSelected = needsList.length > 0 && selected.size === needsList.length;
  const toggleAll = () =>
    setSelected(allSelected ? new Set() : new Set(needsList.map((r) => r.id)));

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
          <View style={styles.titleLeft}>
            <Text style={styles.title}>Approvals</Text>
            {tab === 'needs' && !loading && (
              <View style={styles.menunggu}>
                <Text style={styles.menungguText}>{queue.needs.length} menunggu</Text>
              </View>
            )}
          </View>
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

        {tab === 'needs' && !loading && javaModules.length > 0 && (
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.modRow}
          >
            <ModChip
              label="Semua"
              count={queue.needs.length}
              color={colors.ink}
              active={modFilter === null}
              onPress={() => {
                setModFilter(null);
                setSelected(new Set());
              }}
            />
            {javaModules.map((m) => (
              <ModChip
                key={m.key}
                label={moduleLabel(m.key)}
                count={m.count}
                color={moduleColor(m.key)}
                active={modFilter === m.key}
                onPress={() => {
                  setModFilter(m.key);
                  setSelected(new Set());
                }}
              />
            ))}
          </ScrollView>
        )}

        {tab === 'needs' && !loading && javaModules.length === 0 && (
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
            needsList.length === 0 ? (
              <EmptyState title="All caught up" message="No requests waiting on you right now." />
            ) : wide ? (
              <RequestTable
                rows={needsList}
                selecting={selecting}
                selected={selected}
                onToggle={toggle}
                onOpen={(id) => navigation.navigate('Detail', { id })}
              />
            ) : (
              needsList.map((r) => (
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

// A module filter chip that doubles as the color legend (dot + label + count).
function ModChip({
  label, count, color, active, onPress,
}: { label: string; count: number; color: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      style={[styles.modChip, active && { backgroundColor: color + '18', borderColor: color }]}
    >
      <View style={[styles.modDot, { backgroundColor: color }]} />
      <Text style={[styles.modChipText, active && { color }]} numberOfLines={1}>
        {label}
      </Text>
      <Text style={[styles.modCount, active && { color }]}>{count}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  head: { paddingHorizontal: 16, paddingTop: headerTop },
  titleRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 9 },
  titleLeft: { flexDirection: 'row', alignItems: 'center', gap: 9 },
  title: { fontSize: 18, fontWeight: '800', color: colors.ink },
  menunggu: { backgroundColor: colors.redBg, borderRadius: radius.pill, paddingHorizontal: 9, paddingVertical: 3 },
  menungguText: { fontSize: 11, fontWeight: '800', color: colors.red },
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

  modRow: { gap: 8, paddingTop: 10, paddingBottom: 2, paddingRight: 8 },
  modChip: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1, borderColor: colors.line, borderRadius: radius.pill, paddingHorizontal: 11, paddingVertical: 6, backgroundColor: colors.white },
  modDot: { width: 8, height: 8, borderRadius: 3 },
  modChipText: { fontSize: 11.5, fontWeight: '700', color: colors.sub, maxWidth: 130 },
  modCount: { fontSize: 11, fontWeight: '800', color: colors.slate400 },
  scroll: { flex: 1 },
  scrollContent: { paddingHorizontal: 16, paddingTop: 12, paddingBottom: 16 },
  actionBar: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 16, paddingVertical: 10, borderTopWidth: 1, borderTopColor: colors.line, backgroundColor: colors.white },
  actionCount: { fontSize: 12.5, fontWeight: '700', color: colors.ink },
  actionBtn: { flex: 1 },
});
