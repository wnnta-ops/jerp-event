// Request detail — fund and document variants, key-value rows, pipeline tracker.
// Approve/Reject footer is hidden (with a read-only banner) for in-chain items.

import React, { useState } from 'react';
import { View, Text, StyleSheet, ScrollView, Pressable } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors, radius, sla as slaTokens } from '../theme/tokens';
import { Button, TierChip, SectionLabel } from '../components/ui';
import Pipeline from '../components/Pipeline';
import AppBar from '../components/AppBar';
import DocRow from '../components/DocRow';
import { CheckBig } from '../components/Icons';
import { formatSla } from '../utils/sla';
import { useApp } from '../state/AppContext';
import type { RootStackParamList, ResultSnapshot } from '../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;
type Rt = RouteProp<RootStackParamList, 'Detail'>;

export default function DetailScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { getRequest, getDocs, getBrowsableDocs, addDoc, removeDoc, role, review } = useApp();
  const r = getRequest(params.id);
  const docs = getDocs(params.id);
  const readOnly = !!params.readOnly;
  const isReviewer = role === 'Reviewer';

  // Reviewer document management.
  const [browse, setBrowse] = useState(false); // browse-files picker open
  const [confirmDel, setConfirmDel] = useState<number | null>(null); // index pending delete

  const onReview = async (ok: boolean) => {
    if (!r) return;
    const snap: ResultSnapshot = {
      type: r.type,
      label: r.type === 'FUND' ? r.amount! : r.title!,
      requester: r.requester,
      forward: r.forward,
    };
    await review(r.id, ok);
    navigation.replace('Result', { kind: ok ? 'reviewed' : 'revision', id: r.id, snap });
  };

  // "Remind me later" sheet: closed -> choose (1/2/3 hours) -> reason -> done.
  const [remind, setRemind] = useState<'closed' | 'choose' | 'reason' | 'done'>('closed');
  const [remindHours, setRemindHours] = useState(1);
  const [remindReason, setRemindReason] = useState('');
  const REMIND_REASONS = ['In a meeting', 'Out of office', 'Other'];

  if (!r) {
    return (
      <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
        <AppBar title="Request" onBack={() => navigation.goBack()} />
      </SafeAreaView>
    );
  }

  const isFund = r.type === 'FUND';
  const s = slaTokens[r.slc];
  const slaText = r.slaMin == null ? 'In chain' : `${r.slc === 'red' ? '⏱ ' : ''}${formatSla(r.slaMin)}`;

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <AppBar
        title={isFund ? 'Fund request' : 'Document access'}
        onBack={() => navigation.goBack()}
        right={
          <View style={[styles.slaTop, { backgroundColor: s.bg }]}>
            <Text style={[styles.slaTopText, { color: s.fg }]}>{slaText}</Text>
          </View>
        }
      />

      <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
        <View style={styles.amt}>
          <View style={styles.amtHead}>
            <Text style={styles.amtK}>{isFund ? `${r.reqName ?? 'Fund usage'} · ` : 'Document access · '}</Text>
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

        {(docs.length > 0 || isReviewer) && (
          <>
            <SectionLabel style={styles.docsHeading}>Dokumen pendukung · {docs.length}</SectionLabel>
            {docs.map((d, i) => (
              <DocRow
                key={i}
                doc={d}
                onPress={() => navigation.navigate('DocPreview', { id: r.id, index: i })}
                onDelete={isReviewer ? () => setConfirmDel(i) : undefined}
              />
            ))}
            {isReviewer && (
              <Pressable style={({ pressed }) => [styles.addDoc, pressed && { opacity: 0.6 }]} onPress={() => setBrowse(true)}>
                <Text style={styles.addDocPlus}>＋</Text>
                <Text style={styles.addDocText}>Tambah dokumen pendukung</Text>
              </Pressable>
            )}
          </>
        )}

        {readOnly && (
          <View style={styles.roBanner}>
            <Text style={styles.roText}>Read-only — currently with another approver. You already acted on this.</Text>
          </View>
        )}

        <Pipeline chain={r.chain} />
      </ScrollView>

      {!readOnly && (
        <View style={styles.foot}>
          <Button label="Remind me later" variant="soft" onPress={() => setRemind('choose')} />
          {isReviewer ? (
            <View style={styles.footRow}>
              <View style={styles.footBtn}>
                <Button label="Minta revisi" variant="ghost" color={colors.amber} onPress={() => navigation.navigate('Reject', { id: r.id, mode: 'revision' })} />
              </View>
              <View style={styles.footBtn}>
                <Button label="Tandai sesuai" variant="solid" bg={colors.green} onPress={() => onReview(true)} />
              </View>
            </View>
          ) : (
            <View style={styles.footRow}>
              <View style={styles.footBtn}>
                <Button label="Reject" variant="ghost" onPress={() => navigation.navigate('Reject', { id: r.id })} />
              </View>
              <View style={styles.footBtn}>
                <Button label="Approve" variant="solid" onPress={() => navigation.navigate('Biometric', { id: r.id })} />
              </View>
            </View>
          )}
        </View>
      )}

      {remind !== 'closed' && (
        <View style={styles.overlay}>
          <Pressable style={styles.backdrop} onPress={() => setRemind('closed')} />
          <View style={styles.sheet}>
            {remind === 'choose' ? (
              <>
                <Text style={styles.sheetTitle}>Remind me later</Text>
                <Text style={styles.sheetSub}>Item tetap di antrian. Pilih kapan mau diingatkan.</Text>
                {[1, 2, 3].map((h) => (
                  <Pressable
                    key={h}
                    style={({ pressed }) => [styles.optRow, pressed && { backgroundColor: colors.blueBg }]}
                    onPress={() => {
                      setRemindHours(h);
                      setRemind('reason');
                    }}
                  >
                    <Text style={styles.optText}>Dalam {h} jam</Text>
                    <Text style={styles.optChev}>›</Text>
                  </Pressable>
                ))}
                <Button label="Batal" variant="ghost" color={colors.sub} bg="transparent" borderColor="transparent" onPress={() => setRemind('closed')} style={styles.sheetCancel} />
              </>
            ) : remind === 'reason' ? (
              <>
                <Text style={styles.sheetTitle}>Alasan</Text>
                <Text style={styles.sheetSub}>Kenapa ditunda {remindHours} jam?</Text>
                {REMIND_REASONS.map((reason) => (
                  <Pressable
                    key={reason}
                    style={({ pressed }) => [styles.optRow, pressed && { backgroundColor: colors.blueBg }]}
                    onPress={() => {
                      setRemindReason(reason);
                      setRemind('done');
                    }}
                  >
                    <Text style={styles.optText}>{reason}</Text>
                    <Text style={styles.optChev}>›</Text>
                  </Pressable>
                ))}
                <Button label="Kembali" variant="ghost" color={colors.sub} bg="transparent" borderColor="transparent" onPress={() => setRemind('choose')} style={styles.sheetCancel} />
              </>
            ) : (
              <View style={styles.doneWrap}>
                <View style={styles.doneIcon}>
                  <CheckBig size={34} color={colors.green} />
                </View>
                <Text style={styles.sheetTitle}>Diingatkan dalam {remindHours} jam</Text>
                <Text style={styles.sheetSub}>Alasan: {remindReason} · item tetap di antrian.</Text>
                <Button
                  label="Kembali ke antrian"
                  variant="solid"
                  style={styles.sheetDoneBtn}
                  onPress={() => {
                    setRemind('closed');
                    navigation.goBack();
                  }}
                />
              </View>
            )}
          </View>
        </View>
      )}

      {/* Browse-files picker (reviewer attaches a document) */}
      {browse && (
        <View style={styles.overlay}>
          <Pressable style={styles.backdrop} onPress={() => setBrowse(false)} />
          <View style={styles.sheet}>
            <Text style={styles.sheetTitle}>Pilih dokumen</Text>
            <Text style={styles.sheetSub}>Browse berkas untuk dilampirkan ke permohonan ini.</Text>
            {getBrowsableDocs().map((d, i) => (
              <DocRow
                key={i}
                doc={d}
                onPress={() => {
                  addDoc(r.id, d);
                  setBrowse(false);
                }}
              />
            ))}
            <Button label="Batal" variant="ghost" color={colors.sub} bg="transparent" borderColor="transparent" onPress={() => setBrowse(false)} style={styles.sheetCancel} />
          </View>
        </View>
      )}

      {/* Delete confirmation */}
      {confirmDel !== null && (
        <View style={styles.overlay}>
          <Pressable style={styles.backdrop} onPress={() => setConfirmDel(null)} />
          <View style={styles.sheet}>
            <Text style={styles.sheetTitle}>Hapus dokumen?</Text>
            <Text style={styles.sheetSub}>
              {docs[confirmDel]?.name} akan dihapus. Apakah anda yakin?
            </Text>
            <View style={styles.footRow}>
              <View style={styles.footBtn}>
                <Button label="Batal" variant="ghost" color={colors.ink} onPress={() => setConfirmDel(null)} />
              </View>
              <View style={styles.footBtn}>
                <Button
                  label="Hapus"
                  variant="solid"
                  bg={colors.red}
                  onPress={() => {
                    if (confirmDel !== null) removeDoc(r.id, confirmDel);
                    setConfirmDel(null);
                  }}
                />
              </View>
            </View>
          </View>
        </View>
      )}
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
  scroll: { flex: 1 },
  scrollContent: { paddingHorizontal: 16, paddingBottom: 16 },

  slaTop: { paddingHorizontal: 9, paddingVertical: 3, borderRadius: radius.pill },
  slaTopText: { fontSize: 10.5, fontWeight: '700' },

  amt: { backgroundColor: colors.slate100, borderWidth: 1, borderColor: colors.line, borderRadius: radius.lg, padding: 14, marginVertical: 12 },
  amtHead: { flexDirection: 'row', alignItems: 'center', marginBottom: 4 },
  amtK: { fontSize: 10.5, color: colors.sub, textTransform: 'uppercase', letterSpacing: 0.4 },
  amtV: { fontSize: 23, fontWeight: '800', color: colors.ink },
  amtVDoc: { fontSize: 18 },

  kv: { flexDirection: 'row', justifyContent: 'space-between', gap: 10, paddingVertical: 7, borderBottomWidth: 1, borderStyle: 'dashed', borderBottomColor: colors.line },
  kvK: { color: colors.sub, fontSize: 12.5 },
  kvV: { fontWeight: '600', textAlign: 'right', fontSize: 12.5, color: colors.ink, flexShrink: 1 },

  docsHeading: { marginTop: 16, marginBottom: 9 },
  addDoc: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1.5, borderStyle: 'dashed', borderColor: colors.blue, borderRadius: radius.md, paddingVertical: 12, justifyContent: 'center', marginBottom: 8, backgroundColor: colors.blueBg },
  addDocPlus: { fontSize: 16, fontWeight: '800', color: colors.blueDark },
  addDocText: { fontSize: 12.5, fontWeight: '700', color: colors.blueDark },
  roBanner: { backgroundColor: colors.blueBg, borderRadius: 10, paddingHorizontal: 12, paddingVertical: 9, marginTop: 12 },
  roText: { color: colors.blueDark, fontSize: 11.5, fontWeight: '600' },

  foot: { gap: 10, paddingHorizontal: 16, paddingVertical: 12, borderTopWidth: 1, borderTopColor: colors.line },
  footRow: { flexDirection: 'row', gap: 16 },
  footBtn: { flex: 1 },

  overlay: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, justifyContent: 'flex-end', backgroundColor: 'rgba(15,23,42,0.55)' },
  backdrop: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 },
  sheet: { backgroundColor: colors.white, borderTopLeftRadius: 24, borderTopRightRadius: 24, paddingHorizontal: 20, paddingTop: 22, paddingBottom: 30 },
  sheetTitle: { fontSize: 17, fontWeight: '800', color: colors.ink, textAlign: 'center' },
  sheetSub: { fontSize: 12.5, color: colors.sub, textAlign: 'center', marginTop: 4, marginBottom: 14 },
  optRow: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: radius.md, paddingHorizontal: 14, paddingVertical: 14, marginBottom: 10 },
  optText: { fontSize: 14, fontWeight: '700', color: colors.ink },
  optChev: { marginLeft: 'auto', fontSize: 20, color: colors.slate400, fontWeight: '700' },
  sheetCancel: { marginTop: 2 },
  doneWrap: { alignItems: 'center', paddingVertical: 6 },
  doneIcon: { width: 64, height: 64, borderRadius: 32, backgroundColor: colors.greenBg, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  sheetDoneBtn: { marginTop: 16 },
});
