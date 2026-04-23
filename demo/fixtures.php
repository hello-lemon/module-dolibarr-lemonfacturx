<?php
/*
 * LemonFacturX - Fixtures de démo
 *
 * Crée : user admin axel, société émettrice, compte bancaire, 6 tiers variés,
 * 10 factures couvrant tous les cas métier Factur-X
 * (standard, multi-TVA, TVA 0%, avoir, heures, jours, sans email,
 *  UE autoliquidation, acompte TYPE_DEPOSIT, finale avec acompte imputé).
 *
 * Usage : php /var/www/dolibarr/htdocs/custom/lemonfacturx/demo/fixtures.php
 */

$res = @include "/var/www/dolibarr/htdocs/master.inc.php";
if (!$res) {
	die("Impossible de charger master.inc.php\n");
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

$user = new User($db);
$user->fetch(1);
$user->getrights();

echo "=== User admin axel/0000 ===\n";
// Dolibarr rejette "0000" via setPassword() (politique de complexité).
// Pour une démo interne on force un hash bcrypt direct en DB.
$axelHash = password_hash('0000', PASSWORD_BCRYPT);
$u = new User($db);
if ($u->fetch(0, 'axel') <= 0) {
	$u = new User($db);
	$u->login = 'axel';
	$u->lastname = 'Piquet-Gauthier';
	$u->firstname = 'Axel';
	$u->email = 'axel@lemon-demo.local';
	$u->admin = 1;
	$uid = $u->create($user);
	if ($uid > 0) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."user SET pass_crypted='".$db->escape($axelHash)."' WHERE rowid=".((int) $uid));
		echo "  axel créé id=$uid (hash bcrypt forcé)\n";
	} else {
		echo "  ERR ".implode(', ', $u->errors)."\n";
	}
} else {
	$db->query("UPDATE ".MAIN_DB_PREFIX."user SET pass_crypted='".$db->escape($axelHash)."' WHERE rowid=".((int) $u->id));
	echo "  axel existe déjà id=".$u->id.", mot de passe réinitialisé (hash bcrypt forcé)\n";
}

echo "=== Constantes société émettrice ===\n";
$consts = [
	'MAIN_INFO_SOCIETE_NOM'     => 'LEMON DEMO SASU',
	'MAIN_INFO_SOCIETE_ADDRESS' => '1 rue de la Démo',
	'MAIN_INFO_SOCIETE_ZIP'     => '63000',
	'MAIN_INFO_SOCIETE_TOWN'    => 'Clermont-Ferrand',
	'MAIN_INFO_SOCIETE_COUNTRY' => '1:FR:France',
	'MAIN_INFO_SIREN'           => '732829320',
	'MAIN_INFO_SIRET'           => '73282932000074',
	'MAIN_INFO_TVA_INTRA'       => 'FR44732829320',
	'MAIN_INFO_SOCIETE_MAIL'    => 'contact@lemon-demo.local',
];
foreach ($consts as $k => $v) {
	dolibarr_set_const($db, $k, $v, 'chaine', 0, '', 1);
}
echo "  ".count($consts)." constantes posées\n";

echo "=== Compte bancaire ===\n";
$bank = new Account($db);
$bank->ref           = 'DEMO';
$bank->label         = 'Compte démo';
$bank->bank          = 'Banque Démo';
$bank->iban          = 'FR7630001007941234567890185';
$bank->bic           = 'BDFEFRPPCCT';
$bank->type          = 1;
$bank->country_id    = 1;
$bank->currency_code = 'EUR';
$bank->date_solde    = dol_now();
$bank->balance       = 0;
$bankId = $bank->create($user);
if ($bankId <= 0) {
	echo "  ERR ".implode(', ', $bank->errors)."\n";
	exit(1);
}
echo "  compte id=$bankId (IBAN ".$bank->iban.")\n";
dolibarr_set_const($db, 'LEMONFACTURX_BANK_ACCOUNT', $bankId, 'int', 0, '', 1);

