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
        catatanSaya = j['catatan_saya'] as String?,
        turunan = [for (final t in (j['turunan'] as List? ?? const [])) Turunan.fromJson(t as Map<String, dynamic>)];

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

  /// Invoice Hutang: alokasi / koreksi budget yang ikut diputuskan bersama invoice ini.
  final List<Turunan> turunan;

  String get judul => subject?.isNotEmpty == true ? subject! : (number ?? '#$id');
}

/// Alokasi / koreksi budget turunan invoice (lampirannya: dokumen `alokasi-ID` / `koreksi-ID`).
class Turunan {
  Turunan.fromJson(Map<String, dynamic> j)
      : jenis = (j['jenis'] ?? '') as String,
        id = (j['id'] ?? 0) as int,
        docNo = j['doc_no'] as String?,
        dari = j['dari'] as String?,
        ke = j['ke'] as String?,
        nilai = (j['nilai'] as num?)?.toDouble() ?? 0,
        keterangan = j['keterangan'] as String?;

  /// alokasi | koreksi
  final String jenis;
  final int id;
  final String? docNo;
  final String? dari;
  final String? ke;
  final double nilai;
  final String? keterangan;

  String get kunci => '$jenis-$id';
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

/// Dokumen yang bisa di-preview: file berkas (file-ID), lembar memo, lampiran budget.
class DokumenItem {
  DokumenItem.fromJson(Map<String, dynamic> j)
      : kunci = j['kunci'] as String,
        nama = (j['nama'] ?? 'Dokumen') as String,
        jenis = j['jenis'] as String?,
        mime = (j['mime'] ?? '') as String,
        ukuran = (j['ukuran'] as num?)?.toInt();

  final String kunci;
  final String nama;
  final String? jenis;
  final String mime;
  final int? ukuran;

  bool get pdf => mime.contains('pdf');
  bool get gambar => mime.startsWith('image/');
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
        dokumen = [for (final d in (j['dokumen'] as List? ?? const [])) DokumenItem.fromJson(d as Map<String, dynamic>)],
        stages = [for (final s in (j['stages'] as List? ?? const [])) Tahap.fromJson(s as Map<String, dynamic>)],
        canAct = j['can_act'] == true,
        revisi = (j['revisi'] ?? 0) as int,
        riwayatRevisi = [
          for (final r in (j['riwayat_revisi'] as List? ?? const []))
            (nama: (r['nama'] ?? '') as String, at: _tgl(r['at']), catatan: r['catatan'] as String?),
        ],
        bisaRevisi = j['bisa_revisi'] == true;

  final InboxItem item;
  final List<({String label, String? value})> fields;
  final String? body;
  final int jumlahFile;
  final List<DokumenItem> dokumen;
  final List<Tahap> stages;
  final bool canAct;

  /// Revisi Finance atas alokasi / koreksi: ke-berapa (0 = belum), riwayatnya, dan boleh-tidaknya saya merevisi (di BO).
  final int revisi;
  final List<({String nama, DateTime? at, String? catatan})> riwayatRevisi;
  final bool bisaRevisi;

  /// Dokumen selain lampiran alokasi / koreksi (yang itu dibuka dari daftar turunannya).
  List<DokumenItem> get dokumenLain {
    final kunciTurunan = {for (final t in item.turunan) t.kunci};
    return [for (final d in dokumen) if (!kunciTurunan.contains(d.kunci)) d];
  }
}

DateTime? _tgl(Object? v) => v is String ? DateTime.tryParse(v)?.toLocal() : null;

final _rupiah = NumberFormat.currency(locale: 'id_ID', symbol: 'Rp ', decimalDigits: 0);
final _tanggal = DateFormat('d MMM yyyy, HH:mm', 'id_ID');

String rupiah(double? v) => v == null ? '—' : _rupiah.format(v);
String tanggal(DateTime? d) => d == null ? '—' : _tanggal.format(d);
