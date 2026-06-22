// Top app bar (.appbar) for full-screen pushed views — back chevron, title,
// and an optional right-hand slot (e.g. the SLA pill on Detail).

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { colors } from '../theme/tokens';

interface Props {
  title: string;
  onBack?: () => void;
  right?: React.ReactNode;
  border?: boolean;
}

export default function AppBar({ title, onBack, right, border = true }: Props) {
  return (
    <View style={[styles.bar, border && styles.border]}>
      {onBack && (
        <Pressable hitSlop={10} onPress={onBack}>
          <Text style={styles.back}>‹</Text>
        </Pressable>
      )}
      <Text style={styles.title}>{title}</Text>
      {right ? <View style={styles.right}>{right}</View> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  bar: { flexDirection: 'row', alignItems: 'center', gap: 9, paddingHorizontal: 16, paddingTop: 7, paddingBottom: 11 },
  border: { borderBottomWidth: 1, borderBottomColor: colors.line },
  back: { fontSize: 26, color: colors.sub, lineHeight: 26, marginTop: -2 },
  title: { fontSize: 14, fontWeight: '700', color: colors.ink },
  right: { marginLeft: 'auto' },
});
