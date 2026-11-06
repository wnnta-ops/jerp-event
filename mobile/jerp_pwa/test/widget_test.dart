import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:jerp_pwa/api/api_client.dart';
import 'package:jerp_pwa/auth/session.dart';
import 'package:jerp_pwa/main.dart';
import 'package:jerp_pwa/kunci/biometrik.dart';
import 'package:jerp_pwa/kunci/kunci_store.dart';
import 'package:jerp_pwa/dokumen/dokumen_platform.dart';
import 'package:jerp_pwa/dokumen/dokumen_popup.dart';
import 'package:jerp_pwa/prefs.dart';
import 'package:jerp_pwa/push/push_browser.dart';
import 'package:intl/date_symbol_data_local.dart';

const profil = {'id': 7, 'name': 'Budi GM', 'role': 'General Manager', 'roleKey': 'gm', 'init': 'B'};

final inbox = [
  {'id': 20, 'type': 'Memo Urgent', 'kategori': 'urgent', 'number': 'MI/2611/00001', 'subject': 'Perbaikan pompa', 'department': 'Engineering',
    'catatan': 'Segera', 'amount': 45000000, 'submitter': 'Dewi', 'status': 'pending', 'done': 1, 'total': 4},
  {'id': 31, 'type': 'Invoice Hutang', 'kategori': 'reguler', 'number': 'INV-1', 'subject': 'Invoice PT Beton', 'department': 'Project',
    'amount': 120500000, 'submitter': 'Sari', 'status': 'pending', 'done': 2, 'total': 5,
    'turunan': [
      {'jenis': 'alokasi', 'id': 5, 'doc_no': 'ALK-0005', 'status': 'menunggu', 'dari': '5010 Iklan · Marketing', 'ke': '5020 Sewa · Project', 'nilai': 2500000},
    ]},
];
// PNG 1x1 transparan.
const ttdPalsu = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

Map<String, Object> dashboard = {'allowed': false, 'grup': [], 'charts': {}};
const dashboardBoleh = {
  'allowed': true,
  'periode': {'label': 'Apr–Nov 2026'},
  'grup': [
    {'key': 'cash', 'judul': 'Cash Management', 'charts': ['rekening', 'kasbon', 'aging']},
    {'key': 'accounting', 'judul': 'Accounting, Tax & Budget', 'charts': ['neraca', 'laba_rugi', 'budget']},
  ],
  'charts': {
    'rekening': {'rows': [{'n': 'BTN 393', 'v': 13826170582.8}, {'n': 'BCA 720', 'v': -673688893.45}]},
    'kasbon': {'labels': ['Okt', 'Nov'], 'series': [{'n': 'Diajukan', 'c': 3, 'v': [94111001, 10750000]}, {'n': 'Dipertanggungjawabkan', 'c': 2, 'v': [81648102, 20836598]}]},
    'aging': {'rows': []},
    'neraca': {'rows': [{'n': 'AKTIVA', 'a': 179758401031.73, 'b': 12747137577.43}], 'keys': [{'k': 'a', 'n': 'Lancar', 'c': 0}, {'k': 'b', 'n': 'Tetap', 'c': 2}]},
    'laba_rugi': {'labels': ['Okt', 'Nov'], 'vals': [10257491285.03, -8486100]},
    'budget': {'rows': [{'n': 'SALES', 'real': 15062235539, 'plafon': 37232979893.96}]},
  },
};

final riwayat = [
  {'id': 9, 'type': 'Koreksi Budget', 'kategori': 'reguler', 'subject': 'Koreksi ATK', 'amount': 5000000, 'status': 'approved',
    'keputusan': 'approved', 'diputuskan_at': '2026-11-04T07:20:00Z'},
];

http.Response json(Object body, [int status = 200]) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

