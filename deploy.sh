#!/usr/bin/env bash
# Déploiement Seydeco sur o2switch (depuis la machine de développement).
#   ./deploy.sh             déploie le dernier commit de $BRANCH poussé sur GitHub, si sa CI est verte
#   ./deploy.sh --rollback  remet la release précédente et la base telle qu'elle était avant le dernier déploiement
# Valeurs serveur : deploy.env (copie de deploy.env.dist). Voir README.md.
set -euo pipefail

cd "$(dirname "$0")"

die() { echo "ERREUR : $*" >&2; exit 1; }

[ -f deploy.env ] || die "deploy.env introuvable. Copiez deploy.env.dist en deploy.env et remplissez-le."
# shellcheck source=/dev/null
source deploy.env
KEEP_RELEASES="${KEEP_RELEASES:-3}"
for v in SSH_HOST REMOTE_DIR PHP_BIN COMPOSER_PHAR BRANCH; do
    [ -n "${!v:-}" ] || die "la variable $v est vide dans deploy.env."
done
[ "$KEEP_RELEASES" -ge 2 ] 2>/dev/null || die "KEEP_RELEASES doit être un nombre ≥ 2."

MODE="deploy"
case "${1:-}" in
    "") ;;
    --rollback) MODE="rollback" ;;
    *) die "option inconnue « $1 ». Utilisation : ./deploy.sh [--rollback]" ;;
esac

