#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# Bot Telegram untuk deploy J-ERP dari jarak jauh.
#
# Menjalankan perintah TETAP yang sudah ditentukan di sini — bukan shell bebas.
# Bocornya token bot berarti orang lain bisa memicu deploy & merge, tetapi TIDAK
# bisa menjalankan perintah sembarang di server.
#
# BOT TERPISAH, WAJIB. Jangan memakai token bot persetujuan SSH: keduanya membaca
# antrean lewat getUpdates yang bersifat MERUSAK, sehingga bot yang menunggu
# terus-menerus akan menelan jawaban tombol Izinkan — dan karena persetujuan
# dirancang "gagal = tolak", akibatnya tidak ada yang bisa login SSH sama sekali.
#
# Dijalankan sebagai layanan systemd (lihat deploy-bot.service).
# ═══════════════════════════════════════════════════════════════════════════
set -uo pipefail

CONF=/etc/deploy-bot.conf
STATE=/var/lib/deploy-bot
LOG_TAG=deploy-bot

log() { logger -t "$LOG_TAG" -- "$*"; }

[ -r "$CONF" ] || { echo "konfigurasi tidak terbaca: $CONF" >&2; exit 1; }
# shellcheck source=/dev/null
. "$CONF"

: "${BOT_TOKEN:=}"
: "${ALLOWED_CHATS:=}"      # dipisah spasi/koma — HANYA chat ini yang dilayani
: "${REPO_DIR:=/home/ptmup/docker/jerp}"

[ -n "$BOT_TOKEN" ] && [ -n "$ALLOWED_CHATS" ] || { echo "BOT_TOKEN/ALLOWED_CHATS kosong" >&2; exit 1; }
command -v jq >/dev/null 2>&1 || { echo "jq tidak ada" >&2; exit 1; }

API="https://api.telegram.org/bot${BOT_TOKEN}"
mkdir -p "$STATE"
OFFSET_FILE="$STATE/offset"

kirim() { # $1 chat, $2 teks
    curl -sS --max-time 20 -X POST "${API}/sendMessage" \
        --data-urlencode "chat_id=$1" \
        --data-urlencode "parse_mode=Markdown" \
        --data-urlencode "disable_web_page_preview=true" \
        --data-urlencode "text=$2" >/dev/null 2>&1
}

# Keluaran dipangkas: balasan Telegram dibatasi 4096 karakter, dan pesan yang
# terpotong di tengah lebih menyesatkan daripada ekor yang utuh.
jalankan() {
    local keluaran kode
    keluaran=$(cd "$REPO_DIR" && bash -c "$1" 2>&1)
    kode=$?
    printf '%s\n[keluar=%s]' "$(printf '%s' "$keluaran" | tail -c 2800)" "$kode"
}

diizinkan() {
    local c="$1" a
    for a in $(printf '%s' "$ALLOWED_CHATS" | tr ',' ' '); do
        [ "$c" = "$a" ] && return 0
    done
    return 1
}

BANTUAN=$(cat <<'BANTUAN_EOF'
*Perintah yang tersedia*

`/status` — cabang, commit terakhir, kondisi container
`/pull` — tarik kode terbaru di server
`/deploy fo` — build & migrate FO
`/deploy bo` — build & migrate BO
`/deploy portal` — build Legal & Sales
`/mergebo` — merge cabang bo ke dev, lalu push
`/promote` — naikkan dev ke main, lalu push
`/logs fo` · `/logs bo` — 30 baris log terakhir
`/backup` — backup penuh (DB + berkas)
`/backups` — daftar backup yang ada + sisa ruang disk

Konflik merge TIDAK diselesaikan otomatis — bot membatalkan dan melapor.
BANTUAN_EOF
)

CMD_STATUS=$(cat <<'EOF'
git fetch origin dev main bo --quiet 2>/dev/null
echo "cabang aktif     : $(git rev-parse --abbrev-ref HEAD)"
echo "lokal            : $(git log --oneline -1)"
echo "origin/dev       : $(git log --oneline -1 origin/dev)"
echo "origin/main      : $(git log --oneline -1 origin/main)"
echo "origin/bo        : $(git log --oneline -1 origin/bo)"
echo "bo belum di dev  : $(git rev-list --count origin/dev..origin/bo 2>/dev/null) commit"
echo "dev belum di main: $(git rev-list --count origin/main..origin/dev 2>/dev/null) commit"
echo
docker ps --format "{{.Names}} {{.Status}}" | head -12
EOF
)

