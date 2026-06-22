// Supporting-document row (.doc) — file badge, name + size, chevron. Tappable.
// When onDelete is provided (reviewer), a trash button shows on the right as a
// SIBLING of the tappable area (so tapping it doesn't also open the preview).

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { colors, radius } from '../theme/tokens';
import FileIcon from './FileIcon';
import { TrashIcon } from './Icons';
import type { SupportingDoc } from '../data/types';

export default function DocRow({ doc, onPress, onDelete }: { doc: SupportingDoc; onPress?: () => void; onDelete?: () => void }) {
  return (
    <View style={styles.row}>
      <Pressable onPress={onPress} style={({ pressed }) => [styles.main, pressed && { opacity: 0.6 }]}>
        <FileIcon type={doc.type} />
        <View style={styles.meta}>
          <Text style={styles.name} numberOfLines={1}>
            {doc.name}
          </Text>
          <Text style={styles.size}>{doc.size}</Text>
        </View>
        {!onDelete && <Text style={styles.chev}>›</Text>}
      </Pressable>
      {onDelete && (
        <Pressable hitSlop={8} onPress={onDelete} style={({ pressed }) => [styles.del, pressed && { opacity: 0.5 }]}>
          <TrashIcon size={16} color={colors.red} />
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: radius.md, paddingRight: 6, marginBottom: 8 },
  main: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 10, padding: 10 },
  meta: { flex: 1, minWidth: 0 },
  name: { fontSize: 12.5, fontWeight: '700', color: colors.ink },
  size: { fontSize: 10.5, color: colors.sub },
  chev: { color: '#CBD5E1', fontSize: 20, fontWeight: '700' },
  del: { padding: 8 },
});
