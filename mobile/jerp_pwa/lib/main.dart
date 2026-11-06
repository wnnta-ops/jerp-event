import 'package:flutter/material.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'api/api_client.dart';
import 'auth/session.dart';
import 'config.dart';
import 'kunci/kunci_store.dart';
import 'kunci/layar_kunci.dart';
import 'pages/login_page.dart';
import 'pages/shell_page.dart';
import 'push/push_browser.dart';
import 'prefs.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('id_ID');
  final prefs = Preferensi();
  await prefs.muat();
  final kunci = KunciStore();
  await kunci.muat();
  final session = Session(ApiClient())..pulihkan();
  pasangKunci(session, kunci);
  runApp(JerpApproverApp(session: session, prefs: prefs, kunci: kunci));
}

/// Kunci ikut siklus hidup app (latar belakang > 5 menit → terkunci). Saat keluar akun kunci dan
/// langganan push dilepas, supaya user lain yang masuk di perangkat yang sama mulai bersih.
void pasangKunci(Session session, KunciStore kunci) {
  AppLifecycleListener(onHide: kunci.keLatar, onShow: kunci.kembali);
  session.addListener(() {
    if (session.status != StatusSesi.keluar) return;
    if (kunci.aktif) kunci.reset();
    // Server sudah menghapus langganan push token ini; lepas juga di browser.
    PushBrowser.instance.batal();
  });
}

class JerpApproverApp extends StatelessWidget {
  const JerpApproverApp({super.key, required this.session, required this.prefs, required this.kunci});
  final Session session;
  final Preferensi prefs;
  final KunciStore kunci;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: prefs,
      builder: (context, _) => MaterialApp(
        title: 'J-ERP Approver',
        debugShowCheckedModeBanner: false,
        themeMode: prefs.tema,
        theme: temaApp(Brightness.light),
        darkTheme: temaApp(Brightness.dark),
        // Layar kunci di atas Navigator utama → halaman yang sedang terbuka (detail, dll) ikut tertutup.
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(prefs.teksBesar ? 1.2 : 1.0)),
          child: ListenableBuilder(
            listenable: Listenable.merge([session, kunci]),
            builder: (context, _) => Stack(children: [
              child!,
              if (session.status == StatusSesi.masuk && kunci.terkunci)
                Positioned.fill(
                  child: HeroControllerScope.none(
                    child: Navigator(
                      onGenerateRoute: (_) => MaterialPageRoute(builder: (_) => LayarKunci(kunci: kunci, session: session)),
                    ),
                  ),
                ),
            ]),
          ),
        ),
        home: ListenableBuilder(
          listenable: session,
          builder: (context, _) => switch (session.status) {
            StatusSesi.memuat => const Scaffold(body: Center(child: CircularProgressIndicator())),
            StatusSesi.keluar => LoginPage(session: session),
            StatusSesi.masuk => ShellPage(session: session, prefs: prefs, kunci: kunci),
          },
        ),
      ),
    );
  }
}
