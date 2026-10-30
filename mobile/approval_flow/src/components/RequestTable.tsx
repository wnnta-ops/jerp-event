// Javacore-style approval table for wide / web screens. Mirrors MUK ERP's Inbox
// Approval columns: ☐ · No · No. Dokumen · Tanggal · Proyek · Nilai · Diajukan ·
// Aksi. On phones the Inbox renders RequestCard instead (see InboxScreen).

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { colors, radius, moduleColor, moduleLabel, deadlineStyle } from '../theme/tokens';
import { formatSla, deadlineBucket } from '../utils/sla';
import { CheckSmall } from './Icons';
import type { RequestItem } from '../data/types';

interface Props {
  rows: RequestItem[];
  selecting?: boolean;
  selected?: Set<string>;
  onToggle?: (id: string) => void;
  onOpen: (id: string) => void;
}

function rupiah(n: number): string {
  return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

export default function RequestTable({ rows, selecting, selected, onToggle, onOpen }: Props) {
  return (
    <View style={styles.tbl}>
      {/* Header */}
      <View style={styles.head}>
        {selecting && <View style={styles.cCheck} />}
        <Text style={[styles.h, styles.cNo]}>No</Text>
        <Text style={[styles.h, styles.cDoc]}>No. Dokumen</Text>
        <Text style={[styles.h, styles.cDate]}>Tanggal</Text>
        <Text style={[styles.h, styles.cProj]}>Proyek</Text>
        <Text style={[styles.h, styles.cVal]}>Nilai (Rp)</Text>
        <Text style={[styles.h, styles.cBy]}>Diajukan</Text>
        <Text style={[styles.h, styles.cAct]}>Aksi</Text>
      </View>

      {rows.map((r, i) => {
        const on = selected?.has(r.id);
        const bucket = deadlineBucket(r.slaMin);
        const dl = deadlineStyle[bucket];
        return (
          <View key={r.id} style={[styles.row, on && styles.rowOn]}>
            {selecting && (
              <Pressable style={styles.cCheck} onPress={() => onToggle?.(r.id)}>
                <View style={[styles.box, on && styles.boxOn]}>{on && <CheckSmall size={13} color={colors.white} />}</View>
              </Pressable>
            )}

            <Text style={[styles.cell, styles.cNo, styles.muted]}>{i + 1}</Text>

            {/* No. Dokumen (+ module dot, Over Pagu, deadline) */}
            <View style={styles.cDoc}>
              <View style={styles.docTop}>
                <View style={[styles.dot, { backgroundColor: moduleColor(r.moduleKey) }]} />
                <Pressable onPress={() => onOpen(r.id)}>
                  <Text style={styles.docNo}>{r.docNo}</Text>
                </Pressable>
              </View>
              <View style={styles.badges}>
                {r.overPagu && (
                  <View style={styles.pagu}>
                    <Text style={styles.paguText}>Over Pagu</Text>
                  </View>
                )}
                {r.slaMin != null && (
                  <View style={[styles.sla, { backgroundColor: dl.chipBg }]}>
                    <Text style={[styles.slaText, { color: dl.chipFg }]}>
                      {bucket === 'red' ? '⏱ ' : ''}
                      {formatSla(r.slaMin)}
                    </Text>
                  </View>
                )}
              </View>
            </View>

            <Text style={[styles.cell, styles.cDate, styles.muted]}>{r.docDate ?? '—'}</Text>

            <Text style={[styles.cell, styles.cProj]} numberOfLines={2}>
              {r.project ?? '—'}
            </Text>

            <Text style={[styles.cell, styles.cVal, styles.valCell]}>
              {r.valueNum != null ? rupiah(r.valueNum) : '—'}
            </Text>

            <View style={styles.cBy}>
              <Text style={styles.byAgo}>{r.submittedAgo ?? '—'}</Text>
              <Text style={styles.byWho} numberOfLines={1}>
                oleh {r.requester}
              </Text>
              {r.approveTotal ? (
                <Text style={styles.byProg}>
                  Approve {r.approveLevel ?? 0}/{r.approveTotal}
                </Text>
              ) : null}
            </View>

            <View style={styles.cAct}>
              <Pressable style={({ pressed }) => [styles.actBtn, pressed && { opacity: 0.7 }]} onPress={() => onOpen(r.id)}>
                <Text style={styles.actText}>Detail & Keputusan</Text>
              </Pressable>
            </View>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  tbl: { borderWidth: 1, borderColor: colors.line, borderRadius: radius.md, overflow: 'hidden', backgroundColor: colors.white },

  head: { flexDirection: 'row', alignItems: 'center', backgroundColor: colors.slate100, paddingHorizontal: 12, paddingVertical: 10, gap: 12 },
  h: { fontSize: 10.5, fontWeight: '800', color: colors.sub, textTransform: 'uppercase', letterSpacing: 0.4 },

  row: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 12, paddingVertical: 11, gap: 12, borderTopWidth: 1, borderTopColor: colors.line },
  rowOn: { backgroundColor: colors.blueBg },
  cell: { fontSize: 12.5, color: colors.ink },
  muted: { color: colors.sub },

  cCheck: { width: 24, alignItems: 'center' },
  box: { width: 20, height: 20, borderRadius: 6, borderWidth: 2, borderColor: colors.slate400, alignItems: 'center', justifyContent: 'center' },
  boxOn: { backgroundColor: colors.blue, borderColor: colors.blue },

  cNo: { width: 28 },
  cDoc: { flex: 1.5, minWidth: 0 },
  cDate: { width: 86 },
  cProj: { flex: 1.7, minWidth: 0 },
  cVal: { width: 118 },
  cBy: { flex: 1.2, minWidth: 0 },
  cAct: { width: 150 },

  docTop: { flexDirection: 'row', alignItems: 'center', gap: 7 },
  dot: { width: 9, height: 9, borderRadius: 3 },
  docNo: { fontSize: 13, fontWeight: '800', color: colors.blueDark, letterSpacing: 0.2 },
  badges: { flexDirection: 'row', flexWrap: 'wrap', gap: 5, marginTop: 5 },
  pagu: { backgroundColor: colors.redBg, paddingHorizontal: 7, paddingVertical: 1, borderRadius: radius.pill },
  paguText: { fontSize: 10, fontWeight: '800', color: colors.red },
  sla: { paddingHorizontal: 7, paddingVertical: 1, borderRadius: radius.pill },
  slaText: { fontSize: 10, fontWeight: '700' },

  valCell: { fontWeight: '800' },

  byAgo: { fontSize: 11.5, color: colors.ink, fontWeight: '600' },
  byWho: { fontSize: 11, color: colors.slate400, marginTop: 1 },
  byProg: { fontSize: 10.5, color: colors.sub, fontWeight: '700', marginTop: 3 },

  actBtn: { backgroundColor: colors.blue, borderRadius: radius.md, paddingVertical: 9, paddingHorizontal: 10, alignItems: 'center' },
  actText: { color: colors.white, fontSize: 11.5, fontWeight: '800' },
});
