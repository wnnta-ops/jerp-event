// Empty state (.empty) — green circle with a check, title and subtitle.

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors } from '../theme/tokens';
import { CheckSmall } from './Icons';

export default function EmptyState({ title, message }: { title: string; message: string }) {
  return (
    <View style={styles.empty}>
      <View style={styles.circle}>
        <CheckSmall size={20} color={colors.green} />
      </View>
      <Text style={styles.h}>{title}</Text>
      <Text style={styles.p}>{message}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  empty: { alignItems: 'center', paddingVertical: 46, paddingHorizontal: 24 },
  circle: { width: 56, height: 56, borderRadius: 28, backgroundColor: colors.greenBg, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  h: { fontSize: 14, color: colors.ink, marginBottom: 4, fontWeight: '700' },
  p: { fontSize: 12, color: colors.sub, textAlign: 'center' },
});