CMD_BACKUPS=$(cat <<'EOF'
# Folder tujuan bisa diubah lewat Pengaturan Backup di aplikasi, jadi dibaca dari
# config yang sudah dipakai aplikasi — bukan ditulis mati di sini.
ROOT=$(docker exec jerp-app php artisan tinker        --execute='echo config("filesystems.disks.backup_target.root");' 2>/dev/null        | tr -d '' | tail -1)
case "$ROOT" in /*) : ;; *) ROOT=/var/www/html/storage/app/backups ;; esac
echo "folder : $ROOT"
docker exec jerp-app sh -c "du -sh '$ROOT' 2>/dev/null" | awk '{print "terpakai: "$1}'
# Sisa ruang ikut ditampilkan: penyebab backup gagal yang paling sering adalah
# disk penuh, dan itu baru ketahuan saat backup dibutuhkan kalau tidak dipantau.
df -h /var/lib/docker | awk 'NR==2 {print "sisa disk: "$4" ("$5" terpakai)"}'
echo "---"
docker exec jerp-app sh -c "cd '$ROOT' 2>/dev/null && ls -lht */*.zip 2>/dev/null | head -12"     | awk '{ $1=""; $2=""; $3=""; $4=""; sub(/^ +/,""); print }'
EOF
)

CMD_MERGEBO=$(cat <<'EOF'
git fetch origin bo dev --quiet
JML=$(git rev-list --count origin/dev..origin/bo)
if [ "$JML" = "0" ]; then echo "tidak ada commit baru di bo"; exit 0; fi
echo "$JML commit dari bo:"
git log --oneline origin/dev..origin/bo | head -10
echo "---"
# Dikerjakan di WORKTREE SEMENTARA, bukan dengan checkout di tempat. Server ini
# berada di cabang main untuk produksi; memindahkannya ke dev membuat setiap
# /deploy berikutnya menjalankan kode dev tanpa ada tanda apa pun.
W=$(mktemp -d)
git worktree add --detach "$W" origin/dev --quiet
cd "$W"
if git merge --no-ff origin/bo -m "Merge bo lewat bot deploy" 2>&1 | tail -3; then
    git push origin HEAD:dev 2>&1 | tail -2
    KODE=0
else
    echo "KONFLIK — merge DIBATALKAN, tidak ada yang berubah:"
    git diff --name-only --diff-filter=U
    git merge --abort
    KODE=1
fi
cd - >/dev/null
git worktree remove "$W" --force
exit $KODE
EOF
)

CMD_PROMOTE=$(cat <<'EOF'
git fetch origin dev main --quiet
JML=$(git rev-list --count origin/main..origin/dev)
if [ "$JML" = "0" ]; then echo "main sudah memuat semua dev"; exit 0; fi
echo "$JML commit akan naik:"
git log --oneline origin/main..origin/dev | head -10
echo "---"
W=$(mktemp -d)
git worktree add --detach "$W" origin/main --quiet
cd "$W"
if git merge --no-ff origin/dev -m "Merge dev ke main lewat bot deploy" 2>&1 | tail -3; then
    git push origin HEAD:main 2>&1 | tail -2
    HASIL=0
else
    echo "KONFLIK — DIBATALKAN, main tidak berubah"
    HASIL=1
fi
cd - >/dev/null
git worktree remove "$W" --force
exit $HASIL
EOF
)

log "mulai; repo=$REPO_DIR chat diizinkan=$ALLOWED_CHATS"
OFFSET=$(cat "$OFFSET_FILE" 2>/dev/null || echo 0)

