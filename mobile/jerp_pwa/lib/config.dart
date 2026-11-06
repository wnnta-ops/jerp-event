import 'package:flutter/material.dart';

/// Base URL API FO. Override saat run/build:
/// `--dart-define=API_BASE_URL=https://domain/api/mobile`
const apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://localhost:8000/api/mobile',
);

const oranye = Color(0xFFF97316);

/// Tema app: latar netral (bukan peach turunan oranye), kartu putih bergaris tipis, header oranye,
/// navigasi bawah putih dengan penanda oranye muda.
ThemeData temaApp(Brightness b) {
  final gelap = b == Brightness.dark;
  final latar = gelap ? const Color(0xFF16181D) : const Color(0xFFFAFAF9);
  final kartu = gelap ? const Color(0xFF22252C) : Colors.white;
  final garis = gelap ? const Color(0xFF2E323A) : const Color(0xFFEEECEA);
  final skema = ColorScheme.fromSeed(seedColor: oranye, primary: oranye, brightness: b, dynamicSchemeVariant: DynamicSchemeVariant.neutral)
      .copyWith(
    primary: oranye,
    onPrimary: Colors.white,
    surface: kartu,
    surfaceTint: Colors.transparent,
    surfaceContainerLowest: kartu,
    surfaceContainerLow: kartu,
    surfaceContainer: latar,
    surfaceContainerHigh: gelap ? const Color(0xFF2A2D35) : const Color(0xFFF5F5F4),
    surfaceContainerHighest: gelap ? const Color(0xFF2E323A) : const Color(0xFFF1F0EE),
    outlineVariant: garis,
  );
  return ThemeData(
    useMaterial3: true,
    colorScheme: skema,
    scaffoldBackgroundColor: latar,
    appBarTheme: const AppBarTheme(
      backgroundColor: oranye,
      foregroundColor: Colors.white,
      elevation: 0,
      scrolledUnderElevation: 0,
      surfaceTintColor: Colors.transparent,
    ),
    cardTheme: CardThemeData(
      color: kartu,
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: garis)),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: kartu,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      indicatorColor: gelap ? const Color(0xFF7C2D12) : const Color(0xFFFFEDD5),
      labelTextStyle: WidgetStateProperty.resolveWith((s) => TextStyle(
            fontSize: 12,
            fontWeight: s.contains(WidgetState.selected) ? FontWeight.w600 : FontWeight.w400,
            color: s.contains(WidgetState.selected) ? (gelap ? const Color(0xFFFDBA74) : const Color(0xFFC2410C)) : null,
          )),
      iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(
            color: s.contains(WidgetState.selected) ? (gelap ? const Color(0xFFFDBA74) : const Color(0xFFC2410C)) : null,
          )),
    ),
    dividerTheme: DividerThemeData(color: garis),
    segmentedButtonTheme: SegmentedButtonThemeData(
      style: ButtonStyle(
        backgroundColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? (gelap ? const Color(0xFF7C2D12) : const Color(0xFFFFEDD5)) : kartu),
        foregroundColor: WidgetStateProperty.resolveWith(
            (s) => s.contains(WidgetState.selected) ? (gelap ? const Color(0xFFFED7AA) : const Color(0xFF9A3412)) : null),
        side: WidgetStatePropertyAll(BorderSide(color: garis)),
      ),
    ),
  );
}
