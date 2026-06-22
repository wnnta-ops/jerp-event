// iOS-style toggle switch (.sw) — blue when on, sliding knob.

import React from 'react';
import { Pressable, View, StyleSheet } from 'react-native';
import { colors } from '../theme/tokens';

export default function Toggle({ on, onToggle }: { on: boolean; onToggle?: () => void }) {
  return (
    <Pressable onPress={onToggle} style={[styles.track, on && styles.trackOn]}>
      <View style={[styles.knob, on && styles.knobOn]} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  track: { width: 42, height: 24, borderRadius: 999, backgroundColor: '#CBD5E1', justifyContent: 'center' },
  trackOn: { backgroundColor: colors.blue },
  knob: { position: 'absolute', top: 2, left: 2, width: 20, height: 20, borderRadius: 10, backgroundColor: colors.white },
  knobOn: { left: 20 },
});
