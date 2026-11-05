import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import 'inbox_models.dart';

/// Data inbox & history yang dipakai bersama oleh tab Home, Approval, dan History.
class InboxStore extends ChangeNotifier {
  InboxStore(this.api);

  final ApiClient api;

  List<InboxItem> perlu = const [];
  List<InboxItem> riwayat = const [];
  bool memuat = false;
  String? error;

  int get urgent => perlu.where((i) => i.urgent).length;
  int get reguler => perlu.length - urgent;

  Future<void> muatInbox() => _muat(() async {
        final res = await api.get('/inbox');
        perlu = [for (final j in (res['data'] as List? ?? const [])) InboxItem.fromJson(j as Map<String, dynamic>)];
      });

  Future<void> muatHistory() => _muat(() async {
        final res = await api.get('/inbox/history');
        riwayat = [for (final j in (res['data'] as List? ?? const [])) InboxItem.fromJson(j as Map<String, dynamic>)];
      });

  Future<InboxDetail> detail(int id) async => InboxDetail.fromJson((await api.get('/inbox/$id'))['data'] as Map<String, dynamic>);

  /// Setujui / tolak, lalu segarkan daftar. Mengembalikan pesan dari BO.
  Future<String> putuskan(int id, {required bool setuju, String? catatan}) async {
    final res = await api.post('/inbox/$id/act', {
      'action': setuju ? 'approve' : 'reject',
      if (catatan != null && catatan.trim().isNotEmpty) 'notes': catatan.trim(),
    });
    perlu = perlu.where((i) => i.id != id).toList();
    notifyListeners();
    // Segarkan di belakang; kegagalan di sini tidak membatalkan keputusan.
    muatInbox();
    muatHistory();
    return (res['message'] ?? 'Tersimpan.') as String;
  }

  Future<void> _muat(Future<void> Function() isi) async {
    memuat = true;
    error = null;
    notifyListeners();
    try {
      await isi();
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      memuat = false;
      notifyListeners();
    }
  }
}