/// Server palsu: password "benar" diterima, selain itu 422 ala Laravel.
MockClient serverPalsu({List<String>? log, List<String>? aksi}) => MockClient((req) async {
      log?.add('${req.method} ${req.url.path} ${req.headers['Authorization'] ?? ''}');
      switch (req.url.path) {
        case '/api/mobile/auth/login':
          final body = jsonDecode(req.body) as Map<String, dynamic>;
          log?.add('device=${body['device']}');
          if (body['password'] == 'benar') return json({'token': '1|abc', 'user': profil});
          return json({
            'message': 'Email atau password salah.',
            'errors': {'email': ['Email atau password salah.']},
          }, 422);
        case '/api/mobile/auth/me':
          return req.headers['Authorization'] == 'Bearer 1|abc'
              ? json(profil)
              : json({'message': 'Unauthenticated.'}, 401);
        case '/api/mobile/auth/logout':
          return json({'ok': true});
        case '/api/mobile/inbox':
          return json({'data': inbox, 'counts': {'urgent': 1, 'reguler': 1}});
        case '/api/mobile/inbox/profil':
          return json({
            'user': {'name': 'Budi GM', 'email': 'gm@jerp.test', 'roles': ['GM'], 'superadmin': false, 'dept_head': false, 'ttd': ttdPalsu},
            'chains': [
              {'code': 'MI-URGENT', 'name': 'Memo Urgent', 'type': 'Memo Urgent', 'kategori': 'urgent', 'steps': [
                {'level': 1, 'label': 'Accounting', 'saya': false}, {'level': 2, 'label': 'Finance', 'saya': false},
                {'level': 3, 'label': 'GM', 'saya': true}, {'level': 4, 'label': 'Direktur', 'saya': false},
              ]},
            ],
            'ringkasan': {'menunggu': 2, 'disetujui': 5, 'ditolak': 1},
          });
        case '/api/mobile/push/config':
          return json({'aktif': true, 'public_key': 'BPUB', 'jam_harian': '08:00'});
        case '/api/mobile/push/status':
          return json({'data': pushServer});
        case '/api/mobile/push/subscribe':
          final b = jsonDecode(req.body) as Map<String, dynamic>;
          log?.add('subscribe ${b['endpoint']} ${b['keys']['p256dh']} ${b['perangkat']}');
          pushServer = {'giliran': true, 'ajuan': true, 'harian': true, 'jam_harian': '08:00'};
          return json({'data': pushServer});
        case '/api/mobile/push/subscription':
          final b = jsonDecode(req.body) as Map<String, dynamic>;
          log?.add('put ${jsonEncode(b..remove('endpoint'))}');
          pushServer = {...?pushServer, ...b};
          return json({'data': pushServer});
        case '/api/mobile/push/unsubscribe':
          log?.add('unsubscribe ${(jsonDecode(req.body) as Map)['endpoint']}');
          pushServer = null;
          return json({'ok': true});
        case '/api/mobile/push/uji':
          log?.add('uji');
          return json({'ok': true});
        case '/api/mobile/auth/sessions':
          return json({'data': [
            {'id': 1, 'perangkat': 'PWA · Android', 'masuk_at': '2026-11-05T01:00:00Z', 'terakhir_at': '2026-11-05T02:00:00Z', 'ini': true},
            {'id': 2, 'perangkat': 'PWA · Mac', 'masuk_at': '2026-11-01T01:00:00Z', 'terakhir_at': null, 'ini': false},
          ]});
        case '/api/mobile/auth/sessions/2':
          log?.add('DELETE sesi 2');
          return json({'ok': true});
        case '/api/mobile/auth/logout-all':
          log?.add('logout-all');
          return json({'ok': true, 'jumlah': 2});
        case '/api/mobile/dashboard':
          return json(dashboard);
        case '/api/mobile/inbox/history':
          return json({'data': riwayat});
        case '/api/mobile/inbox/20':
          return json({'data': {...inbox.first, 'fields': [{'label': 'Supplier', 'value': 'PT Beton'}], 'body': 'Isi memo', 'jumlah_file': 0,
            'stages': [{'label': 'Accounting', 'state': 'done', 'by': 'Andi'}, {'label': 'GM', 'state': 'current'}], 'can_act': true,
            'dokumen': [
              {'kunci': 'file-1', 'nama': 'faktur.pdf', 'jenis': 'Faktur', 'mime': 'application/pdf', 'ukuran': 26285},
              {'kunci': 'memo', 'nama': 'Lembar MI/2611/00001', 'jenis': 'Lembar memo', 'mime': 'text/html', 'ukuran': null},
            ]}});
        case '/api/mobile/inbox/31':
          return json({'data': {...inbox[1], 'fields': [], 'body': null, 'jumlah_file': 0, 'stages': [], 'can_act': true,
            'revisi': 1, 'riwayat_revisi': [{'nama': 'Fina Finance', 'at': '2026-11-05T07:00:00Z', 'catatan': 'Ganti sumber'}], 'bisa_revisi': false,
            'dokumen': [
              {'kunci': 'memo-internal', 'nama': 'Memo Internal + Lampiran INV-1', 'jenis': 'Memo Internal', 'mime': 'text/html', 'ukuran': null},
              {'kunci': 'alokasi-5', 'nama': 'Lampiran alokasi ALK-0005', 'jenis': 'Lampiran alokasi budget', 'mime': 'text/html', 'ukuran': null},
            ]}});
        case '/api/mobile/inbox/31/dokumen/memo-internal':
          return http.Response.bytes(utf8.encode('<p>Memo + lampiran</p>'), 200, headers: {'content-type': 'text/html; charset=UTF-8'});
        case '/api/mobile/inbox/31/dokumen/alokasi-5':
          return http.Response.bytes(utf8.encode('<p>Lampiran</p>'), 200, headers: {'content-type': 'text/html; charset=UTF-8'});
        case '/api/mobile/inbox/20/dokumen/file-1':
          return http.Response.bytes(utf8.encode('%PDF-1.4 uji'), 200,
              headers: {'content-type': 'application/pdf', 'content-disposition': 'inline; filename="faktur.pdf"'});
        case '/api/mobile/inbox/20/dokumen/memo':
          return http.Response.bytes(utf8.encode('<html><body>Memo</body></html>'), 200, headers: {'content-type': 'text/html; charset=UTF-8'});
        case '/api/mobile/inbox/20/act':
          final b = jsonDecode(req.body) as Map<String, dynamic>;
          aksi?.add('${b['action']}:${b['notes'] ?? ''}');
          return json({'ok': true, 'message': b['action'] == 'approve' ? 'Dokumen disetujui.' : 'Dokumen ditolak.'});
      }
      return json({'message': 'Not found'}, 404);
    });

