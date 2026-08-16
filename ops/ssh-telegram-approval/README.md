# Persetujuan Login SSH lewat Telegram

Setiap login SSH mengirim pesan Telegram berisi tombol **Izinkan / Tolak**. Sesi
baru terbuka setelah tombol Izinkan ditekan.

**Gagal = tolak.** Telegram tak terjangkau, bot mati, atau tidak ada jawaban
sampai batas waktu → login ditolak. Kalau kegagalan justru mengizinkan, penyerang
cukup membuat Telegram tak terjangkau untuk melewati seluruh pemeriksaan ini.

> **JANGAN pasang sebelum memastikan console/VNC dari panel penyedia VPS bisa
> dipakai.** Console tidak melewati sshd, jadi itulah satu-satunya jalan masuk
> bila Telegram bermasalah. Coba dulu sampai berhasil login lewat console.

---

## 1. Siapkan bot Telegram

1. Chat **@BotFather** → `/newbot` → simpan **token**-nya.
2. Kirim satu pesan apa pun ke bot yang baru dibuat (bot tidak bisa memulai chat).
3. Ambil chat id Anda:

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getUpdates" | jq '.result[].message.chat.id'
```

## 2. Pasang di server

```bash
sudo apt update && sudo apt install -y jq curl

sudo cp ops/ssh-telegram-approval/ssh-approval.sh /usr/local/bin/ssh-approval.sh
sudo chmod 700 /usr/local/bin/ssh-approval.sh
sudo mkdir -p /var/lib/ssh-approval
```

Berkas konfigurasi (berisi token — **jangan** masuk git):

```bash
sudo tee /etc/ssh-approval.conf >/dev/null <<'EOF'
BOT_TOKEN="123456:ABC..."
CHAT_ID="123456789"
TIMEOUT_DETIK=60
# Akun otomatis yang TIDAK perlu persetujuan (rsync backup, pemantauan).
# Tanpa ini, tugas terjadwal ikut menunggu manusia lalu gagal diam-diam.
BYPASS_USERS=""
BYPASS_HOSTS="127.0.0.1 ::1"
EOF
sudo chmod 600 /etc/ssh-approval.conf
```

## 3. Uji SEBELUM menyambungkannya ke PAM

```bash
sudo PAM_USER=uji PAM_RHOST=1.2.3.4 PAM_TYPE=account /usr/local/bin/ssh-approval.sh
echo "kode keluar: $?"
```

Pesan harus masuk ke Telegram. Tekan **Izinkan** → `kode keluar: 0`;
**Tolak** atau dibiarkan → `1`. Jangan lanjut sebelum ini benar.

## 4. Sambungkan ke PAM

Tahap **account** dipilih dengan sengaja: tahap itu dilewati semua login sshd,
baik memakai kunci maupun kata sandi. Tahap `auth` tidak dipakai karena login
berbasis kunci melewatinya — pemeriksaan bisa terlewati diam-diam.

```bash
sudo cp /etc/pam.d/sshd /root/pam-sshd.bak     # cadangan untuk pemulihan
echo 'account required pam_exec.so quiet /usr/local/bin/ssh-approval.sh' | sudo tee -a /etc/pam.d/sshd
```

## 5. Uji dari terminal BARU — jangan tutup sesi yang sedang berjalan

```bash
ssh ptmup@<ip-server>
```

Telegram harus berbunyi, dan sesi baru terbuka hanya setelah Anda menekan Izinkan.

## Pemulihan bila terkunci

Lewat console/VNC panel VPS:

```bash
sudo cp /root/pam-sshd.bak /etc/pam.d/sshd
```

SSH kembali normal seketika — `sshd` membaca `/etc/pam.d/sshd` tiap koneksi baru,
jadi tidak perlu restart apa pun.

---

## Yang perlu diketahui

**Sesi yang sedang berjalan tidak terpengaruh.** Pemeriksaan hanya terjadi saat
koneksi baru, jadi memasang atau mencabutnya aman dilakukan sambil bekerja.

**scp, sftp, dan rsync ikut meminta persetujuan** karena semuanya lewat sshd.
Untuk pekerjaan otomatis, masukkan akunnya ke `BYPASS_USERS`.

**Satu permintaan pada satu waktu.** Telegram `getUpdates` bersifat merusak —
update yang sudah diambil hilang dari antrean — sehingga dua login bersamaan bisa
saling mencuri jawaban. Skrip memakai lock agar antre, bukan bentrok.

**Jangan pasang webhook pada bot yang sama.** Webhook dan `getUpdates` tidak bisa
dipakai bersamaan; salah satunya akan berhenti menerima.

**Ini bukan pengganti dasar keamanan lain.** Yang berdampak lebih besar: matikan
login kata sandi (`PasswordAuthentication no`) setelah kunci SSH terbukti jalan,
dan pasang `fail2ban`.
