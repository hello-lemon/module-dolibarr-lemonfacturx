#!/usr/bin/env bash
#
# Restaure le Dolibarr de démo à l'état initial (post-fixtures) en ~5 secondes.
# Drop la DB, réimporte le snapshot /root/dolibarr-demo-snapshot.sql.gz.
# À exécuter sur le LXC 115 (dolibarr-ard) en root.
#
set -euo pipefail

SNAPSHOT="/root/dolibarr-demo-snapshot.sql.gz"
DB_NAME="dolibarr_ard"
DB_USER="dolibarr_ard"

if [ ! -f "$SNAPSHOT" ]; then
	echo "Snapshot introuvable : $SNAPSHOT"
	echo "Lancer d'abord rebuild-demo.sh pour créer le snapshot initial."
	exit 1
fi

echo "[1/4] Arrêt nginx"
systemctl stop nginx

echo "[2/4] Drop + recreate DB $DB_NAME"
mysql -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8 COLLATE utf8_unicode_ci; GRANT ALL ON $DB_NAME.* TO $DB_USER@localhost;"

echo "[3/4] Import snapshot"
zcat "$SNAPSHOT" | mysql "$DB_NAME"

echo "[4/4] Redémarrage nginx"
systemctl start nginx

echo "Reset terminé. Login : axel / 0000 (ou admin / admin)"
