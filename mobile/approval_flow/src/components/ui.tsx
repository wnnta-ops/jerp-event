// Shared UI primitives matching the prototype's reusable bits:
// Button (.btn solid/ghost/soft), Avatar (.ava), TierChip (.chip), SlaPill (.sla),
// SectionLabel (.sect).

import React from 'react';
import { Text, View, Pressable, StyleSheet, StyleProp, ViewStyle, TextStyle } from 'react-native';
import { colors, radius, tier as tierTokens, sla as slaTokens, Tier, SlaClass } from '../theme/tokens';
import { formatSla } from '../utils/sla';

// ── Button ───────────────────────────────────────────────────────────────────

type ButtonVariant = 'solid' | 'ghost' | 'soft';

interface ButtonProps {
  label: string;
  onPress?: () => void;
  variant?: ButtonVariant;
  color?: string; // override text color
  bg?: string; // override background
  borderColor?: string;
  style?: StyleProp<ViewStyle>;
  disabled?: boolean;
}

export function Button({ label, onPress, variant = 'solid', color, bg, borderColor, style, disabled }: ButtonProps) {
  const base: ViewStyle =
    variant === 'solid'
      ? { backgroundColor: bg ?? colors.blue }
      : variant === 'soft'
        ? { backgroundColor: bg ?? colors.blueBg }
        : { backgroundColor: bg ?? colors.white, borderWidth: 1.5, borderColor: borderColor ?? colors.line };

  const textColor =
    color ?? (variant === 'solid' ? colors.white : variant === 'soft' ? colors.blueDark : colors.red);

  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => [styles.btn, base, pressed && { opacity: 0.85 }, disabled && { opacity: 0.5 }, style]}
    >
      <Text style={[styles.btnText, { color: textColor }]}>{label}</Text>
    </Pressable>
  );
}

// ── Avatar ───────────────────────────────────────────────────────────────────

export function Avatar({ initial, size = 'lg' }: { initial: string; size?: 'lg' | 'xl' }) {
  const dim = size === 'xl' ? 60 : 42;
  const font = size === 'xl' ? 22 : 15;
  return (
    <View style={[styles.ava, { width: dim, height: dim, borderRadius: dim / 2 }]}>
      <Text style={[styles.avaText, { fontSize: font }]}>{initial}</Text>
    </View>
  );
}

// ── Tier chip ────────────────────────────────────────────────────────────────

export function TierChip({ tier, label }: { tier: Tier; label: string }) {
  const t = tierTokens[tier];
  return (
    <View style={[styles.chip, { backgroundColor: t.chipBg }]}>
      <Text style={[styles.chipText, { color: t.chipFg }]}>{label}</Text>
    </View>
  );
}

// ── SLA pill ─────────────────────────────────────────────────────────────────

export function SlaPill({ slaMin, slc }: { slaMin: number | null; slc: SlaClass }) {
  const s = slaTokens[slc];
  const text = slaMin == null ? '·' : `${slc === 'red' ? '⏱ ' : ''}${formatSla(slaMin)}`;
  return (
    <View style={[styles.slaPill, { backgroundColor: s.bg }]}>
      <Text style={[styles.slaText, { color: s.fg }]}>{text}</Text>
    </View>
  );
}

// ── Section label (.sect) ────────────────────────────────────────────────────

export function SectionLabel({ children, style }: { children: React.ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[styles.sect, style]}>{children}</Text>;
}

const styles = StyleSheet.create({
  btn: {
    width: '100%',
    paddingVertical: 13,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  btnText: { fontSize: 14, fontWeight: '700' },

  ava: { backgroundColor: colors.blueChipBg, alignItems: 'center', justifyContent: 'center' },
  avaText: { color: colors.blueDark, fontWeight: '800' },

  chip: { paddingHorizontal: 7, paddingVertical: 2, borderRadius: radius.pill, alignSelf: 'flex-start' },
  chipText: { fontSize: 10, fontWeight: '800', letterSpacing: 0.2 },

  slaPill: { paddingHorizontal: 7, paddingVertical: 2, borderRadius: radius.pill },
  slaText: { fontSize: 10, fontWeight: '700' },

  sect: {
    fontSize: 10.5,
    color: colors.sub,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    fontWeight: '700',
  },
});
