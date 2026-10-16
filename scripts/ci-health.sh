#!/usr/bin/env bash
#
# ci-health.sh — après un déploiement, le point de santé d'un site répond.
#
# À jouer depuis un job de CI post-déploiement : un appel HTTPS public et
# anonyme, aucune connexion au serveur. Exemple de job :
# docs/exploitation/sonde-post-deploiement.md.
#
# Ce que le script fait :
#   - `GET <url>`, sans suivre de redirection : la sonde lit ce que le site
#     répond (une base introuvable peut rediriger vers l'installeur, un site
#     multilingue mal réglé vers `/fr/health`) ;
#   - vert seulement sur un code 200 ET un corps portant `"status":"ok"` :
#     une panne franche de la base ou de Redis donne un 500 au démarrage, pas
#     un 503, et un proxy peut répondre 200 avec sa propre page ;
#   - plusieurs essais espacés (6, à 10 s d'intervalle, soit 50 s entre le
#     premier et le dernier) : le point de santé garde son verdict 30 s, et le
#     premier appel après un vidage de cache réchauffe les caches du site. Un
#     échec gardé de la fenêtre précédente ne fait pas rougir le job, un échec
#     qui dure au-delà, si ;
#   - une réponse servie depuis un cache n'est jamais verte, même `ok` : un
#     CDN qui garde `/health` (Cloudflare réécrit `no-store` selon ses règles
#     de cache) rejoue un verdict ancien, et la sonde ne verrait plus une
#     panne. Signes lus : `Age` supérieur à 0, `cf-cache-status` à `HIT`,
#     `STALE`, `UPDATING` ou `REVALIDATED`, `X-Cache` contenant `HIT` ;
#   - en échec, le message donne l'URL, le code HTTP et le corps reçu de
#     CHAQUE essai (le corps de `/health` ne porte aucun secret ; il est
#     tronqué à 500 octets, au cas où un proxy répondrait une page entière).
#
# L'URL est donnée telle quelle : c'est au job de dire quel hôte sonder, et de
# déroger site par site quand l'hôte « officiel » ne sert pas le site.
#
# Usage :
#   bash scripts/ci-health.sh https://www.exemple.fr/health
#   bash scripts/ci-health.sh --self-test     # hors réseau, faux curl
#   Variables : HEALTH_ATTEMPTS (6), HEALTH_PAUSE (10, secondes),
#   HEALTH_TIMEOUT (20, secondes par essai).
#
# Code retour 0 si le site répond `200` + `"status":"ok"`, 1 sinon, 2 sur
# usage erroné.

set -euo pipefail

# Dit si une réponse sort d'un cache, d'après ses en-têtes ($1, fichier
# de `curl -D`). Écrit l'en-tête en cause et rend 0 si c'est le cas.
served_from_cache() {
  local headers="$1" age status xcache
  age="$(grep -i '^age:' "$headers" | tail -n 1 | tr -dc '0-9')"
  if [ -n "$age" ] && [ "$age" -gt 0 ]; then
    echo "Age: $age"
    return 0
  fi
  status="$(grep -i '^cf-cache-status:' "$headers" | tail -n 1 | tr -d '\r' | sed 's/^[^:]*: *//')"
  case "$(printf '%s' "$status" | tr '[:lower:]' '[:upper:]')" in
    HIT | STALE | UPDATING | REVALIDATED)
      echo "cf-cache-status: $status"
      return 0
      ;;
  esac
  xcache="$(grep -i '^x-cache:' "$headers" | tail -n 1 | tr -d '\r' | sed 's/^[^:]*: *//')"
  if printf '%s' "$xcache" | grep -qi 'hit'; then
    echo "X-Cache: $xcache"
    return 0
  fi
  return 1
}

