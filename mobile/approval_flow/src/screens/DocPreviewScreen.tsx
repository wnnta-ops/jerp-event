// Document preview — mock view of a supporting file (the app only displays files;
// in production they're pulled from the permission web where the requester uploaded).

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors } from '../theme/tokens';
import { Button } from '../components/ui';
import AppBar from '../components/AppBar';
import FileIcon from '../components/FileIcon';
import { useApp } from '../state/AppContext';
import type { RootStackParamList } from '../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;
type Rt = RouteProp<RootStackParamList, 'DocPreview'>;

export default function DocPreviewScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { getDocs } = useApp();
  const docs = getDocs(params.id);
  const doc = docs[params.index] ?? docs[0];

  if (!doc) {
    return (
      <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
        <AppBar title="Document" onBack={() => navigation.goBack()} />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <AppBar title={doc.name} onBack={() => navigation.goBack()} />

      <View style={styles.body}>
        <FileIcon type={doc.type} big />
        <Text style={styles.name}>{doc.name}</Text>
        <Text style={styles.size}>{doc.size} · diunggah requester</Text>
        <Text style={styles.note}>
          Preview mock. Di app asli, file ini ditarik dari web izin tempat requester upload.
        </Text>
      </View>

      <View style={styles.foot}>
        <View style={styles.footBtn}>
          <Button label="Tutup" variant="ghost" color={colors.ink} onPress={() => navigation.goBack()} />
        </View>
        <View style={styles.footBtn}>
          <Button label="Buka / Unduh" variant="solid" onPress={() => navigation.goBack()} />
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  body: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 24, backgroundColor: colors.slate100 },
  name: { fontSize: 14, fontWeight: '700', color: colors.ink, marginTop: 14, textAlign: 'center' },
  size: { fontSize: 11, color: colors.sub, marginTop: 3 },
  note: { fontSize: 11.5, color: colors.slate400, marginTop: 18, maxWidth: 230, textAlign: 'center', lineHeight: 17 },
  foot: { flexDirection: 'row', gap: 16, paddingHorizontal: 16, paddingVertical: 12, borderTopWidth: 1, borderTopColor: colors.line },
  footBtn: { flex: 1 },
});