class BioPalsu implements Biometrik {
  bool lolos = true;
  @override
  Future<bool> tersedia() async => true;
  @override
  Future<String?> daftar(String userId, String nama) async => 'cred-$userId';
  @override
  Future<bool> verifikasi(String credId) async => lolos;
}

Future<Session> pasang(
  WidgetTester tester, {
  Map<String, Object> simpanan = const {},
  List<String>? log,
  List<String>? aksi,
  bool bolehDashboard = false,
  String? pinAktif,
  BioPalsu? bio,
}) async {
  dashboard = bolehDashboard ? dashboardBoleh : {'allowed': false, 'grup': [], 'charts': {}};
  // Layar tinggi supaya menu Profil di bawah ikut dibangun ListView.
  tester.view.devicePixelRatio = 1;
  tester.view.physicalSize = const Size(420, 2400);
  addTearDown(tester.view.reset);
  SharedPreferences.setMockInitialValues(simpanan);
  final session = Session(ApiClient(client: serverPalsu(log: log, aksi: aksi)));
  await session.pulihkan();
  final prefs = Preferensi();
  await prefs.muat();
  final kunci = KunciStore(biometrik: bio ?? BioPalsu());
  if (pinAktif != null) await kunci.aktifkan(pinAktif);
  await kunci.muat();
  pasangKunci(session, kunci);
  kunciTerakhir = kunci;
  await tester.pumpWidget(JerpApproverApp(session: session, prefs: prefs, kunci: kunci));
  await tester.pumpAndSettle();
  return session;
}

late KunciStore kunciTerakhir;

class DokumenPalsu implements DokumenPlatform {
  final unduhan = <String>[];
  @override
  Widget html(String isi, String kunci) => Text('HTML $kunci: $isi');
  @override
  Future<void> unduh(Uint8List isi, String nama, String mime) async => unduhan.add('$nama $mime ${isi.length}');
}
Map<String, dynamic>? pushServer;

class PushPalsu implements PushBrowser {
  InfoPush infoNya = const InfoPush(didukung: true, izin: 'default', ios: false, terpasang: false);
  String? ep;
  String hasil = 'ok';
  @override
  Future<InfoPush> info() async => infoNya;
  @override
  Future<HasilLangganan> langganan(String publicKey) async {
    if (hasil != 'ok') return (sub: null, error: hasil);
    ep = 'https://push.test/hp';
    return (sub: <String, dynamic>{'endpoint': ep, 'keys': {'p256dh': 'pk-$publicKey', 'auth': 'au'}}, error: null);
  }
  @override
  Future<String?> endpoint() async => ep;
  @override
  Future<String?> batal() async {
    final lama = ep;
    ep = null;
    return lama;
  }
}

