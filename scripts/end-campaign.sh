#!/bin/bash
# Fin de campagne : export → matching local → export Excel → suppression serveur
# Usage : ./scripts/end-campaign.sh <campaign_id> <admin_code> [server_url]

set -e

CAMPAIGN_ID=${1:?"Usage: $0 <campaign_id> <admin_code> [server_url]"}
ADMIN_CODE=${2:?"Code admin manquant"}
SERVER_URL=${3:-"https://groupes.inscog.eu"}

WORKDIR="data/campaignes"
SNAPSHOT="tmp-camp-${CAMPAIGN_ID}-$(date +%Y%m%d).db"
DB_PATH="${WORKDIR}/${SNAPSHOT}"

echo "═══ Fin de campagne ${CAMPAIGN_ID} ═══"

# 1. Exporter le snapshot depuis le serveur
echo "[1/5] Export du snapshot depuis ${SERVER_URL}…"
curl -s -c /tmp/tdswap-cookies.txt -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"code\":\"${ADMIN_CODE}\"}" \
    "${SERVER_URL}/admin/login" > /dev/null

curl -s -b /tmp/tdswap-cookies.txt \
    -o "${SNAPSHOT}" \
    "${SERVER_URL}/campaigns/${CAMPAIGN_ID}/export"

if [ ! -f "${SNAPSHOT}" ] || [ $(stat -f%z "${SNAPSHOT}" 2>/dev/null || stat -c%s "${SNAPSHOT}") -lt 1000 ]; then
    echo "ERREUR : snapshot téléchargé invalide."
    exit 1
fi
echo "  ✓ ${SNAPSHOT} téléchargé."

# 2. Importer en local (BDD de travail)
echo "[2/5] Import en local (BDD de travail)…"
mkdir -p "${WORKDIR}"
mv "${SNAPSHOT}" "${DB_PATH}"
echo "  ✓ ${DB_PATH}"

# 3. Matching
echo "[3/5] Matching en cours…"
php scripts/run-matching.php "${CAMPAIGN_ID}" --db "${DB_PATH}"

# 4. Export Excel
echo "[4/5] Export Excel…"
php scripts/export-results.php "${CAMPAIGN_ID}" --db "${DB_PATH}"
EXCEL="storage/resultats-campagne-${CAMPAIGN_ID}.xlsx"
echo "  ✓ ${EXCEL}"

# 5. Suppression côté serveur
echo "[5/5] Suppression de la campagne côté serveur…"
CAMP_NAME=$(php -r "\$p=new PDO('sqlite:${DB_PATH}'); echo \$p->query('SELECT name FROM campaigns WHERE id=${CAMPAIGN_ID}')->fetchColumn();")
curl -s -b /tmp/tdswap-cookies.txt -X DELETE \
    -H 'Content-Type: application/json' \
    -d "{\"confirm_name\":\"${CAMP_NAME}\"}" \
    "${SERVER_URL}/campaigns/${CAMPAIGN_ID}" | php -r 'echo json_decode(stream_get_contents(STDIN))->ok ? "  ✓ Campagne supprimée du serveur.\n" : "  ⚠ Erreur suppression\n";'

rm -f /tmp/tdswap-cookies.txt

echo ""
echo "═══ Terminé ═══"
echo "Excel : ${EXCEL}"
echo "BDD de travail : ${DB_PATH}"
echo ""
echo "Prochaines étapes :"
echo "  1. Vérifier l'Excel (${EXCEL})"
echo "  2. Publier la liste des groupes via les canaux université"
echo "  3. Optionnel : supprimer la BDD de travail (rm ${DB_PATH})"