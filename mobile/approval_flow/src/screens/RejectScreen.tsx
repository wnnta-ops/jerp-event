// Reject — preset reason chips (toggle) + free-text note. Note takes priority,
// then the selected chip, else a default. Records the rejection via the service.

import React, { useState } from 'react';
import { View, Text, StyleSheet, ScrollView, Pressable, TextInput, KeyboardAvoidingView, Platform } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors, radius } from '../theme/tokens';
import { Button, SectionLabel } from '../components/ui';
import AppBar from '../components/AppBar';
import { useApp } from '../state/AppContext';
import type { RootStackParamList, ResultSnapshot } from '../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;
type Rt = RouteProp<RootStackParamList, 'Reject'>;

export default function RejectScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { getRequest, decide, review, decideMany, reasons, revisionReasons } = useApp();
  const [selected, setSelected] = useState<string>('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);

  const isRevision = params.mode === 'revision';
  const chipReasons = isRevision ? revisionReasons : reasons;

  const ids = params.ids ?? [];
  const bulk = ids.length > 0;
  const r = bulk ? undefined : getRequest(params.id ?? '');
  if (!bulk && !r) {
    navigation.goBack();
    return null;
  }

  const isFund = r?.type === 'FUND';
  const label = bulk ? `${ids.length} permohonan` : isFund ? r!.amount! : r!.title!;
  const snap: ResultSnapshot | undefined = r
    ? { type: r.type, label, requester: r.requester, forward: r.forward }
    : undefined;

  const onSubmit = async () => {
    if (busy) return;
    setBusy(true);
    if (bulk) {
      const reason = note.trim() || selected || 'Sent to requester';
      const result = await decideMany(ids, 'rejected', reason);
      setBusy(false);
      navigation.replace('Result', { kind: 'rejected', reason, bulk: result.count });
    } else if (isRevision) {
      const reason = note.trim() || selected || 'Dokumen perlu diperbaiki';
      await review(r!.id, false, reason);
      setBusy(false);
      navigation.replace('Result', { kind: 'revision', id: r!.id, reason, snap });
    } else {
      const reason = note.trim() || selected || 'Sent to requester';
      await decide(r!.id, 'rejected', reason);
      setBusy(false);
      navigation.replace('Result', { kind: 'rejected', id: r!.id, reason, snap });
    }
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <AppBar title={isRevision ? 'Minta revisi' : bulk ? `Tolak ${ids.length} permohonan` : 'Reject request'} onBack={() => navigation.goBack()} border={false} />
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent} keyboardShouldPersistTaps="handled">
          <Text style={styles.lead}>
            {isRevision ? 'Minta revisi dokumen ' : bulk ? 'Menolak ' : 'Rejecting '}
            <Text style={styles.leadBold}>{label}</Text>
            {bulk ? ' sekaligus.' : (
              <>
                {' '}
                {isRevision ? 'ke' : 'from'} {r!.requester}.
              </>
            )}
          </Text>

          <SectionLabel style={styles.sect}>{isRevision ? 'Alasan revisi (wajib)' : 'Reason (required)'}</SectionLabel>
          <View style={styles.chips}>
            {chipReasons.map((x) => {
              const on = selected === x;
              return (
                <Pressable key={x} style={[styles.chip, on && styles.chipOn]} onPress={() => setSelected(on ? '' : x)}>
                  <Text style={[styles.chipText, on && styles.chipTextOn]}>{x}</Text>
                </Pressable>
              );
            })}
          </View>

          <TextInput
            style={styles.ta}
            placeholder={isRevision ? 'Catatan revisi untuk requester…' : 'Add a note for the requester…'}
            placeholderTextColor={colors.slate400}
            multiline
            value={note}
            onChangeText={setNote}
            textAlignVertical="top"
          />
        </ScrollView>

        <View style={styles.foot}>
          <View style={styles.footBtn}>
            <Button label="Cancel" variant="ghost" color={colors.sub} onPress={() => navigation.goBack()} />
          </View>
          <View style={styles.footBtn}>
            <Button label={isRevision ? 'Kirim revisi' : 'Submit rejection'} variant="solid" bg={isRevision ? colors.amber : colors.red} onPress={onSubmit} disabled={busy} />
          </View>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  flex: { flex: 1 },
  scroll: { flex: 1 },
  scrollContent: { paddingHorizontal: 16, paddingBottom: 16 },
  lead: { fontSize: 13, color: colors.sub, marginTop: 6, marginBottom: 2 },
  leadBold: { color: colors.ink, fontWeight: '700' },
  sect: { marginTop: 12, marginBottom: 8 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 },
  chip: { borderWidth: 1.5, borderColor: colors.line, borderRadius: radius.pill, paddingHorizontal: 12, paddingVertical: 7 },
  chipOn: { borderColor: colors.blue, backgroundColor: colors.blueBg },
  chipText: { fontSize: 12, fontWeight: '600', color: colors.sub },
  chipTextOn: { color: colors.blueDark },
  ta: { borderWidth: 1.5, borderColor: colors.line, borderRadius: 12, padding: 11, fontSize: 13, height: 90, color: colors.ink },
  foot: { flexDirection: 'row', gap: 16, paddingHorizontal: 16, paddingVertical: 12, borderTopWidth: 1, borderTopColor: colors.line },
  footBtn: { flex: 1 },
});