Future<void> ketikPin(WidgetTester tester, String pin) async {
  for (final d in pin.split('')) {
    await tester.tap(find.widgetWithText(OutlinedButton, d).last);
    await tester.pump();
  }
  await tester.pumpAndSettle();
}

Future<void> isiLogin(WidgetTester tester, String email, String password) async {
  await tester.enterText(find.widgetWithText(TextFormField, 'Email'), email);
  await tester.enterText(find.widgetWithText(TextFormField, 'Password'), password);
  await tester.tap(find.text('Masuk'));
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(() => initializeDateFormatting('id_ID'));

  testWidgets('tanpa token tersimpan → halaman login', (tester) async {
    await pasang(tester);
    expect(find.text('Masuk dengan akun J-ERP Anda'), findsOneWidget);
  });

  testWidgets('validasi form kosong', (tester) async {
    await pasang(tester);
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();
    expect(find.text('Email wajib diisi'), findsOneWidget);
    expect(find.text('Password wajib diisi'), findsOneWidget);
  });

  testWidgets('password salah → pesan error dari server', (tester) async {
    await pasang(tester);
    await isiLogin(tester, 'gm@jerp.test', 'salah');
    expect(find.text('Email atau password salah.'), findsOneWidget);
    expect(find.text('Approval Inbox'), findsNothing);
  });

  testWidgets('login sukses → Home dengan sapaan & jumlah, token tersimpan', (tester) async {
    await pasang(tester);
    await isiLogin(tester, 'gm@jerp.test', 'benar');
    expect(find.text('Halo, Budi'), findsOneWidget);
    expect(find.text('Urgent'), findsOneWidget);
    expect(find.text('Reguler'), findsOneWidget);
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('jerp_token'), '1|abc');
  });

  testWidgets('token tersimpan valid → langsung masuk inbox', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    expect(find.text('Halo, Budi'), findsOneWidget);
  });

  testWidgets('token tersimpan kedaluwarsa → login & token dihapus', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '9|basi'});
    expect(find.text('Masuk dengan akun J-ERP Anda'), findsOneWidget);
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('jerp_token'), isNull);
  });

  testWidgets('keluar → panggil logout dan kembali ke login', (tester) async {
    final log = <String>[];
    await pasang(tester, simpanan: {'jerp_token': '1|abc'}, log: log);
    await tester.tap(find.text('Profil'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.widgetWithText(ListTile, 'Keluar'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ListTile, 'Keluar'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Keluar'));
    await tester.pumpAndSettle();
    expect(log.last, 'POST /api/mobile/auth/logout Bearer 1|abc');
    expect(find.text('Masuk dengan akun J-ERP Anda'), findsOneWidget);
  });

  testWidgets('tab Approval: daftar, saring urgent, buka detail lalu setujui', (tester) async {
    final aksi = <String>[];
    await pasang(tester, simpanan: {'jerp_token': '1|abc'}, aksi: aksi);
    await tester.tap(find.text('Approval'));
    await tester.pumpAndSettle();
    expect(find.text('Perbaikan pompa'), findsOneWidget);
    expect(find.text('Invoice PT Beton'), findsOneWidget);

    await tester.tap(find.text('Urgent (1)'));
    await tester.pumpAndSettle();
    expect(find.text('Invoice PT Beton'), findsNothing);

    await tester.tap(find.text('Perbaikan pompa'));
    await tester.pumpAndSettle();
    expect(find.text('Isi memo'), findsOneWidget);
    expect(find.text('PT Beton'), findsOneWidget);

    await tester.tap(find.widgetWithText(FilledButton, 'Setujui'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Setujui').last);
    await tester.pumpAndSettle();
    expect(aksi, ['approve:']);
    expect(find.text('Dokumen disetujui.'), findsOneWidget);
  });

  testWidgets('tolak wajib alasan', (tester) async {
    final aksi = <String>[];
    await pasang(tester, simpanan: {'jerp_token': '1|abc'}, aksi: aksi);
    await tester.tap(find.text('Approval'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Perbaikan pompa'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(OutlinedButton, 'Tolak'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Tolak'));
    await tester.pumpAndSettle();
    expect(find.text('Alasan penolakan wajib diisi'), findsOneWidget);
    expect(aksi, isEmpty);

    await tester.enterText(find.byType(TextField), 'Nominal terlalu besar');
    await tester.tap(find.widgetWithText(FilledButton, 'Tolak'));
    await tester.pumpAndSettle();
    expect(aksi, ['reject:Nominal terlalu besar']);
  });

  testWidgets('tab History menampilkan keputusan', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    await tester.tap(find.text('History'));
    await tester.pumpAndSettle();
    expect(find.text('Koreksi ATK'), findsOneWidget);
    expect(find.text('Disetujui'), findsOneWidget);
  });

  testWidgets('Home tanpa izin dashboard: tidak ada bagian Dashboard', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    expect(find.text('Halo, Budi'), findsOneWidget);
    expect(find.text('Dashboard'), findsNothing);
  });

  testWidgets('Home dengan izin dashboard: grup & grafik tampil', (tester) async {
    tester.view.physicalSize = const Size(800, 6000);
    addTearDown(tester.view.resetPhysicalSize);
    await pasang(tester, simpanan: {'jerp_token': '1|abc'}, bolehDashboard: true);
    expect(find.text('Dashboard'), findsOneWidget);
    expect(find.text('Apr–Nov 2026'), findsOneWidget);
    expect(find.text('Cash Management'), findsOneWidget);
    expect(find.text('4 · Summary Rekening Ledger'), findsOneWidget);
    expect(find.text('Rp 13,8 M'), findsOneWidget);
    expect(find.text('−Rp 674 jt'), findsOneWidget);
    expect(find.text('6 · Aging Hutang'), findsOneWidget);
    expect(find.text('Belum ada data di periode ini.'), findsOneWidget);
    expect(find.text('10 · Realisasi Budget'), findsOneWidget);
    expect(find.text('40%'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('Profil: ringkasan & chain yang melewati saya', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    await tester.tap(find.text('Profil'));
    await tester.pumpAndSettle();
    expect(find.text('Menunggu saya'), findsOneWidget);
    expect(find.text('5'), findsOneWidget);
    expect(find.text('Memo Urgent · Saya di tahap 3 dari 4 (GM)'), findsOneWidget);
    expect(find.text('Direktur'), findsOneWidget);
  });

  testWidgets('login mengirim nama perangkat', (tester) async {
    final log = <String>[];
    await pasang(tester, log: log);
    await isiLogin(tester, 'gm@jerp.test', 'benar');
    expect(log, contains('device=PWA · Android'));
  });

  Future<void> bukaProfil(WidgetTester tester, String menu) async {
    await tester.tap(find.text('Profil'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text(menu));
    await tester.pumpAndSettle();
    await tester.tap(find.text(menu));
    await tester.pumpAndSettle();
  }

  testWidgets('Profil menampilkan preview TTD', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    await tester.tap(find.text('Profil'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Tanda tangan saya'));
    expect(find.byType(Image), findsWidgets);
    expect(find.textContaining('Ubah lewat J-ERP web'), findsOneWidget);
  });

  testWidgets('Tampilan: tab awal Approval tersimpan & dipakai saat app dibuka', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    await bukaProfil(tester, 'Tampilan');
    await tester.tap(find.widgetWithText(SegmentedButton<int>, 'Approval'));
    await tester.pumpAndSettle();
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getInt('pref_tab_awal'), 1);

    await tester.pumpWidget(const SizedBox()); // buka ulang app dari nol
    await pasang(tester, simpanan: {'jerp_token': '1|abc', 'pref_tab_awal': 1});
    expect(find.text('Approval inbox'), findsOneWidget);
  });

  testWidgets('Tampilan: tema gelap diterapkan', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc', 'pref_tema': 'dark'});
    final app = tester.widget<MaterialApp>(find.byType(MaterialApp));
    expect(app.themeMode, ThemeMode.dark);
  });

  testWidgets('Perangkat & sesi: daftar, keluarkan perangkat lain, keluar semua', (tester) async {
    final log = <String>[];
    await pasang(tester, simpanan: {'jerp_token': '1|abc'}, log: log);
    await bukaProfil(tester, 'Perangkat & sesi');
    expect(find.text('PWA · Android (perangkat ini)'), findsOneWidget);
    expect(find.text('PWA · Mac'), findsOneWidget);

    await tester.tap(find.byTooltip('Keluarkan'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Keluarkan'));
    await tester.pumpAndSettle();
    expect(log, contains('DELETE sesi 2'));

    await tester.tap(find.text('Keluar dari semua perangkat'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Keluar semua'));
    await tester.pumpAndSettle();
    expect(log, contains('logout-all'));
    expect(find.text('Masuk dengan akun J-ERP Anda'), findsOneWidget);
  });

  testWidgets('Kunci: nyalakan dari Profil dengan PIN dua kali', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    await bukaProfil(tester, 'Kunci aplikasi');
    await tester.tap(find.widgetWithText(SwitchListTile, 'Kunci aplikasi'));
    await tester.pumpAndSettle();
    expect(find.text('Buat PIN 6 digit'), findsOneWidget);
    await ketikPin(tester, '123456');
    expect(find.text('Ulangi PIN'), findsOneWidget);
    await ketikPin(tester, '654321');
    expect(find.text('PIN tidak sama, ulangi.'), findsOneWidget);
    await ketikPin(tester, '123456');
    expect(find.text('Kunci aplikasi aktif.'), findsOneWidget);
    expect(kunciTerakhir.aktif, isTrue);
    expect(await kunciTerakhir.cocokPin('123456'), isTrue);
  });

  testWidgets('Kunci aktif: app dibuka terkunci, salah lalu benar', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc'}, pinAktif: '246810');
    expect(find.text('Masukkan PIN'), findsOneWidget);
    await ketikPin(tester, '111111');
    expect(find.text('PIN salah. Sisa 4 percobaan.'), findsOneWidget);
    await ketikPin(tester, '246810');
    expect(find.text('Masukkan PIN'), findsNothing);
    expect(find.text('Halo, Budi'), findsOneWidget);
  });

  testWidgets('Kunci: 5x salah → keluar akun & kunci dihapus', (tester) async {
    final log = <String>[];
    await pasang(tester, simpanan: {'jerp_token': '1|abc'}, pinAktif: '246810', log: log);
    for (var i = 0; i < 5; i++) {
      await ketikPin(tester, '000000');
    }
    expect(find.text('Masuk dengan akun J-ERP Anda'), findsOneWidget);
    expect(log, contains('POST /api/mobile/auth/logout Bearer 1|abc'));
    expect(kunciTerakhir.aktif, isFalse);
  });

  testWidgets('Kunci: buka dengan biometrik', (tester) async {
    final bio = BioPalsu();
    await pasang(tester, simpanan: {'jerp_token': '1|abc', 'kunci_bio_cred': 'cred-7'}, pinAktif: '246810', bio: bio);
    bio.lolos = false;
    await tester.tap(find.byTooltip('Buka dengan biometrik'));
    await tester.pumpAndSettle();
    expect(find.text('Masukkan PIN'), findsOneWidget);
    bio.lolos = true;
    await tester.tap(find.byTooltip('Buka dengan biometrik'));
    await tester.pumpAndSettle();
    expect(find.text('Halo, Budi'), findsOneWidget);
  });

  testWidgets('Kunci: latar belakang ≥ 5 menit mengunci, < 5 menit tidak', (tester) async {
    await pasang(tester, simpanan: {'jerp_token': '1|abc'}, pinAktif: '246810');
    await ketikPin(tester, '246810');
    final t = DateTime(2026, 11, 5, 9);
    kunciTerakhir.keLatar(t);
    kunciTerakhir.kembali(t.add(const Duration(minutes: 4)));
    expect(kunciTerakhir.terkunci, isFalse);
    kunciTerakhir.keLatar(t);
    kunciTerakhir.kembali(t.add(const Duration(minutes: 5)));
    await tester.pumpAndSettle();
    expect(find.text('Masukkan PIN'), findsOneWidget);
  });

  testWidgets('Notifikasi: nyalakan, ubah pilihan, kirim uji, matikan', (tester) async {
    final log = <String>[];
    final push = PushPalsu();
    PushBrowser.instance = push;
    pushServer = null;
    await pasang(tester, simpanan: {'jerp_token': '1|abc'}, log: log);
    await bukaProfil(tester, 'Notifikasi push');
    expect(find.text('Mati'), findsOneWidget);

    await tester.tap(find.widgetWithText(SwitchListTile, 'Notifikasi di perangkat ini'));
    await tester.pumpAndSettle();
    expect(log, contains('subscribe https://push.test/hp pk-BPUB PWA · Android'));
    expect(find.text('Giliran saya'), findsOneWidget);

    await tester.tap(find.widgetWithText(SwitchListTile, 'Pengingat harian'));
    await tester.pumpAndSettle();
    expect(log, contains('put {"harian":false}'));

    await tester.tap(find.text('Kirim notifikasi uji'));
    await tester.pumpAndSettle();
    expect(log, contains('uji'));

    await tester.tap(find.widgetWithText(SwitchListTile, 'Notifikasi di perangkat ini'));
    await tester.pumpAndSettle();
    expect(log, contains('unsubscribe https://push.test/hp'));
    expect(push.ep, isNull);
  });

  testWidgets('Notifikasi: iPhone belum dipasang ke Home Screen', (tester) async {
    PushBrowser.instance = PushPalsu()..infoNya = const InfoPush(didukung: false, izin: 'unsupported', ios: true, terpasang: false);
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    await bukaProfil(tester, 'Notifikasi push');
    expect(find.textContaining('Add to Home Screen'), findsOneWidget);
  });

  testWidgets('Notifikasi: izin ditolak', (tester) async {
    final push = PushPalsu()..hasil = 'denied';
    PushBrowser.instance = push;
    pushServer = null;
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    await bukaProfil(tester, 'Notifikasi push');
    await tester.tap(find.widgetWithText(SwitchListTile, 'Notifikasi di perangkat ini'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Izin notifikasi ditolak'), findsOneWidget);
  });

  testWidgets('Detail: preview PDF & lembar memo dalam popup, lalu unduh', (tester) async {
    final dok = DokumenPalsu();
    DokumenPlatform.instance = dok;
    penampilPdf = (isi, kunci) => Text('PDF ${isi.length} byte');
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    await tester.tap(find.text('Approval'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Perbaikan pompa'));
    await tester.pumpAndSettle();
    expect(find.text('faktur.pdf'), findsOneWidget);
    expect(find.text('Faktur · 26 KB'), findsOneWidget);

    await tester.tap(find.text('faktur.pdf'));
    await tester.pumpAndSettle();
    expect(find.text('PDF 12 byte'), findsOneWidget);
    await tester.tap(find.byTooltip('Unduh'));
    await tester.pumpAndSettle();
    expect(dok.unduhan, ['faktur.pdf application/pdf 12']);
    await tester.tap(find.byTooltip('Tutup'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Lembar MI/2611/00001'));
    await tester.pumpAndSettle();
    expect(find.text('HTML memo: <html><body>Memo</body></html>'), findsOneWidget);
  });

  testWidgets('Invoice dengan alokasi/koreksi: penanda di kartu, [REVISI], lampiran, tombol setujui', (tester) async {
    DokumenPlatform.instance = DokumenPalsu();
    await pasang(tester, simpanan: {'jerp_token': '1|abc'});
    await tester.tap(find.text('Approval'));
    await tester.pumpAndSettle();
    expect(find.text('+ 1 alokasi/koreksi budget'), findsOneWidget);
    await tester.tap(find.text('Invoice PT Beton'));
    await tester.pumpAndSettle();
    expect(find.text('Alokasi / Koreksi Budget (1)'), findsOneWidget);
    expect(find.textContaining('[REVISI] ke-1'), findsOneWidget);
    expect(find.text('Setujui + 1 alokasi/koreksi'), findsOneWidget);
    // Lampiran per turunan tidak diulang di bagian Dokumen; Memo Internal (memo + lampiran) yang tampil.
    expect(find.text('Lampiran alokasi ALK-0005'), findsNothing);
    expect(find.text('Memo Internal + Lampiran INV-1'), findsOneWidget);
    await tester.tap(find.text('Alokasi · ALK-0005'));
    await tester.pumpAndSettle();
    expect(find.text('HTML memo-internal: <p>Memo + lampiran</p>'), findsOneWidget);
  });
}
