// Result — confirmation for approved / rejected / offline-error.
//  • approved: synced + released/granted (or forwarded to next head for L2)
//  • rejected: reason + returned to requester
//  • error:    offline, decision saved locally, with Retry / Turn off offline & retry

import React, { useState } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation, useRoute, RouteProp, CommonActions } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors } from '../theme/tokens';
import { Button } from '../components/ui';
import { CheckBig, CheckSmall, XIcon, WarnIcon } from '../components/Icons';
import { useApp } from '../state/AppContext';
import type { RootStackParamList } from '../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;
type Rt = RouteProp<RootStackParamList, 'Result'>;

export default function ResultScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { kind, snap, reason, bulk } = params;
  const { queue, decide, decideMany, toggleOffline, offline } = useApp();
  const [busy, setBusy] = useState(false);

  const left = queue.needs.length;
  const isFund = snap?.type === 'FUND';

  const backToQueue = () => {
    navigation.dispatch(
      CommonActions.reset({ index: 0, routes: [{ name: 'Main', state: { routes: [{ name: 'Approvals' }] } }] }),
    );
  };

  // Error-screen retry: re-attempt the approval. If still offline, stay on error.
  const retry = async (turnOffOffline: boolean) => {
    if (busy) return;
    setBusy(true);
    if (turnOffOffline && offline) toggleOffline();
    if (params.ids && params.ids.length) {
      const result = await decideMany(params.ids, 'approved');
      setBusy(false);
      navigation.replace('Result', { kind: result.offline ? 'error' : 'approved', bulk: result.offline ? params.ids.length : result.count, ids: params.ids });
    } else {
      const result = await decide(params.id ?? '', 'approved');
      setBusy(false);
      navigation.replace('Result', { kind: result.offline ? 'error' : 'approved', id: params.id, snap });
    }
  };

  if (kind === 'error') {
    return (
      <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
        <View style={styles.body}>
          <View style={[styles.bigic, { backgroundColor: colors.amberBg }]}>
            <WarnIcon size={34} color={colors.amber} />
          </View>
          <Text style={[styles.h2, { color: colors.amber }]}>Couldn’t sync</Text>
          <Text style={styles.meta}>You’re offline — the decision wasn’t sent.</Text>
          <View style={styles.statuses}>
            <View style={styles.sl2}>
              <Text style={[styles.sl2text, { color: colors.amber }]}>Your approval is saved on this device and will retry.</Text>
            </View>
          </View>
        </View>
        <View style={styles.footPad}>
          <Button label="Retry" variant="solid" onPress={() => retry(false)} disabled={busy} style={{ marginBottom: 8 }} />
          <Button label="Turn off offline & retry" variant="ghost" color={colors.ink} onPress={() => retry(true)} disabled={busy} />
        </View>
      </SafeAreaView>
    );
  }

  const positive = kind === 'approved' || kind === 'reviewed';
  const title =
    kind === 'approved' ? 'Approved' : kind === 'reviewed' ? 'Dokumen sesuai' : kind === 'revision' ? 'Minta revisi' : 'Rejected';

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <View style={styles.body}>
        <View style={[styles.bigic, { backgroundColor: positive ? colors.greenBg : colors.redBg }]}>
          {positive ? <CheckBig size={38} color={colors.green} /> : <XIcon size={34} color={colors.red} />}
        </View>
        <Text style={[styles.h2, { color: positive ? colors.green : colors.red }]}>{title}</Text>
        <Text style={styles.meta}>{bulk ? `${bulk} permohonan` : `${snap?.label} · ${snap?.requester}`}</Text>

        <View style={styles.statuses}>
          {bulk ? (
            positive ? (
              <>
                <StatusLine text={`${bulk} permohonan disetujui`} />
                <StatusLine text="Disinkronkan ke sistem internal" />
                <StatusLine text="Requester diberi tahu" />
              </>
            ) : (
              <>
                <StatusLine text={`${bulk} permohonan ditolak`} />
                <StatusLine text={`Alasan: ${reason || 'Sent to requester'}`} />
                <StatusLine text="Dikembalikan ke requester" />
              </>
            )
          ) : kind === 'approved' ? (
            <>
              {snap?.forward ? (
                <>
                  <StatusLine text="Synced to website" />
                  <StatusLine text={`Forwarded to ${snap.forward} (final)`} />
                  <StatusLine text="Requester notified" />
                </>
              ) : (
                <>
                  <StatusLine text="Synced to website" />
                  <StatusLine text={isFund ? 'Fund released' : 'Access granted'} />
                  <StatusLine text="Requester notified" />
                </>
              )}
            </>
          ) : kind === 'reviewed' ? (
            <>
              <StatusLine text="Dokumen ditandai sesuai standar" />
              <StatusLine text="Diteruskan ke approver (Dept Head / GM)" />
              <StatusLine text="Requester diberi tahu" />
            </>
          ) : kind === 'revision' ? (
            <>
              <StatusLine text="Dikembalikan ke requester untuk perbaiki dokumen" />
              <StatusLine text={`Catatan: ${reason || 'Dokumen perlu diperbaiki'}`} />
              <StatusLine text="Sinkron ke web izin" />
            </>
          ) : (
            <>
              <StatusLine text={`Reason: ${reason || 'Sent to requester'}`} />
              <StatusLine text="Returned to requester to revise" />
              <StatusLine text="Synced to website" />
            </>
          )}
        </View>
      </View>

      <View style={styles.footPad}>
        <Button
          label={`${positive || kind === 'revision' ? 'Kembali ke antrian' : 'Back to queue'} · ${left} left`}
          variant="soft"
          onPress={backToQueue}
        />
      </View>
    </SafeAreaView>
  );
}

function StatusLine({ text }: { text: string }) {
  return (
    <View style={styles.sl2}>
      <CheckSmall size={15} color={colors.green} />
      <Text style={styles.sl2text}>{text}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  body: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 26 },
  bigic: { width: 78, height: 78, borderRadius: 39, alignItems: 'center', justifyContent: 'center', marginBottom: 16 },
  h2: { fontSize: 22, fontWeight: '800', marginBottom: 4 },
  meta: { fontSize: 13, color: colors.sub, marginBottom: 18, textAlign: 'center' },
  statuses: { width: '100%' },
  sl2: { flexDirection: 'row', alignItems: 'center', gap: 9, paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: colors.line },
  sl2text: { fontSize: 12.5, color: colors.ink, flexShrink: 1 },
  footPad: { padding: 16 },
});
