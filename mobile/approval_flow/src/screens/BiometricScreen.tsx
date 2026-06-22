// Biometric confirm — a bottom sheet over the (dimmed) detail. Approval is only
// recorded after authentication succeeds. Uses real Face ID / fingerprint via
// expo-local-authentication, falling back to a simulated confirm on devices with
// no enrolled biometrics (e.g. many simulators).

import React, { useState } from 'react';
import { View, Text, StyleSheet, Pressable, Platform, Alert } from 'react-native';
import * as LocalAuthentication from 'expo-local-authentication';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors } from '../theme/tokens';
import { Button } from '../components/ui';
import { FaceIcon } from '../components/Icons';
import { useApp } from '../state/AppContext';
import type { RootStackParamList, ResultSnapshot } from '../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;
type Rt = RouteProp<RootStackParamList, 'Biometric'>;

// Authenticate, falling back to a simulated success when the device has no
// enrolled biometrics so the prototype is demoable everywhere.
async function authenticate(): Promise<boolean> {
  try {
    const hasHardware = await LocalAuthentication.hasHardwareAsync();
    const enrolled = await LocalAuthentication.isEnrolledAsync();
    if (!hasHardware || !enrolled) return true; // simulated fallback
    const res = await LocalAuthentication.authenticateAsync({
      promptMessage: 'Confirm approval',
      cancelLabel: 'Cancel',
      disableDeviceFallback: false,
    });
    return res.success;
  } catch {
    return true; // never block the demo on an auth subsystem error
  }
}

export default function BiometricScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { getRequest, decide, decideMany } = useApp();
  const [busy, setBusy] = useState(false);

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

  const onConfirm = async () => {
    if (busy) return;
    setBusy(true);
    const ok = await authenticate();
    if (!ok) {
      setBusy(false);
      if (Platform.OS !== 'web') Alert.alert('Not confirmed', 'Authentication was cancelled or failed.');
      return;
    }
    if (bulk) {
      const result = await decideMany(ids, 'approved');
      setBusy(false);
      navigation.replace('Result', { kind: result.offline ? 'error' : 'approved', bulk: result.offline ? ids.length : result.count, ids });
    } else {
      const result = await decide(r!.id, 'approved');
      setBusy(false);
      navigation.replace('Result', { kind: result.offline ? 'error' : 'approved', id: r!.id, snap });
    }
  };

  return (
    <View style={styles.overlay}>
      <Pressable style={styles.backdrop} onPress={() => navigation.goBack()} />
      <View style={styles.sheet}>
        <View style={styles.face}>
          <FaceIcon size={30} color={colors.blue} />
        </View>
        <Text style={styles.h3}>{bulk ? 'Confirm bulk approval' : 'Confirm approval'}</Text>
        <Text style={styles.p}>Authenticate to approve</Text>
        <Text style={styles.big}>{label}</Text>
        <Text style={styles.p}>
          {bulk ? 'Disetujui sekaligus' : r!.requester}
          {!bulk && r!.forward ? ` · forwards to ${r!.forward}` : ''}
        </Text>
        <Button label="Confirm with Face ID" onPress={onConfirm} disabled={busy} style={styles.confirm} />
        <Button label="Cancel" variant="ghost" color={colors.sub} bg="transparent" borderColor="transparent" onPress={() => navigation.goBack()} style={styles.cancel} />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  overlay: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(15,23,42,0.55)' },
  backdrop: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 },
  sheet: {
    backgroundColor: colors.white,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    paddingHorizontal: 20,
    paddingTop: 24,
    paddingBottom: 34,
    alignItems: 'center',
  },
  face: { width: 58, height: 58, borderRadius: 16, borderWidth: 2.5, borderColor: colors.blue, alignItems: 'center', justifyContent: 'center', marginBottom: 13 },
  h3: { fontSize: 17, fontWeight: '700', color: colors.ink, marginBottom: 4 },
  p: { fontSize: 12.5, color: colors.sub, textAlign: 'center' },
  big: { fontSize: 22, fontWeight: '800', color: colors.ink, marginTop: 11, marginBottom: 2 },
  confirm: { marginTop: 16 },
  cancel: { marginTop: 4 },
});