echo "=== Tiers ===\n";
function createTier($db, $user, $p)
{
	$s = new Societe($db);
	$s->name       = $p['name'];
	$s->client     = 1;
	$s->address    = $p['address']    ?? '10 rue Test';
	$s->zip        = $p['zip']        ?? '75001';
	$s->town       = $p['town']       ?? 'Paris';
	$s->country_id = $p['country_id'] ?? 1;
	$s->email      = $p['email']      ?? '';
	$s->idprof2    = $p['idprof2']    ?? '';
	$s->tva_intra  = $p['tva_intra']  ?? '';
	$s->tva_assuj  = $p['tva_assuj']  ?? 1;
	$id = $s->create($user);
	if ($id <= 0) {
		echo "  ERR ".$p['name']." : ".implode(', ', $s->errors)."\n";
		return 0;
	}
	echo "  ".$p['name']." => id $id\n";
	return $id;
}

$tiers = [
	'fr_std'      => createTier($db, $user, ['name' => 'Client FR Standard', 'email' => 'contact@fr-std.test', 'idprof2' => '54320987600018', 'tva_intra' => 'FR12543209876']),
	'fr_no_email' => createTier($db, $user, ['name' => 'Client FR Sans Email', 'idprof2' => '48765432100034']),
	'de_intra'    => createTier($db, $user, ['name' => 'Kunde DE GmbH', 'email' => 'kontakt@kunde-de.test', 'address' => 'Musterstrasse 1', 'zip' => '10115', 'town' => 'Berlin', 'country_id' => 5, 'tva_intra' => 'DE123456789']),
	'us_export'   => createTier($db, $user, ['name' => 'US Customer Inc.', 'email' => 'contact@us-customer.test', 'address' => '1 Main St', 'zip' => '10001', 'town' => 'New York', 'country_id' => 233, 'tva_assuj' => 0]),
	'particulier' => createTier($db, $user, ['name' => 'Jean Dupont', 'email' => 'jean.dupont@perso.test', 'address' => '5 rue Perso', 'zip' => '69001', 'town' => 'Lyon', 'tva_assuj' => 0]),
	'no_address'  => createTier($db, $user, ['name' => 'Tiers Sans Adresse', 'email' => 'x@x.test', 'address' => '', 'zip' => '', 'town' => '']),
];

echo "=== Récupération unités (heures, jours) ===\n";
function getUnitId($db, $code)
{
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_units WHERE short_label='".$db->escape($code)."' AND unit_type='time'";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj) {
			return (int) $obj->rowid;
		}
	}
	return 0;
}
$unitH = getUnitId($db, 'h');
$unitD = getUnitId($db, 'd');
echo "  unit heure id=$unitH, unit jour id=$unitD\n";

echo "=== Factures ===\n";
function mkFacture($db, $user, $socid, $lines, $type = 0)
{
	$f = new Facture($db);
	$f->socid             = $socid;
	$f->type              = $type;
	$f->date              = dol_now();
	$f->cond_reglement_id = 1;
	$f->mode_reglement_id = 2;
	$id = $f->create($user);
	if ($id <= 0) {
		echo "  ERR Facture: ".implode(', ', $f->errors)."\n";
		return 0;
	}
	foreach ($lines as $l) {
		$r = $f->addline(
			$l['desc'],
			$l['pu_ht'],
			$l['qty'],
			$l['tva'],
			0, 0,                  // localtax1, localtax2
			0,                     // fk_product
			0,                     // remise_percent
			'', '',                // date_start, date_end
			0,                     // ventil
			0,                     // info_bits
			'',                    // fk_remise_except
			'HT',                  // price_base_type
			0,                     // pu_ttc
			0,                     // type ligne
			-1,                    // rang
			0,                     // special_code
			'',                    // origin
			0,                     // origin_id
			0,                     // fk_parent_line
			null,                  // fk_fournprice
			0,                     // pa_ht
			'',                    // label
			0,                     // array_options
			100,                   // situation_percent
			0,                     // fk_prev_id
			$l['unit'] ?? null     // fk_unit
		);
		if ($r < 0) {
			echo "    ERR addline: ".implode(', ', $f->errors)."\n";
		}
	}
	$f->fetch($id);
	$r = $f->validate($user);
	if ($r <= 0) {
		echo "  ERR validate: ".implode(', ', $f->errors)."\n";
	}
	return $id;
}

