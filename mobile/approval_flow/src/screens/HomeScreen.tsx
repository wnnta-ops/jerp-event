// Home — greeting + role, "Awaiting you / Urgent now" stat cards, menu tiles,
// and a top-priority peek (or an all-caught-up empty state).

import React from 'react';
import { View, Text, StyleSheet, ScrollView, Pressable } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors, radius, headerTop, gradient } from '../theme/tokens';
import { Avatar, SectionLabel } from '../components/ui';
import { ApprovalsIcon, HistoryIcon, CheckSmall } from '../components/Icons';
import RequestCard from '../components/RequestCard';
import { useApp } from '../state/AppContext';
import { deadlineBucket } from '../utils/sla';
import type { RootStackParamList, MainTabParamList } from '../navigation/types';

// Composite type: tab switches resolve in the tab navigator, Detail bubbles to root.
type Nav = NativeStackNavigationProp<RootStackParamList & MainTabParamList>;

export default function HomeScreen() {
  const navigation = useNavigation<Nav>();
  const { profile, queue } = useApp();
  const needs = queue.needs.length;
  const urgent = queue.needs.filter((r) => deadlineBucket(r.slaMin) === 'red').length;
  // Soonest deadline first (no-deadline items last), show a short preview on Home.
  const priority = [...queue.needs]
    .sort((a, b) => (a.slaMin ?? Infinity) - (b.slaMin ?? Infinity))
    .slice(0, 4);

  const goApprovals = () => navigation.navigate('Approvals');
  const goHistory = () => navigation.navigate('History');

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.hhead}>
        <Avatar initial={profile.init} size="lg" />
        <View>
          <Text style={styles.greetG}>Selamat pagi,</Text>
          <View style={styles.greetRow}>
            <Text style={styles.greetN}>{profile.name}</Text>
            <View style={styles.roleChip}>
              <Text style={styles.roleChipText}>{profile.role}</Text>
            </View>
          </View>
        </View>
      </View>

      <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
        <View style={styles.cards}>
          <Pressable style={styles.cardWrap} onPress={goApprovals}>
            <LinearGradient colors={gradient(colors.blue)} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.scard}>
              <Text style={styles.scardNum}>{needs}</Text>
              <Text style={styles.scardLab}>Awaiting you</Text>
            </LinearGradient>
          </Pressable>
          <View style={styles.cardWrap}>
            <LinearGradient colors={gradient(colors.red)} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.scard}>
              <Text style={styles.scardNum}>{urgent}</Text>
              <Text style={styles.scardLab}>Urgent now</Text>
            </LinearGradient>
          </View>
        </View>

        <SectionLabel style={styles.sect}>Menu</SectionLabel>
        <View style={styles.tiles}>
          <Tile icon={<ApprovalsIcon size={18} color={colors.blue} />} tint="b" label="Approvals" badge={needs} onPress={goApprovals} />
          <Tile icon={<HistoryIcon size={18} color={colors.green} />} tint="g" label="History" onPress={goHistory} />
        </View>

        {priority.length > 0 ? (
          <>
            <View style={styles.sectRow}>
              <SectionLabel style={styles.sectFlush}>Top priority</SectionLabel>
              {needs > priority.length && (
                <Pressable onPress={goApprovals} hitSlop={8}>
                  <Text style={styles.seeAll}>Lihat semua ({needs})</Text>
                </Pressable>
              )}
            </View>
            {priority.map((r) => (
              <RequestCard key={r.id} request={r} onPress={() => navigation.navigate('Detail', { id: r.id })} />
            ))}
          </>
        ) : (
          <View style={styles.empty}>
            <View style={styles.emptyCircle}>
              <CheckSmall size={20} color={colors.green} />
            </View>
            <Text style={styles.emptyH}>All caught up</Text>
            <Text style={styles.emptyP}>Nothing awaiting you.</Text>
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const TINT: Record<string, string> = { b: colors.blueBg, g: colors.greenBg, p: colors.purpleBg, s: colors.slate100 };

function Tile({
  icon, tint, label, badge, onPress,
}: { icon: React.ReactNode; tint: string; label: string; badge?: number; onPress?: () => void }) {
  return (
    <Pressable style={styles.tile} onPress={onPress}>
      <View style={[styles.tic, { backgroundColor: TINT[tint] }]}>{icon}</View>
      <Text style={styles.tileLabel}>{label}</Text>
      {badge ? (
        <View style={styles.tileBadge}>
          <Text style={styles.tileBadgeText}>{badge}</Text>
        </View>
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  hhead: { flexDirection: 'row', alignItems: 'center', gap: 11, paddingHorizontal: 16, paddingTop: headerTop, paddingBottom: 12 },
  greetG: { fontSize: 11.5, color: colors.sub },
  greetRow: { flexDirection: 'row', alignItems: 'center' },
  greetN: { fontSize: 16, fontWeight: '800', color: colors.ink },
  roleChip: { backgroundColor: colors.blueBg, borderRadius: radius.pill, paddingHorizontal: 7, paddingVertical: 2, marginLeft: 6 },
  roleChipText: { fontSize: 10, fontWeight: '700', color: colors.blueDark },

  scroll: { flex: 1 },
  scrollContent: { paddingHorizontal: 16, paddingBottom: 16 },

  cards: { flexDirection: 'row', gap: 16, marginBottom: 16 },
  cardWrap: { flex: 1 },
  scard: { borderRadius: 15, padding: 13 },
  scardNum: { fontSize: 28, fontWeight: '800', color: colors.white, lineHeight: 30 },
  scardLab: { fontSize: 11, color: colors.white, opacity: 0.92, marginTop: 4 },

  sect: { marginTop: 8, marginBottom: 9 },
  sectRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 8, marginBottom: 9 },
  sectFlush: { marginTop: 0, marginBottom: 0 },
  seeAll: { fontSize: 12, fontWeight: '700', color: colors.blue },
  tiles: { flexDirection: 'row', flexWrap: 'wrap', gap: 16, marginBottom: 16 },
  tile: {
    flexBasis: '47%',
    flexGrow: 1,
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: 13,
    padding: 12,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 9,
  },
  tic: { width: 30, height: 30, borderRadius: 9, alignItems: 'center', justifyContent: 'center' },
  tileLabel: { fontSize: 12.5, fontWeight: '700', color: colors.ink },
  tileBadge: { marginLeft: 'auto', backgroundColor: colors.red, borderRadius: radius.pill, paddingHorizontal: 7, paddingVertical: 1 },
  tileBadgeText: { color: colors.white, fontSize: 10, fontWeight: '800' },

  empty: { alignItems: 'center', paddingVertical: 46, paddingHorizontal: 24 },
  emptyCircle: { width: 56, height: 56, borderRadius: 28, backgroundColor: colors.greenBg, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  emptyH: { fontSize: 14, color: colors.ink, marginBottom: 4, fontWeight: '700' },
  emptyP: { fontSize: 12, color: colors.sub },
});
