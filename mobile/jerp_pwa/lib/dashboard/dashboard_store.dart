import 'package:flutter/foundation.dart';

import '../api/api_client.dart';

/// Sub-dashboard BO yang boleh dilihat user (dashboard.{key}.view) dan urutan grafiknya.
class GrupDashboard {
  GrupDashboard.fromJson(Map<String, dynamic> j)
      : key = j['key'] as String,
        judul = (j['judul'] ?? j['key']) as String,
        charts = [for (final c in (j['charts'] as List? ?? const [])) c as String];

  final String key;
  final String judul;
  final List<String> charts;
}

/// Data dashboard dari `/api/mobile/dashboard` (props DashboardController BO apa adanya).
class DashboardStore extends ChangeNotifier {
  DashboardStore(this.api);

  final ApiClient api;

  /// null = belum dimuat.
  bool? allowed;
  List<GrupDashboard> grup = const [];
  String? periode;
  Map<String, dynamic> charts = const {};
  bool memuat = false;
  String? error;

  /// [dari] = `date_from` (Y-m-d); null = bawaan BO (8 bulan terakhir).
  Future<void> muat({String? dari}) async {
    memuat = true;
    error = null;
    notifyListeners();
    try {
      final res = await api.get(dari == null ? '/dashboard' : '/dashboard?date_from=$dari');
      allowed = res['allowed'] == true;
      grup = [for (final g in (res['grup'] as List? ?? const [])) GrupDashboard.fromJson(g as Map<String, dynamic>)];
      periode = (res['periode'] as Map?)?['label'] as String?;
      final c = res['charts'];
      charts = c is Map<String, dynamic> ? c : const {};
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      memuat = false;
      notifyListeners();
    }
  }
}
