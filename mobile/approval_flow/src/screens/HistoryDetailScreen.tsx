// History detail — a read-only tracker for a past decision. Shows the outcome
// (approved / rejected + reason), the request info, and the pipeline as it stands.

import React from 'react';
import { View, Text, StyleSheet, ScrollView } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors, radius, space } from '../theme/tokens';
import { TierChip, Button, SectionLabel } from '../components/ui';
import Pipeline from '../components/Pipeline';
import AppBar from '../components/AppBar';
import DocRow from '../components/DocRow';
import { CheckSmall } from '../components/Icons';
import type { RootStackParamList } from '../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;
type Rt = RouteProp<RootStackParamList, 'HistoryDetail'>;

export default function HistoryDetailScreen() {
  const navigation = useNavigation<Nav>();
  const { entry } = useRoute<Rt>().params;
  const approved = entry.dec === 'approved';
  const r = entry.request;
  const isFund = entry.type === 'FUND';
  const docs = r?.docs ?? [];

  const tone = approved ? colors.green : colors.red;
  const toneBg = approved ? colors.greenBg : colors.redBg;

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <AppBar title="Decision" onBack={() => navigation.goBack()} />

      <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
        {/* Outcome banner */}
        <View style={[styles.outcome, { backgroundColor: toneBg }]}>
          <View style={[styles.outcomeIcon, { backgroundColor: approved ? colors.green : colors.red }]}>
            <Text style={styles.outcomeMark}>{approved ? '✓' : '✕'}</Text>
          </View>
          <View style={styles.flex}>
            <Text style={[styles.outcomeTitle, { color: tone }]}>{approved ? 'Approved' : 'Rejected'}</Text>
            <Text style={styles.outcomeMeta}>
              {entry.label} · {entry.who} · {entry.time}
            </Text>
          </View>
        </View>

        {entry.dec === 'rejected' && entry.reason ? (
          <View style={styles.reason}>
            <Text style={styles.reasonLabel}>Reason</Text>
            <Text style={styles.reasonText}>{entry.reason}</Text>
          </View>
        ) : null}

        {r ? (
          <>
            <View style={styles.amt}>
              <View style={styles.amtHead}>
                <Text style={styles.amtK}>{isFund ? 'Fund usage · ' : 'Document access · '}</Text>
                <TierChip tier={r.tier} label={r.tag} />
              </View>
              <Text style={[styles.amtV, !isFund && styles.amtVDoc]}>{isFund ? r.amount : r.title}</Text>
            </View>

            {isFund ? (
              <>
                <KV k="Requester" v={`${r.requester} · ${r.dept}`} />
                <KV k="Purpose" v={r.purpose!} />
                <KV k="Budget line" v={r.budget!} />
                <KV k="Needed by (H)" v={r.need!} />
              </>
            ) : (
              <>
                <KV k="Requester" v={`${r.requester} · ${r.dept}`} />
                <KV k="Access level" v={r.access!} />
                <KV k="Classification" v={r.cls!} />
              </>
            )}

            {docs.length > 0 && (
              <>
                <SectionLabel style={styles.docsHeading}>Dokumen pendukung · {docs.length}</SectionLabel>
                {docs.map((d, i) => (
                  <DocRow
                    key={i}
                    doc={d}
                    onPress={() => entry.id && navigation.navigate('DocPreview', { id: entry.id, index: i })}
                  />
                ))}
              </>
            )}

            <Pipeline chain={r.chain} />
          </>
        ) : (
          <View style={styles.noTrack}>
            <CheckSmall size={16} color={colors.green} />
            <Text style={styles.noTrackText}>No further tracking details for this entry.</Text>
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function KV({ k, v }: { k: string; v: string }) {
  return (
    <View style={styles.kv}>
      <Text style={styles.kvK}>{k}</Text>
      <Text style={styles.kvV}>{v}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  flex: { flex: 1 },
  scroll: { flex: 1 },
  scrollContent: { paddingHorizontal: space.lg, paddingBottom: space.lg },

  outcome: { flexDirection: 'row', alignItems: 'center', gap: 11, borderRadius: radius.lg, padding: 13, marginVertical: 12 },
  outcomeIcon: { width: 34, height: 34, borderRadius: 17, alignItems: 'center', justifyContent: 'center' },
  outcomeMark: { color: colors.white, fontWeight: '800', fontSize: 16 },
  outcomeTitle: { fontSize: 16, fontWeight: '800' },
  outcomeMeta: { fontSize: 11.5, color: colors.sub, marginTop: 2 },

  reason: { backgroundColor: colors.slate100, borderRadius: radius.md, padding: 12, marginBottom: 4 },
  reasonLabel: { fontSize: 10.5, color: colors.sub, textTransform: 'uppercase', letterSpacing: 0.4, fontWeight: '700', marginBottom: 3 },
  reasonText: { fontSize: 13, color: colors.ink, fontWeight: '600' },

  amt: { backgroundColor: colors.slate100, borderWidth: 1, borderColor: colors.line, borderRadius: radius.lg, padding: 14, marginVertical: 12 },
  amtHead: { flexDirection: 'row', alignItems: 'center', marginBottom: 4 },
  amtK: { fontSize: 10.5, color: colors.sub, textTransform: 'uppercase', letterSpacing: 0.4 },
  amtV: { fontSize: 23, fontWeight: '800', color: colors.ink },
  amtVDoc: { fontSize: 18 },

  kv: { flexDirection: 'row', justifyContent: 'space-between', gap: 10, paddingVertical: 7, borderBottomWidth: 1, borderStyle: 'dashed', borderBottomColor: colors.line },
  kvK: { color: colors.sub, fontSize: 12.5 },
  kvV: { fontWeight: '600', textAlign: 'right', fontSize: 12.5, color: colors.ink, flexShrink: 1 },

  docsHeading: { marginTop: 16, marginBottom: 9 },
  foot: { paddingHorizontal: space.lg, paddingVertical: 12, borderTopWidth: 1, borderTopColor: colors.line },

  noTrack: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 18 },
  noTrackText: { fontSize: 12.5, color: colors.sub },
});