$f = [];
$f['F001_std']          = mkFacture($db, $user, $tiers['fr_std'],      [['desc' => 'Prestation mensuelle', 'pu_ht' => 1000, 'qty' => 1, 'tva' => 20]]);
$f['F002_multi_tva']    = mkFacture($db, $user, $tiers['fr_std'],      [['desc' => 'Prestation 20%', 'pu_ht' => 1000, 'qty' => 1, 'tva' => 20], ['desc' => 'Livre 5.5%', 'pu_ht' => 500, 'qty' => 1, 'tva' => 5.5]]);
$f['F003_tva_0']        = mkFacture($db, $user, $tiers['fr_std'],      [['desc' => 'Prestation franchise TVA', 'pu_ht' => 500, 'qty' => 1, 'tva' => 0]]);
$f['F004_avoir']        = mkFacture($db, $user, $tiers['fr_std'],      [['desc' => 'Avoir sur F001', 'pu_ht' => -1000, 'qty' => 1, 'tva' => 20]], 2);
$f['F005_heures']       = mkFacture($db, $user, $tiers['fr_std'],      [['desc' => 'Prestation horaire', 'pu_ht' => 80, 'qty' => 10, 'tva' => 20, 'unit' => $unitH]]);
$f['F006_jours']        = mkFacture($db, $user, $tiers['fr_std'],      [['desc' => 'Prestation journalière', 'pu_ht' => 450, 'qty' => 5, 'tva' => 20, 'unit' => $unitD]]);
$f['F007_no_email']     = mkFacture($db, $user, $tiers['fr_no_email'], [['desc' => 'Prestation', 'pu_ht' => 500, 'qty' => 1, 'tva' => 20]]);
$f['F008_ue_autoliq']   = mkFacture($db, $user, $tiers['de_intra'],    [['desc' => 'Service UE autoliquidation', 'pu_ht' => 2000, 'qty' => 1, 'tva' => 0]]);
$f['F009_deposit']      = mkFacture($db, $user, $tiers['fr_std'],      [['desc' => 'Acompte 20% pour F010', 'pu_ht' => 200, 'qty' => 1, 'tva' => 20]], 3);
$f['F010_with_deposit'] = mkFacture($db, $user, $tiers['fr_std'],      [['desc' => 'Prestation complète avec acompte F009', 'pu_ht' => 1000, 'qty' => 1, 'tva' => 20]]);

foreach ($f as $k => $v) {
	echo "  $k => id $v\n";
}

echo "=== Lien acompte F009 -> F010 (llx_societe_remise_except) ===\n";
if ($f['F009_deposit'] > 0 && $f['F010_with_deposit'] > 0) {
	// Mécanisme Dolibarr : pour que getSumDepositsUsed() détecte un acompte
	// sur la facture finale, il faut une ligne dans llx_societe_remise_except
	// avec description='(DEPOSIT)', fk_facture_source = deposit, fk_facture = finale
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."societe_remise_except "
		." (datec, entity, fk_soc, discount_type, fk_user, description, amount_ht, amount_tva, amount_ttc, tva_tx, fk_facture_source, fk_facture)"
		." VALUES (NOW(), 1, ".((int) $tiers['fr_std']).", 0, 1, '(DEPOSIT)', 200, 40, 240, 20, ".((int) $f['F009_deposit']).", ".((int) $f['F010_with_deposit']).")";
	$res = $db->query($sql);
	if ($res) {
		echo "  lien acompte inséré (F009 200€HT imputé sur F010)\n";
	} else {
		echo "  ERR insert remise_except: ".$db->lasterror()."\n";
	}
}

echo "\n=== Récap ===\n";
echo "- 1 user admin : axel / 0000\n";
echo "- 1 société émettrice : LEMON DEMO SASU (SIREN 732829320)\n";
echo "- 1 compte bancaire : FR76 3000 1007 9412 3456 7890 185\n";
echo "- ".count($tiers)." tiers\n";
echo "- ".count($f)." factures\n";
echo "Fini.\n";
