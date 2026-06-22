// Pipeline / chain tracker (.pipe) — dots (done ✓ / now ● / wait #) with a
// connecting line between steps, plus a step title and detail.

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors } from '../theme/tokens';
import { SectionLabel } from './ui';
import { EyeIcon } from './Icons';
import type { PipeStep } from '../data/types';

export default function Pipeline({ chain }: { chain: PipeStep[] }) {
  return (
    <View style={styles.pipe}>
      <SectionLabel style={styles.heading}>Pipeline</SectionLabel>
      {chain.map((c, i) => {
        const last = i === chain.length - 1;
        const isReview = c.state === 'review';
        const mark = c.state === 'done' ? '✓' : c.state === 'now' ? '●' : String(i + 1);
        // A review/done step keeps the connecting line solid (the chain moved past it).
        const lineDone = c.state === 'done' || c.state === 'review';
        return (
          <View key={i} style={styles.row}>
            <View style={styles.col}>
              <View style={[styles.dot, dotStyle(c.state)]}>
                {isReview ? <EyeIcon size={12} color={colors.white} /> : <Text style={[styles.dotText, dotTextStyle(c.state)]}>{mark}</Text>}
              </View>
              {!last && <View style={[styles.line, lineDone && styles.lineDone]} />}
            </View>
            <View style={styles.txt}>
              <View style={styles.titleRow}>
                <Text style={styles.title}>{c.title}</Text>
                {isReview && (
                  <View style={styles.reviewTag}>
                    <Text style={styles.reviewTagText}>REVIEWER</Text>
                  </View>
                )}
              </View>
              <Text style={styles.detail}>{c.detail}</Text>
            </View>
          </View>
        );
      })}
    </View>
  );
}

function dotStyle(state: PipeStep['state']) {
  if (state === 'done') return { backgroundColor: colors.green };
  if (state === 'review') return { backgroundColor: colors.blue };
  if (state === 'now') return { backgroundColor: colors.white, borderWidth: 2.5, borderColor: colors.blue };
  return { backgroundColor: colors.white, borderWidth: 2, borderColor: colors.line };
}
function dotTextStyle(state: PipeStep['state']) {
  if (state === 'done') return { color: colors.white };
  if (state === 'now') return { color: colors.blue };
  return { color: colors.slate400 };
}

const styles = StyleSheet.create({
  pipe: { marginTop: 15 },
  heading: { marginBottom: 10 },
  row: { flexDirection: 'row', gap: 11 },
  col: { alignItems: 'center' },
  dot: { width: 21, height: 21, borderRadius: 11, alignItems: 'center', justifyContent: 'center' },
  dotText: { fontSize: 10, fontWeight: '800' },
  line: { width: 2, flex: 1, backgroundColor: colors.line, marginVertical: 2, minHeight: 14 },
  lineDone: { backgroundColor: colors.green },
  txt: { paddingBottom: 13, flex: 1 },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: 7 },
  title: { fontSize: 12.5, fontWeight: '700', color: colors.ink },
  detail: { fontSize: 10.5, color: colors.sub, marginTop: 1 },
  reviewTag: { backgroundColor: colors.blueBg, borderRadius: 4, paddingHorizontal: 5, paddingVertical: 1 },
  reviewTagText: { fontSize: 8, fontWeight: '800', color: colors.blueDark, letterSpacing: 0.3 },
});
