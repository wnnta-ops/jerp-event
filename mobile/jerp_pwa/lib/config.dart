import 'package:flutter/material.dart';

/// Base URL API FO. Override saat run/build:
/// `--dart-define=API_BASE_URL=https://domain/api/mobile`
const apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://localhost:8000/api/mobile',
);

const oranye = Color(0xFFF97316);

/// Oranye lembut untuk mode gelap — oranye penuh di atas latar gelap terlalu silau.
const oranyeGelap = Color(0xFFE8925A);

/// Warna aksen (tombol, ikon, angka penting) sesuai mode terang / gelap.
Color aksen(BuildContext c) => Theme.of(c).colorScheme.primary;

/// Teks aksen di atas latar kartu (nominal, tautan "Semua").
Color aksenTeks(BuildContext c) => Theme.of(c).brightness == Brightness.dark ? const Color(0xFFF4B183) : const Color(0xFFC2410C);

/// Latar header (AppBar, header Home, judul popup): oranye di mode terang, gelap hangat di mode gelap.
Color warnaHeader(BuildContext c) => Theme.of(c).appBarTheme.backgroundColor ?? oranye;

/// Latar label / pilihan terpilih (chip grup, segmen, penanda nav) dan teksnya.
Color latarPilih(BuildContext c) => Theme.of(c).brightness == Brightness.dark ? const Color(0xFF3A2C22) : const Color(0xFFFFEDD5);
Color teksPilih(BuildContext c) => Theme.of(c).brightness == Brightness.dark ? const Color(0xFFF4B183) : const Color(0xFF9A3412);

/// Tema app: latar netral (bukan peach turunan oranye), kartu putih bergaris tipis, header oranye,
/// navigasi bawah putih dengan penanda oranye muda. Mode gelap: latar abu gelap lembut (bukan hitam),
/// header gelap hangat, aksen oranye diredam — supaya tidak menyilaukan.
ThemeData temaApp(Brightness b) {
  final gelap = b == Brightness.dark;
  final aksenMode = gelap ? oranyeGelap : oranye;
  final latar = gelap ? const Color(0xFF1A1C20) : const Color(0xFFFAFAF9);
  final kartu = gelap ? const Color(0xFF23262B) : Colors.white;
  final garis = gelap ? const Color(0xFF30343B) : const Color(0xFFEEECEA);
  final pilih = gelap ? const Color(0xFF3A2C22) : const Color(0xFFFFEDD5);
  final teksPilihMode = gelap ? const Color(0xFFF4B183) : const Color(0xFFC2410C);
  final skema = ColorScheme.fromSeed(seedColor: oranye, primary: aksenMode, brightness: b, dynamicSchemeVariant: DynamicSchemeVariant.neutral)
      .copyWith(
    primary: aksenMode,
    onPrimary: gelap ? const Color(0xFF1A1C20) : Colors.white,
    surface: kartu,
    onSurface: gelap ? const Color(0xFFE6E4E1) : null,
    onSurfaceVariant: gelap ? const Color(0xFFA8A6A2) : null,
    surfaceTint: Colors.transparent,
    surfaceContainerLowest: kartu,
    surfaceContainerLow: kartu,
    surfaceContainer: latar,
    surfaceContainerHigh: gelap ? const Color(0xFF2A2D33) : const Color(0xFFF5F5F4),
    surfaceContainerHighest: gelap ? const Color(0xFF2E3138) : const Color(0xFFF1F0EE),
    outlineVariant: garis,
  );
  return ThemeData(
    useMaterial3: true,
    colorScheme: skema,
    scaffoldBackgroundColor: latar,
    appBarTheme: AppBarTheme(
      backgroundColor: gelap ? const Color(0xFF2B231E) : oranye,
      foregroundColor: gelap ? const Color(0xFFF1E6DD) : Colors.white,
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
      indicatorColor: pilih,
      labelTextStyle: WidgetStateProperty.resolveWith((s) => TextStyle(
            fontSize: 12,
            fontWeight: s.contains(WidgetState.selected) ? FontWeight.w600 : FontWeight.w400,
            color: s.contains(WidgetState.selected) ? teksPilihMode : null,
          )),
      iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(
            color: s.contains(WidgetState.selected) ? teksPilihMode : null,
          )),
    ),
    dividerTheme: DividerThemeData(color: garis),
    segmentedButtonTheme: SegmentedButtonThemeData(
      style: ButtonStyle(
        backgroundColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? pilih : kartu),
        foregroundColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? teksPilihMode : null),
        side: WidgetStatePropertyAll(BorderSide(color: garis)),
      ),
    ),
  );
}