# Script exécuté sur le serveur. Arguments : mode, dossier, php, composer, nom de release, nb de releases.
REMOTE_SCRIPT=$(cat <<'REMOTE'
set -euo pipefail
MODE="$1"; REMOTE_DIR="$2"; PHP_BIN="$3"; COMPOSER_PHAR="$4"; NAME="$5"; KEEP="$6"
export APP_ENV=prod APP_DEBUG=0
cd "$REMOTE_DIR"
die() { echo "ERREUR (serveur) : $*" >&2; exit 1; }

[ -x "$PHP_BIN" ] || die "PHP introuvable : $PHP_BIN"
[ -f shared/.env.local ] || die "$REMOTE_DIR/shared/.env.local manquant (voir la checklist du README)."

# Identifiants MySQL lus dans .env.local, écrits dans un fichier temporaire privé (jamais en ligne de commande)
MYCNF=$(mktemp); chmod 600 "$MYCNF"; trap 'rm -f "$MYCNF"' EXIT
DB_NAME=$("$PHP_BIN" -r '
    $url = null;
    foreach (file($argv[1]) as $line) {
        if (preg_match("/^\s*DATABASE_URL\s*=\s*(.*)$/", $line, $m)) { $url = trim($m[1], " \t\r\n\"\x27"); }
    }
    $p = $url ? parse_url($url) : false;
    if (!$p || !isset($p["path"])) { fwrite(STDERR, "DATABASE_URL absent ou illisible\n"); exit(1); }
    $cnf = "[client]\nhost=\"".($p["host"] ?? "localhost")."\"\nport=".($p["port"] ?? 3306)."\nuser=\"".urldecode($p["user"] ?? "")."\"\npassword=\"".addcslashes(urldecode($p["pass"] ?? ""), "\\\"")."\"\n";
    file_put_contents($argv[2], $cnf);
    echo ltrim($p["path"], "/");
' shared/.env.local "$MYCNF") || die "impossible de lire DATABASE_URL dans shared/.env.local."

# Vide la base (sans droit CREATE/DROP DATABASE) puis recharge le dump : aucune table laissée par une migration
restore_dump() {
    local drops
    drops=$(mysql --defaults-extra-file="$MYCNF" -N -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'" "$DB_NAME") || return 1
    { echo "SET FOREIGN_KEY_CHECKS=0;"; echo "$drops"; echo "SET FOREIGN_KEY_CHECKS=1;"; } | mysql --defaults-extra-file="$MYCNF" "$DB_NAME" || return 1
    gunzip -c "$1" | mysql --defaults-extra-file="$MYCNF" "$DB_NAME"
}

if [ "$MODE" = "rollback" ]; then
    [ -L current ] || die "aucune release en ligne."
    CUR=$(basename "$(readlink current)")
    [ -f "releases/$CUR/.previous_release" ] || die "aucune release précédente pour $CUR."
    PREV=$(cat "releases/$CUR/.previous_release")
    [ -d "releases/$PREV" ] || die "la release précédente $PREV n'existe plus."
    [ -f "dumps/$CUR.sql.gz" ] || die "dump dumps/$CUR.sql.gz introuvable."
    echo "→ Restauration de la base (dump pris avant $CUR)"
    restore_dump "dumps/$CUR.sql.gz" || die "RESTAURATION DU DUMP ÉCHOUÉE — restaurer dumps/$CUR.sql.gz à la main (current inchangé)."
    echo "→ Bascule de current vers $PREV"
    ln -sfn "releases/$PREV" current.tmp && mv -Tf current.tmp current
    rm -rf "releases/$CUR" "dumps/$CUR.sql.gz"
    echo "OK : retour arrière sur $PREV."
    exit 0
fi

mkdir -p releases dumps
REL="releases/$NAME"
[ -d "$REL" ] && die "la release $NAME existe déjà."
mkdir "$REL"
tar -x -C "$REL"   # code du commit, envoyé par la machine de dev
ln -s ../../shared/.env.local "$REL/.env.local"
# Photos et vidéos envoyées depuis le back-office : conservées d'une release à l'autre
mkdir -p shared/uploads
ln -s ../../shared/uploads "$REL/uploads"
[ -L current ] && basename "$(readlink current)" > "$REL/.previous_release"

echo "→ Dump de la base dans dumps/$NAME.sql.gz"
mysqldump --defaults-extra-file="$MYCNF" --single-transaction --routines --no-tablespaces "$DB_NAME" | gzip > "dumps/$NAME.sql.gz" \
    || { rm -rf "$REL" "dumps/$NAME.sql.gz"; die "échec du dump, rien n'a été modifié."; }

cd "$REL"
echo "→ composer install --no-dev"
"$PHP_BIN" "$COMPOSER_PHAR" install --no-dev --optimize-autoloader --no-interaction --no-progress \
    || { cd "$REMOTE_DIR"; rm -rf "$REL"; die "échec de composer install, current inchangé."; }

echo "→ Migrations"
if ! "$PHP_BIN" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; then
    cd "$REMOTE_DIR"
    echo "Échec de migration : restauration du dump…" >&2
    restore_dump "dumps/$NAME.sql.gz" || die "RESTAURATION DU DUMP ÉCHOUÉE — restaurer dumps/$NAME.sql.gz à la main."
    rm -rf "$REL"
    die "migration en échec, base restaurée, current inchangé."
fi

echo "→ asset-map:compile + cache:clear"
"$PHP_BIN" bin/console asset-map:compile && "$PHP_BIN" bin/console cache:clear \
    || { cd "$REMOTE_DIR"; restore_dump "dumps/$NAME.sql.gz" || die "RESTAURATION DU DUMP ÉCHOUÉE — restaurer dumps/$NAME.sql.gz à la main."; rm -rf "$REL"; die "échec assets/cache, base restaurée, current inchangé."; }

cd "$REMOTE_DIR"
echo "→ Bascule de current vers $NAME"
ln -sfn "$REL" current.tmp && mv -Tf current.tmp current

# Nettoyage : garder les $KEEP releases les plus récentes et leurs dumps, jamais la release en ligne ni sa précédente
KEEP_PREV=$(cat "$REL/.previous_release" 2>/dev/null || true)
for old in $(ls -1 releases | sort | head -n -"$KEEP"); do
    [ "$old" = "$NAME" ] || [ "$old" = "$KEEP_PREV" ] && continue
    rm -rf "releases/$old" "dumps/$old.sql.gz"
done
echo "OK : $NAME en ligne."
REMOTE
)

if [ "$MODE" = "rollback" ]; then
    read -r -p "Retour arrière : la base sera remise dans l'état d'avant le dernier déploiement (les données saisies depuis seront perdues). Taper « oui » pour continuer : " answer
    [ "$answer" = "oui" ] || die "retour arrière annulé."
    ssh "$SSH_HOST" bash -s -- rollback "$REMOTE_DIR" "$PHP_BIN" "$COMPOSER_PHAR" "-" "$KEEP_RELEASES" <<<"$REMOTE_SCRIPT"
    exit 0
fi

# 1. Le commit à déployer = dernier commit de $BRANCH sur GitHub
command -v gh >/dev/null || die "la commande gh est absente (brew install gh), impossible de vérifier la CI."
git fetch --quiet origin "$BRANCH" || die "git fetch origin $BRANCH impossible."
SHA=$(git rev-parse "origin/$BRANCH")

# 2. Refus si la CI de ce commit n'est pas verte (rouge, en cours ou absente)
CI=$(gh run list --commit "$SHA" --workflow ci.yml --limit 1 --json status,conclusion --jq '.[0] | "\(.status) \(.conclusion)"' 2>/dev/null) \
    || die "impossible d'interroger GitHub (lancer « gh auth login »)."
[ "$CI" = "completed success" ] || die "CI non verte pour ${SHA:0:7} (état : ${CI:-aucune exécution}). Déploiement refusé."
echo "CI verte pour ${SHA:0:7}."

# 3. Release horodatée + code du commit envoyé par SSH, puis étapes serveur
NAME="$(date +%Y%m%d-%H%M%S)-${SHA:0:7}"
echo "Déploiement de la release ${NAME}…"
# shellcheck disable=SC2029 # expansion côté client voulue (script et arguments déjà échappés)
git archive --format=tar "$SHA" | ssh "$SSH_HOST" "bash -c $(printf '%q' "$REMOTE_SCRIPT") -- deploy $(printf '%q ' "$REMOTE_DIR" "$PHP_BIN" "$COMPOSER_PHAR" "$NAME" "$KEEP_RELEASES")"

# 4. Tag Git de la release en ligne
if git tag "release-$NAME" "$SHA" && git push --quiet origin "release-$NAME"; then
    echo "Release taguée release-$NAME."
else
    echo "ATTENTION : release en ligne mais tag non créé/poussé" >&2
fi
