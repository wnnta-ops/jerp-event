// File-type badge for supporting documents (PDF / XLS / IMG), small or large.

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors } from '../theme/tokens';
import type { DocType } from '../data/types';

const META: Record<DocType, { bg: string; fg: string; label: string }> = {
  pdf: { bg: colors.redBg, fg: colors.red, label: 'PDF' },
  xls: { bg: colors.greenBg, fg: colors.green, label: 'XLS' },
  img: { bg: colors.blueBg, fg: colors.blue, label: 'IMG' },
};

export default function FileIcon({ type, big = false }: { type: DocType; big?: boolean }) {
  const m = META[type];
  const dim = big ? 84 : 34;
  return (
    <View style={[styles.box, { width: dim, height: dim, borderRadius: big ? 16 : 8, backgroundColor: m.bg }]}>
      <Text style={[styles.label, { color: m.fg, fontSize: big ? 20 : 9 }]}>{m.label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { alignItems: 'center', justifyContent: 'center' },
  label: { fontWeight: '800' },
});