# Sonde une URL : essais espacés, vert au premier `200` + `"status":"ok"`.
probe() {
  local url="$1" attempts pause timeout n code body cached report='' cached_seen=''
  case "$url" in
    https://* | http://*) ;;
    *)
      echo "URL invalide : « $url » (attendu : https://<hôte>/health)" >&2
      return 2
      ;;
  esac
  attempts="${HEALTH_ATTEMPTS:-6}"
  pause="${HEALTH_PAUSE:-10}"
  timeout="${HEALTH_TIMEOUT:-20}"
  body="$(mktemp)"
  # shellcheck disable=SC2064
  trap "rm -f '$body' '$body.err' '$body.hdr'" RETURN

  echo "Point de santé : $url ($attempts essais, $pause s d'intervalle)"
  for n in $(seq 1 "$attempts"); do
    : > "$body"
    : > "$body.err"
    : > "$body.hdr"
    # Ni `-L` ni `--location` : une redirection est un échec, pas un détour.
    code="$(curl -sS --max-time "$timeout" -o "$body" -D "$body.hdr" -w '%{http_code}' \
      -A "healthcheck-ci/${CI_COMMIT_TAG:-local}" "$url" 2> "$body.err")" || code="${code:-000}"
    cached="$(served_from_cache "$body.hdr")" || cached=''
    if [ "$code" = 200 ] && grep -q '"status":"ok"' "$body" && [ -z "$cached" ]; then
      echo "  essai $n/$attempts : HTTP $code, $(head -c 500 "$body")"
      echo "OK : $url répond."
      return 0
    fi
    local line="  essai $n/$attempts : HTTP $code, corps : "
    if [ -s "$body" ]; then
      line="$line« $(head -c 500 "$body" | tr '\n' ' ') »"
    else
      line="${line}(vide)"
    fi
    if [ -n "$cached" ]; then
      line="$line, servi depuis un cache ($cached)"
      cached_seen=1
    fi
    if [ -s "$body.err" ]; then
      line="$line, curl : $(head -c 300 "$body.err" | tr '\n' ' ')"
    fi
    report="$report$line"$'\n'
    if [ -n "$cached" ]; then
      echo "  essai $n/$attempts : HTTP $code, servi depuis un cache ($cached)"
    else
      echo "  essai $n/$attempts : HTTP $code, pas encore vert"
    fi
    if [ "$n" -lt "$attempts" ]; then
      sleep "$pause"
    fi
  done
  {
    echo "ÉCHEC : $url n'a pas répondu HTTP 200 avec \"status\":\"ok\", hors cache, en $attempts essais."
    printf '%s' "$report"
    if [ -n "$cached_seen" ]; then
      echo "Un cache (CDN, proxy) garde /health : exclure /health du cache (règle « Bypass cache » sur Cloudflare), voir docs/exploitation/point-de-sante.md, « Derrière un CDN »."
    fi
    echo "Lire le corps (\"checks\") puis le journal du site, ou lancer « drush healthcheck:run » sur le serveur pour la cause."
  } >&2
  return 1
}

# Auto-test hors réseau : un faux `curl` rejoue des réponses écrites d'avance.
self_test() {
  local dir failures=0
  dir="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$dir'" RETURN
  cat > "$dir/curl" <<'FAKE'
#!/usr/bin/env bash
# Faux curl : lit la réponse suivante de $FAKE_RESPONSES (« code|en-têtes|corps »
# par ligne, en-têtes séparés par `;`), écrit le corps dans le fichier de `-o`,
# les en-têtes dans celui de `-D`, le code sur la sortie.
# Refuse `-L` : la sonde ne doit jamais suivre une redirection.
out='' hdr=''
while [ $# -gt 0 ]; do
  case "$1" in
    -L | --location) echo 'curl appelé avec -L' >&2; exit 99 ;;
    -o) out="$2"; shift ;;
    -D) hdr="$2"; shift ;;
  esac
  shift
done
n="$(cat "$FAKE_COUNTER" 2>/dev/null || echo 0)"; n=$((n + 1)); echo "$n" > "$FAKE_COUNTER"
line="$(sed -n "${n}p" "$FAKE_RESPONSES")"
code="${line%%|*}"; rest="${line#*|}"; headers="${rest%%|*}"; body="${rest#*|}"
if [ "$code" = 000 ]; then echo 'curl: (7) Failed to connect' >&2; printf '000'; exit 7; fi
printf '%s' "$body" > "$out"
if [ -n "$hdr" ]; then
  printf 'HTTP/2 %s\r\n' "$code" > "$hdr"
  [ -z "$headers" ] || printf '%s\r\n' "$headers" | tr ';' '\n' >> "$hdr"
