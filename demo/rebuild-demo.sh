#!/usr/bin/env bash
#
# Reconstruit intégralement le Dolibarr de démo :
#  1. Drop DB
#  2. Réinstalle Dolibarr 22 vierge via install forced
#  3. Active les modules core + LemonFacturX
#  4. Pose les constantes (PDF, SMTP vers mailpit)
#  5. Exécute les fixtures (société émettrice, tiers, 10 factures)
#  6. Dumpe le snapshot final dans /root/dolibarr-demo-snapshot.sql.gz
#
# À lancer après toute modification de fixtures.php.
# À exécuter sur le LXC 115 (dolibarr-ard) en root.
#
set -euo pipefail

DB_NAME="dolibarr_ard"
DB_USER="dolibarr_ard"
# Password lu depuis conf.php
DB_PASS=$(grep "^\$dolibarr_main_db_pass" /var/www/dolibarr/htdocs/conf/conf.php | sed -E "s/.*=[ ]*'([^']+)'.*/\1/")
DOL_ROOT="/var/www/dolibarr/htdocs"
DOL_DATA="/var/www/dolibarr/documents"
MODULE_ROOT="$DOL_ROOT/custom/lemonfacturx"
SNAPSHOT="/root/dolibarr-demo-snapshot.sql.gz"

echo "[1/6] Drop + recreate DB $DB_NAME"
rm -f "$DOL_DATA/install.lock" "$DOL_DATA/install.forced.lock"
mysql -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8 COLLATE utf8_unicode_ci; GRANT ALL ON $DB_NAME.* TO $DB_USER@localhost;"

echo "[2/6] Install Dolibarr (install.forced.php + wget steps)"
cat > "$DOL_ROOT/install/install.forced.php" <<EOF
<?php
\$force_install_noedit = 2;
\$force_install_type = 'mysqli';
\$force_install_port = 3306;
\$force_install_dbserver = 'localhost';
\$force_install_database = '$DB_NAME';
\$force_install_databaselogin = '$DB_USER';
\$force_install_databasepass = '$DB_PASS';
\$force_install_prefix = 'llx_';
\$force_install_createdatabase = false;
\$force_install_createuser = false;
\$force_install_mainforcehttps = false;
\$force_install_main_authentication = 'dolibarr';
\$force_install_main_force_https = 0;
\$force_install_messageifalreadyinstalled = 'Already installed';
\$force_install_distrib = 'standard';
EOF
chown www-data:www-data "$DOL_ROOT/install/install.forced.php"
chmod 666 "$DOL_ROOT/conf/conf.php"
systemctl restart nginx
sleep 1

COOKIES=/tmp/dol-install.cookies
rm -f "$COOKIES" /tmp/dol-step*.out
wget -q --save-cookies "$COOKIES" --keep-session-cookies -O /tmp/dol-step1.out "http://localhost/install/step1.php?action=set"
wget -q --load-cookies "$COOKIES" --save-cookies "$COOKIES" --keep-session-cookies -O /tmp/dol-step2.out "http://localhost/install/step2.php?action=set"
wget -q --load-cookies "$COOKIES" --save-cookies "$COOKIES" --keep-session-cookies --post-data="action=set&login=admin&pass=admin&pass_verif=admin" -O /tmp/dol-step4.out "http://localhost/install/step4.php"
wget -q --load-cookies "$COOKIES" --save-cookies "$COOKIES" --keep-session-cookies -O /tmp/dol-step5.out "http://localhost/install/step5.php?action=set&login=admin&pass=admin&pass_verif=admin"
touch "$DOL_DATA/install.lock"
rm -f "$DOL_ROOT/install/install.forced.php"
chmod 440 "$DOL_ROOT/conf/conf.php"
chown www-data:www-data "$DOL_ROOT/conf/conf.php"

# Retire le flag MAIN_NOT_INSTALLED qui peut subsister après step5
# et qui redirigerait les utilisateurs vers /install/ au lieu de la page de login.
mysql -e "DELETE FROM $DB_NAME.llx_const WHERE name='MAIN_NOT_INSTALLED'"

TABLE_COUNT=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'")
echo "  Tables créées : $TABLE_COUNT"

echo "[3/6] Activation modules core + LemonFacturX"
php <<'EOF'
<?php
require_once "/var/www/dolibarr/htdocs/master.inc.php";
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT."/custom/lemonfacturx/core/modules/modLemonFacturX.class.php";

foreach (["modSociete", "modFacture", "modBanque", "modProduct", "modService", "modCommande", "modFournisseur", "modPropale"] as $m) {
	activateModule($m);
}
$mod = new modLemonFacturX($db);
$mod->init();
echo "  modules activés\n";
EOF

echo "[4/6] Constantes SMTP + PDF + LemonFacturX"
php <<'EOF'
<?php
require_once "/var/www/dolibarr/htdocs/master.inc.php";
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
$consts = [
	["LEMONFACTURX_ENABLED", 1, "int"],
	["MAIN_PDF_FORCE_FONT", "pdfahelvetica", "chaine"],
	["MAIN_MAIL_SENDMODE", "smtp", "chaine"],
	["MAIN_MAIL_SMTP_SERVER", "127.0.0.1", "chaine"],
	["MAIN_MAIL_SMTP_PORT", "1025", "chaine"],
	["MAIN_MAIL_EMAIL_FROM", "demo@dolibarr-demo.local", "chaine"],
	["MAIN_APPLICATION_TITLE", "Dolibarr Demo LemonFacturX", "chaine"],
];
foreach ($consts as $c) {
	dolibarr_set_const($db, $c[0], $c[1], $c[2], 0, "", 1);
}
echo "  ".count($consts)." constantes posées\n";
EOF

echo "[5/6] Exécution fixtures"
php "$MODULE_ROOT/demo/fixtures.php"

# Les scripts PHP sont exécutés en CLI root, donc les dossiers/fichiers créés
# dans documents/ (facture/*, propale/*, etc.) appartiennent à root. php-fpm
# tourne en www-data et ne peut plus y écrire (regénération PDF, upload...).
# On rebascule tout à www-data pour que l'UI Dolibarr fonctionne normalement.
chown -R www-data:www-data "$DOL_DATA"
find "$DOL_DATA" -type d -exec chmod 2775 {} +
find "$DOL_DATA" -type f -exec chmod 664 {} + 2>/dev/null || true

echo "[6/6] Snapshot → $SNAPSHOT"
mysqldump --single-transaction --routines --triggers "$DB_NAME" 2>/dev/null | gzip > "$SNAPSHOT"
ls -lh "$SNAPSHOT"

echo ""
echo "Rebuild terminé."
echo "  URL : http://ard.hellolemon.dev"
echo "  Login admin : axel / 0000 (ou admin / admin)"
echo "  Mailpit : http://192.168.1.224:8025"
