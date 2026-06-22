// Renders a stamped signature specimen from an SVG path (mock — not a certified
// e-signature). The real authorization is the in-app biometric/PIN at approve time.

import React from 'react';
import Svg, { Path } from 'react-native-svg';
import { colors } from '../theme/tokens';

export default function Signature({ path, width = 58, color = colors.blue700 }: { path: string; width?: number; color?: string }) {
  const height = (width * 30) / 64;
  return (
    <Svg width={width} height={height} viewBox="0 0 64 30" fill="none" stroke={color} strokeWidth={1.7} strokeLinecap="round">
      <Path d={path} />
    </Svg>
  );
}
