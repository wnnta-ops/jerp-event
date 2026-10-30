// Request card.
//   • Javacore mode (r.docNo present): doc number · project · value · module ·
//     who + Approve X/N — mirrors MUK ERP's Inbox Approval row.
//   • Legacy mode: title (judul) · amount · description · who.
//   Left stripe = module/type color; box tint = deadline urgency.

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import Svg, { Path } from 'react-native-svg';
import { colors, radius, typeColor, deadlineStyle, moduleColor, moduleLabel } from '../theme/tokens';
import { formatSla, deadlineBucket, requestBucket } from '../utils/sla';
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

type DlStyle = (typeof deadlineStyle)[keyof typeof deadlineStyle];
type Bucket = ReturnType<typeof deadlineBucket>;

export default function RequestCard({ request: r, onPress, selecting, selected }: Props) {
  const isJava = !!r.docNo;
  const bucket = requestBucket(r);
  const dl = deadlineStyle[bucket];
  const stripe = isJava ? moduleColor(r.moduleKey) : typeColor(r.reqName);

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.rc, { backgroundColor: dl.bg, borderColor: dl.border }, selected && styles.rcSelected, pressed && { opacity: 0.7 }]}
    >
      <View style={[styles.stripe, { backgroundColor: stripe }]} />
      <View style={styles.inner}>{isJava ? javaBody(r, dl, bucket, stripe) : legacyBody(r, dl, bucket)}</View>

      {selecting && (
        <View style={styles.checkWrap}>
          <View style={[styles.checkbox, selected && styles.checkboxOn]}>{selected && <CheckSmall size={14} color={colors.white} />}</View>
        </View>
      )}
    </Pressable>
  );
}

// ── Javacore row ────────────────────────────────────────────────────────────────
function javaBody(r: RequestItem, dl: DlStyle, bucket: Bucket, stripe: string) {
  return (
    <>
      {/* Doc number + deadline */}
      <View style={styles.titleRow}>
        <Text style={styles.docNo} numberOfLines={1}>
          {r.docNo}
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

      {/* Project */}
      {r.project ? (
        <Text style={styles.project} numberOfLines={2}>
          {r.project}
        </Text>
      ) : null}

      {/* Value + module + over-pagu */}
      <View style={styles.valueRow}>
        {r.valueNum != null ? (
          <Text style={styles.amount}>{rupiah(r.valueNum)}</Text>
        ) : (
          <Text style={styles.noValue}>Tanpa nilai</Text>
        )}
        <View style={[styles.modChip, { backgroundColor: stripe + '1A' }]}>
          <Text style={[styles.modChipText, { color: stripe }]}>{moduleLabel(r.moduleKey, r.moduleLabel)}</Text>
        </View>
        {r.overPagu ? (
          <View style={styles.pagu}>
            <Text style={styles.paguText}>Over Pagu</Text>
          </View>
        ) : null}
      </View>

      {/* Who + progress + attachments */}
      <View style={styles.metaRow}>
        <Text style={styles.meta} numberOfLines={1}>
          oleh {r.requester}
          {r.submittedAgo ? ` · ${r.submittedAgo}` : ''}
        </Text>
        {r.approveTotal ? (
          <Text style={styles.progress}>
            Approve {r.approveLevel ?? 0}/{r.approveTotal}
          </Text>
        ) : null}
        {r.docs && r.docs.length > 0 ? (
          <View style={styles.attach}>
            <Clip />
            <Text style={styles.attachText}>{r.docs.length}</Text>
          </View>
        ) : null}
      </View>
    </>
  );
}

// ── Legacy row (fund/doc) ────────────────────────────────────────────────────────
function legacyBody(r: RequestItem, dl: DlStyle, bucket: Bucket) {
  const isFund = r.type === 'FUND';
  const title = isFund ? r.purpose ?? r.amount : r.title;
  return (
    <>
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

      {isFund ? (
        <Text style={styles.amount}>
          {r.amount}
          {r.reqName ? <Text style={styles.amountType}>{`  ·  ${r.reqName}`}</Text> : null}
        </Text>
      ) : (
        <Text style={styles.amountType}>{r.reqName ?? r.cls ?? 'Akses Dokumen'}</Text>
      )}

      {(r.desc || (!isFund && r.sub)) && (
        <Text style={styles.desc} numberOfLines={2}>
          {r.desc ?? r.sub}
        </Text>
      )}

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
    </>
  );
}

function rupiah(n: number): string {
  return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

const styles = StyleSheet.create({
  rc: { borderWidth: 1, borderRadius: radius.lg, overflow: 'hidden', marginBottom: 14, flexDirection: 'row' },
  rcSelected: { borderColor: colors.blue, borderWidth: 1.5 },
  stripe: { width: 6 },
  inner: { paddingVertical: 14, paddingHorizontal: 14, flex: 1, minWidth: 0 },

  titleRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  title: { fontSize: 15, fontWeight: '800', color: colors.ink, flex: 1, lineHeight: 20 },
  docNo: { fontSize: 14.5, fontWeight: '800', color: colors.ink, flex: 1, letterSpacing: 0.2 },
  slaPill: { paddingHorizontal: 9, paddingVertical: 3, borderRadius: radius.pill, marginTop: 1 },
  slaText: { fontSize: 11, fontWeight: '700' },

  project: { fontSize: 12.5, fontWeight: '600', color: colors.ink, marginTop: 6, lineHeight: 17 },

  valueRow: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 8, marginTop: 8 },
  amount: { fontSize: 15, fontWeight: '800', color: colors.ink },
  amountType: { fontSize: 12.5, fontWeight: '600', color: colors.sub, marginTop: 8 },
  noValue: { fontSize: 12.5, fontWeight: '600', color: colors.slate400 },
  modChip: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: radius.pill },
  modChipText: { fontSize: 11, fontWeight: '700' },
  pagu: { backgroundColor: colors.redBg, paddingHorizontal: 8, paddingVertical: 2, borderRadius: radius.pill },
  paguText: { fontSize: 10.5, fontWeight: '800', color: colors.red },

  desc: { fontSize: 12.5, color: colors.sub, marginTop: 6, lineHeight: 18 },

  metaRow: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 12 },
  meta: { fontSize: 11.5, color: colors.slate400, flex: 1 },
  progress: { fontSize: 11, fontWeight: '700', color: colors.sub },
  attach: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  attachText: { fontSize: 11.5, color: colors.slate400, fontWeight: '700' },

  checkWrap: { justifyContent: 'center', paddingHorizontal: 14 },
  checkbox: { width: 24, height: 24, borderRadius: 12, borderWidth: 2, borderColor: colors.slate400, alignItems: 'center', justifyContent: 'center' },
  checkboxOn: { backgroundColor: colors.blue, borderColor: colors.blue },
});
