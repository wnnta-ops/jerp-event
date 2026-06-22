// Request card — roomy, readable layout:
//   • left stripe = request TYPE color
//   • box tint    = deadline urgency (<=3d red, 4-7d amber, else white)
//   Title (judul) · time pill · amount · description · who + attachments

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import Svg, { Path } from 'react-native-svg';
import { colors, radius, typeColor, deadlineStyle } from '../theme/tokens';
import { formatSla, deadlineBucket } from '../utils/sla';
import { CheckSmall } from './Icons';
import type { RequestItem } from '../data/types';

const Clip = () => (
  <Svg width={12} height={12} viewBox="0 0 24 24" fill="none" stroke={colors.slate400} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
    <Path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
  </Svg>
);

interface Props {
  request: RequestItem;
  onPress?: () => void;
  selecting?: boolean;
  selected?: boolean;
}

export default function RequestCard({ request: r, onPress, selecting, selected }: Props) {
  const isFund = r.type === 'FUND';
  const title = isFund ? r.purpose ?? r.amount : r.title;
  const stripe = typeColor(r.reqName);
  const bucket = deadlineBucket(r.slaMin);
  const dl = deadlineStyle[bucket];

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.rc, { backgroundColor: dl.bg, borderColor: dl.border }, selected && styles.rcSelected, pressed && { opacity: 0.7 }]}
    >
      <View style={[styles.stripe, { backgroundColor: stripe }]} />
      <View style={styles.inner}>
        {/* Title + time */}
        <View style={styles.titleRow}>
          <Text style={styles.title} numberOfLines={2}>
            {title}
          </Text>
          {r.slaMin != null && (
            <View style={[styles.slaPill, { backgroundColor: dl.chipBg }]}>
              <Text style={[styles.slaText, { color: dl.chipFg }]}>
                {bucket === 'red' ? '⏱ ' : ''}
                {formatSla(r.slaMin)}
              </Text>
            </View>
          )}
        </View>

        {/* Amount (fund) or access (doc) */}
        {isFund ? (
          <Text style={styles.amount}>
            {r.amount}
            {r.reqName ? <Text style={styles.amountType}>{`  ·  ${r.reqName}`}</Text> : null}
          </Text>
        ) : (
          <Text style={styles.amountType}>{r.reqName ?? r.cls ?? 'Akses Dokumen'}</Text>
        )}

        {/* Description */}
        {(r.desc || (!isFund && r.sub)) && (
          <Text style={styles.desc} numberOfLines={2}>
            {r.desc ?? r.sub}
          </Text>
        )}

        {/* Meta: requester + attachments */}
        <View style={styles.metaRow}>
          <Text style={styles.meta} numberOfLines={1}>
            {r.requester} · {r.dept}
          </Text>
          {r.docs && r.docs.length > 0 ? (
            <View style={styles.attach}>
              <Clip />
              <Text style={styles.attachText}>{r.docs.length}</Text>
            </View>
          ) : null}
        </View>
      </View>

      {selecting && (
        <View style={styles.checkWrap}>
          <View style={[styles.checkbox, selected && styles.checkboxOn]}>{selected && <CheckSmall size={14} color={colors.white} />}</View>
        </View>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  rc: { borderWidth: 1, borderRadius: radius.lg, overflow: 'hidden', marginBottom: 14, flexDirection: 'row' },
  rcSelected: { borderColor: colors.blue, borderWidth: 1.5 },
  stripe: { width: 6 },
  inner: { paddingVertical: 14, paddingHorizontal: 14, flex: 1, minWidth: 0 },

  titleRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  title: { fontSize: 15, fontWeight: '800', color: colors.ink, flex: 1, lineHeight: 20 },
  slaPill: { paddingHorizontal: 9, paddingVertical: 3, borderRadius: radius.pill, marginTop: 1 },
  slaText: { fontSize: 11, fontWeight: '700' },

  amount: { fontSize: 15, fontWeight: '800', color: colors.ink, marginTop: 8 },
  amountType: { fontSize: 12.5, fontWeight: '600', color: colors.sub, marginTop: 8 },

  desc: { fontSize: 12.5, color: colors.sub, marginTop: 6, lineHeight: 18 },

  metaRow: { flexDirection: 'row', alignItems: 'center', marginTop: 12 },
  meta: { fontSize: 11.5, color: colors.slate400, flex: 1 },
  attach: { flexDirection: 'row', alignItems: 'center', gap: 3, marginLeft: 8 },
  attachText: { fontSize: 11.5, color: colors.slate400, fontWeight: '700' },

  checkWrap: { justifyContent: 'center', paddingHorizontal: 14 },
  checkbox: { width: 24, height: 24, borderRadius: 12, borderWidth: 2, borderColor: colors.slate400, alignItems: 'center', justifyContent: 'center' },
  checkboxOn: { backgroundColor: colors.blue, borderColor: colors.blue },
});