fi
printf '%s' "$code"
FAKE
  chmod +x "$dir/curl"

  # $1 libellé, $2 code attendu, $3 réponses (une par ligne).
  expect() {
    local label="$1" expected="$2" responses="$3" got=0
    printf '%s\n' "$responses" > "$dir/responses"
    rm -f "$dir/counter"
    PATH="$dir:$PATH" FAKE_RESPONSES="$dir/responses" FAKE_COUNTER="$dir/counter" \
      HEALTH_ATTEMPTS=3 HEALTH_PAUSE=0 probe 'https://site.test/health' > "$dir/out" 2>&1 || got=$?
    if [ "$got" = "$expected" ]; then
      echo "ok   - $label"
    else
      echo "FAIL - $label : code $got, attendu $expected"
      sed 's/^/       /' "$dir/out"
      failures=$((failures + 1))
    fi
  }

  expect '200 + status ok : vert' 0 '200||{"status":"ok","checks":{"database":"ok"}}'
  expect 'warning non critique : vert' 0 '200||{"status":"ok","checks":{"search_api":"warning"}}'
  expect 'reprise après un 503 : vert' 0 $'503||{"status":"failed","checks":{"database":"failed"}}\n200||{"status":"ok","checks":{"database":"ok"}}'
  expect '503 qui dure : rouge' 1 $'503||{"status":"failed"}\n503||{"status":"failed"}\n503||{"status":"failed"}'
  expect '200 sans status ok : rouge' 1 $'200||<html>proxy</html>\n200||<html>proxy</html>\n200||<html>proxy</html>'
  expect '500 au démarrage : rouge' 1 $'500||\n500||\n500||'
  expect 'redirection : rouge, jamais suivie' 1 $'301||\n301||\n301||'
  expect 'hôte injoignable : rouge' 1 $'000||\n000||\n000||'
  expect 'en-têtes ordinaires : vert' 0 '200|cache-control: no-store, private;cf-cache-status: DYNAMIC;age: 0|{"status":"ok"}'
  expect 'cf-cache-status MISS : vert' 0 '200|cf-cache-status: MISS|{"status":"ok"}'
  expect 'Cloudflare HIT, même ok : rouge' 1 $'200|cf-cache-status: HIT|{"status":"ok"}\n200|cf-cache-status: HIT|{"status":"ok"}\n200|cf-cache-status: HIT|{"status":"ok"}'
  expect 'Age non nul, même ok : rouge' 1 $'200|Age: 1646|{"status":"ok"}\n200|Age: 1656|{"status":"ok"}\n200|Age: 1666|{"status":"ok"}'
  expect 'X-Cache HIT, même ok : rouge' 1 $'200|X-Cache: HIT, MISS|{"status":"ok"}\n200|X-Cache: HIT, MISS|{"status":"ok"}\n200|X-Cache: HIT, MISS|{"status":"ok"}'
  expect 'cache vidé entre deux essais : vert' 0 $'200|cf-cache-status: HIT;age: 12|{"status":"ok"}\n200|cf-cache-status: MISS|{"status":"ok"}'
  local usage=0
  probe 'site.test/health' > /dev/null 2>&1 || usage=$?
  if [ "$usage" = 2 ]; then
    echo 'ok   - URL sans schéma : usage erroné (2)'
  else
    echo "FAIL - URL sans schéma : code $usage, attendu 2"; failures=$((failures + 1))
  fi

  if [ "$failures" -gt 0 ]; then
    echo "$failures échec(s) à l'auto-test." >&2
    return 1
  fi
  echo 'Auto-test : tout est vert.'
}

case "${1:-}" in
  --self-test) self_test ;;
  '' | -h | --help)
    sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'
    [ -n "${1:-}" ] || exit 2
    ;;
  *) probe "$1" ;;
esac
