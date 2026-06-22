// History / Done row (.hrow) — decision icon, label + who, decision + time.
// Tappable when onPress is provided (opens the read-only tracker).

import React from 'react';
import { View, Text, StyleSheet, Pressable } from 'react-native';
import { colors } from '../theme/tokens';
import { CheckSmall } from './Icons';
import type { HistoryEntry } from '../data/types';

export default function HistoryRow({ entry, onPress }: { entry: HistoryEntry; onPress?: () => void }) {
  const ok = entry.dec === 'approved';
  return (
    <Pressable
      onPress={onPress}
      disabled={!onPress}
      style={({ pressed }) => [styles.row, pressed && onPress ? styles.pressed : null]}
    >
      <View style={[styles.ic, { backgroundColor: ok ? colors.greenBg : colors.redBg }]}>
        {ok ? <CheckSmall size={15} color={colors.green} /> : <Text style={styles.x}>✕</Text>}
      </View>
      <View style={styles.mid}>
        <Text style={styles.label}>{entry.label}</Text>
        <Text style={styles.who}>{entry.who}</Text>
      </View>
      <View style={styles.right}>
        <Text style={[styles.dec, { color: ok ? colors.green : colors.red }]}>{entry.decLabel ?? entry.dec}</Text>
        <Text style={styles.time}>{entry.time || 'Just now'}</Text>
      </View>
      {onPress ? <Text style={styles.chevron}>›</Text> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 11, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: colors.line },
  pressed: { opacity: 0.6 },
  ic: { width: 34, height: 34, borderRadius: 9, alignItems: 'center', justifyContent: 'center' },
  x: { color: colors.red, fontWeight: '800', fontSize: 15 },
  mid: { flex: 1, minWidth: 0 },
  label: { fontSize: 13, fontWeight: '700', color: colors.ink },
  who: { fontSize: 11, color: colors.sub },
  right: { alignItems: 'flex-end' },
  dec: { fontSize: 10.5, fontWeight: '700', textTransform: 'capitalize' },
  time: { fontSize: 10.5, color: colors.sub },
  chevron: { fontSize: 22, color: colors.slate400, marginLeft: 2, marginTop: -2 },
});