while true; do
    UP=$(curl -sS --max-time 70 \
        "${API}/getUpdates?offset=${OFFSET}&timeout=60&allowed_updates=%5B%22message%22%5D" 2>/dev/null)
    [ "$(printf '%s' "$UP" | jq -r '.ok // false')" = "true" ] || { sleep 5; continue; }

    BARU=$(printf '%s' "$UP" | jq -r '[.result[].update_id] | max // empty')
    [ -n "$BARU" ] && { OFFSET=$((BARU + 1)); echo "$OFFSET" > "$OFFSET_FILE"; }

    while IFS=$'\t' read -r CHAT TEKS DARI; do
        [ -n "${CHAT:-}" ] || continue

        if ! diizinkan "$CHAT"; then
            log "TOLAK perintah dari chat tak dikenal: $CHAT ($DARI): $TEKS"
            continue
        fi

        CMD=$(printf '%s' "$TEKS" | awk '{print tolower($1)}' | sed 's/@.*$//')
        ARG=$(printf '%s' "$TEKS" | awk '{print tolower($2)}')
        log "perintah dari $DARI ($CHAT): $TEKS"

        case "$CMD" in
        /start|/help)
            kirim "$CHAT" "$BANTUAN"
            ;;

        /status)
            HASIL=$(jalankan "$CMD_STATUS")
            kirim "$CHAT" "*Status*
\`\`\`
${HASIL}
\`\`\`"
            ;;

        /pull)
            kirim "$CHAT" "Menarik kode…"
            HASIL=$(jalankan 'git pull --ff-only 2>&1; echo "---"; git log --oneline -3')
            kirim "$CHAT" "*Pull*
\`\`\`
${HASIL}
\`\`\`"
            ;;

        /deploy)
            case "$ARG" in
                fo)     SKRIP="bash marger/up-fo.sh" ;;
                bo)     SKRIP="bash marger/up-bo.sh" ;;
                portal) SKRIP="bash marger/up-portal.sh" ;;
                *) kirim "$CHAT" "Pakai: \`/deploy fo\` · \`/deploy bo\` · \`/deploy portal\`"; continue ;;
            esac
            kirim "$CHAT" "Deploy *${ARG}* dimulai — biasanya 2-5 menit."
            HASIL=$(jalankan "$SKRIP")
            kirim "$CHAT" "*Deploy ${ARG}*
\`\`\`
${HASIL}
\`\`\`"
            ;;

        /mergebo)
            kirim "$CHAT" "Merge cabang bo ke dev…"
            HASIL=$(jalankan "$CMD_MERGEBO")
            kirim "$CHAT" "*Merge bo → dev*
\`\`\`
${HASIL}
\`\`\`"
            ;;

        /promote)
            kirim "$CHAT" "Menaikkan dev ke main…"
            HASIL=$(jalankan "$CMD_PROMOTE")
            kirim "$CHAT" "*Promote dev → main*
\`\`\`
${HASIL}
\`\`\`"
            ;;

        /logs)
            case "$ARG" in
                fo) C=jerp-app ;;
                bo) C=ipro-fo-app ;;
                *) kirim "$CHAT" "Pakai: \`/logs fo\` atau \`/logs bo\`"; continue ;;
            esac
            HASIL=$(jalankan "docker logs $C --tail 30 2>&1")
            kirim "$CHAT" "*Log ${ARG}*
\`\`\`
${HASIL}
\`\`\`"
            ;;

        /backup)
            kirim "$CHAT" "Backup penuh (DB + berkas) dimulai…"
            HASIL=$(jalankan 'docker exec jerp-app php artisan backup:scoped FULL 2>&1 | tail -12')
            kirim "$CHAT" "*Backup*
\`\`\`
${HASIL}
\`\`\`"
            ;;

        /backups)
            HASIL=$(jalankan "$CMD_BACKUPS")
            kirim "$CHAT" "*Daftar backup*
\`\`\`
${HASIL}
\`\`\`"
            ;;

        *)
            case "$CMD" in /*) kirim "$CHAT" "Perintah tidak dikenal. Ketik \`/help\`." ;; esac
            ;;
        esac
    done < <(printf '%s' "$UP" | jq -r '.result[].message
        | select(.text != null)
        | [(.chat.id|tostring), .text, (.from.first_name // .from.username // "?")]
        | @tsv')
done
