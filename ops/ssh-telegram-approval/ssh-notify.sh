#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# Pemberitahuan Telegram untuk sesi SSH: dibuka & ditutup.
#
# Dipanggil PAM (pam_exec) pada tahap SESSION. Berbeda dari ssh-approval.sh
# yang MEMUTUSKAN boleh-tidaknya login, berkas ini hanya MEMBERI TAHU.
#
# SELALU keluar 0 — apa pun yang terjadi. Kegagalan mengirim pemberitahuan
# tidak boleh menggagalkan sesi yang sah; kalau bisa menggagalkan, gangguan
# jaringan sesaat berubah jadi tidak bisa masuk server.
#
# Pengiriman dilepas ke latar belakang supaya login tidak menunggu jaringan.
# ═══════════════════════════════════════════════════════════════════════════
set -uo pipefail

CONF=/etc/ssh-approval.conf
[ -r "$CONF" ] || exit 0
# shellcheck source=/dev/null
. "$CONF"

: "${BOT_TOKEN:=}"
: "${CHAT_ID:=}"
: "${BYPASS_USERS:=}"
: "${BYPASS_HOSTS:=127.0.0.1 ::1}"
: "${NOTIFY_SESSION:=1}"   # set 0 di conf untuk mematikan pemberitahuan sesi

[ "$NOTIFY_SESSION" = "1" ] || exit 0
[ -n "$BOT_TOKEN" ] && [ -n "$CHAT_ID" ] || exit 0

USER_LOGIN="${PAM_USER:-?}"
FROM_HOST="${PAM_RHOST:-lokal}"

# Akun & host yang dikecualikan dari persetujuan juga dikecualikan dari
# pemberitahuan — kalau tidak, rsync backup tiap jam membanjiri Telegram.
for u in $BYPASS_USERS; do [ "$USER_LOGIN" = "$u" ] && exit 0; done
for h in $BYPASS_HOSTS; do [ "$FROM_HOST" = "$h" ] && exit 0; done

case "${PAM_TYPE:-}" in
    open_session)  IKON="🟢"; JUDUL="Sesi SSH DIBUKA" ;;
    close_session) IKON="⚪"; JUDUL="Sesi SSH ditutup" ;;
    *) exit 0 ;;
esac

TEKS="${IKON} *${JUDUL}*
Pengguna : \`${USER_LOGIN}\`
Dari IP  : \`${FROM_HOST}\`
Waktu    : $(date '+%d %b %Y %H:%M:%S %Z')
Server   : \`$(hostname)\`"

# Lepas ke latar belakang: login tidak boleh menunggu jaringan Telegram.
# Nilai dioper lewat environment, BUKAN disisipkan ke dalam string perintah —
# nama host atau pengguna bisa memuat karakter yang merusak kutipan shell.
export TEKS BOT_TOKEN CHAT_ID
setsid bash -c 'curl -sS --max-time 15 -X POST \
  "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
  --data-urlencode "chat_id=${CHAT_ID}" \
  --data-urlencode "parse_mode=Markdown" \
  --data-urlencode "text=${TEKS}" >/dev/null 2>&1' \
  </dev/null >/dev/null 2>&1 &

exit 0
