import 'package:intl/intl.dart';

/// Satu dokumen di BO Approval Inbox (bentuk dari ApprovalInboxBridgeController).
class InboxItem {
  InboxItem.fromJson(Map<String, dynamic> j)
      : id = j['id'] as int,
        type = (j['type'] ?? '') as String,
        urgent = j['kategori'] == 'urgent',
        number = j['number'] as String?,
        subject = j['subject'] as String?,
        department = j['department'] as String?,
        catatan = j['catatan'] as String?,
        amount = (j['amount'] as num?)?.toDouble(),
        submitter = j['submitter'] as String?,
        submittedAt = _tgl(j['submitted_at']),
        position = (j['position'] ?? '') as String,
        status = (j['status'] ?? '') as String,
        done = (j['done'] ?? 0) as int,
        total = (j['total'] ?? 0) as int,
        keputusan = j['keputusan'] as String?,
        diputuskanAt = _tgl(j['diputuskan_at']),
        catatanSaya = j['catatan_saya'] as String?;

  final int id;
  final String type;
  final bool urgent;
  final String? number;
  final String? subject;
  final String? department;
  final String? catatan;
  final double? amount;
  final String? submitter;
  final DateTime? submittedAt;
  final String position;
  final String status;
  final int done;
  final int total;

  /// Hanya di history: keputusan saya (approved / rejected), kapan, dan catatan saya.
  final String? keputusan;
  final DateTime? diputuskanAt;
  final String? catatanSaya;

  String get judul => subject?.isNotEmpty == true ? subject! : (number ?? '#$id');
}

class Tahap {
  Tahap.fromJson(Map<String, dynamic> j)
      : label = (j['label'] ?? '') as String,
        state = (j['state'] ?? '') as String,
        by = j['by'] as String?,
        at = _tgl(j['at']),
        notes = j['notes'] as String?;

  final String label;

  /// done | current | rejected | skipped | waiting
  final String state;
  final String? by;
  final DateTime? at;
  final String? notes;
}

class InboxDetail {
  InboxDetail.fromJson(Map<String, dynamic> j)
      : item = InboxItem.fromJson(j),
        fields = [
          for (final f in (j['fields'] as List? ?? const []))
            (label: (f['label'] ?? '') as String, value: f['value']?.toString()),
        ],
        body = j['body'] as String?,
        jumlahFile = (j['jumlah_file'] ?? 0) as int,
        stages = [for (final s in (j['stages'] as List? ?? const [])) Tahap.fromJson(s as Map<String, dynamic>)],
        canAct = j['can_act'] == true;

  final InboxItem item;
  final List<({String label, String? value})> fields;
  final String? body;
  final int jumlahFile;
  final List<Tahap> stages;
  final bool canAct;
}

DateTime? _tgl(Object? v) => v is String ? DateTime.tryParse(v)?.toLocal() : null;

final _rupiah = NumberFormat.currency(locale: 'id_ID', symbol: 'Rp ', decimalDigits: 0);
final _tanggal = DateFormat('d MMM yyyy, HH:mm', 'id_ID');

String rupiah(double? v) => v == null ? '—' : _rupiah.format(v);
String tanggal(DateTime? d) => d == null ? '—' : _tanggal.format(d);
