<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Page de configuration du module LemonFacturX
 */

// Charger l'environnement Dolibarr
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once dol_buildpath('/lemonfacturx/core/lib/lemonfacturx.lib.php');

// Sécurité
if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(["admin", "lemonfacturx@lemonfacturx"]);

$action = GETPOST('action', 'aZ09');

// Valeurs par défaut des mentions légales BR-FR (synchronisées avec xml_builder.php)
$defaultPMD = 'En cas de retard de paiement, une pénalité égale à 3 fois le taux d\'intérêt légal sera exigible (article L.441-10 du Code de commerce).';
$defaultPMT = 'Une indemnité forfaitaire de 40 euros sera exigible pour frais de recouvrement en cas de retard de paiement.';
$defaultAAB = 'Pas d\'escompte pour paiement anticipé.';

// Sauvegarde des paramètres
if ($action == 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	// CSRF : vérifier le token courant (pas newToken() qui génère un futur token)
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$error = 0;

	$updates = [
		['LEMONFACTURX_ENABLED',      GETPOSTINT('LEMONFACTURX_ENABLED'),         'int'],
		['LEMONFACTURX_BANK_ACCOUNT', GETPOSTINT('LEMONFACTURX_BANK_ACCOUNT'),    'int'],
		['LEMONFACTURX_PAYMENT_MEANS',trim(GETPOST('LEMONFACTURX_PAYMENT_MEANS', 'alpha')), 'chaine'],
		['LEMONFACTURX_STRICT_MODE',  GETPOSTINT('LEMONFACTURX_STRICT_MODE'),     'int'],
		['LEMONFACTURX_PHP_CLI_PATH', trim(GETPOST('LEMONFACTURX_PHP_CLI_PATH', 'alphanohtml')), 'chaine'],
		['LEMONFACTURX_NOTE_PMD',     trim(GETPOST('LEMONFACTURX_NOTE_PMD', 'restricthtml')),    'chaine'],
		['LEMONFACTURX_NOTE_PMT',     trim(GETPOST('LEMONFACTURX_NOTE_PMT', 'restricthtml')),    'chaine'],
		['LEMONFACTURX_NOTE_AAB',     trim(GETPOST('LEMONFACTURX_NOTE_AAB', 'restricthtml')),    'chaine'],
	];
	foreach ($updates as $u) {
		if (dolibarr_set_const($db, $u[0], $u[1], $u[2], 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

// Affichage
llxHeader('', $langs->trans("LemonFacturXSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("LemonFacturXSetup"), $linkback, 'title_setup');

// Bandeau "Nouvelle version disponible" si le check GitHub remonte une version > locale
require_once dirname(__DIR__).'/core/modules/modLemonFacturX.class.php';
$modDesc = new modLemonFacturX($db);
$updateInfo = lemonfacturx_check_latest_release($db, $modDesc->version);
if ($updateInfo !== null) {
	print '<div class="warning" style="margin:8px 0;padding:10px;border-left:4px solid #e67e22;background:#fff3e0;">';
	print '<strong>'.$langs->trans("LemonFacturXUpdateAvailable").'</strong> : ';
	print $langs->trans("LemonFacturXUpdateAvailableMsg", dol_escape_htmltag($updateInfo['version']), dol_escape_htmltag($modDesc->version));
	print ' <a href="'.dol_escape_htmltag($updateInfo['url']).'" target="_blank" rel="noopener">'.$langs->trans("LemonFacturXUpdateSeeRelease").'</a>';
	print '</div>';
}

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';

// Activer/Désactiver
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXEnabled").'</td>';
print '<td>';
print '<select name="LEMONFACTURX_ENABLED" class="flat">';
print '<option value="0"'.(!getDolGlobalInt('LEMONFACTURX_ENABLED') ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.(getDolGlobalInt('LEMONFACTURX_ENABLED') ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// Compte bancaire (IBAN/BIC)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXBankAccount").'</td>';
print '<td>';
$currentBankAccount = getDolGlobalInt('LEMONFACTURX_BANK_ACCOUNT');
$sql = "SELECT rowid, label, iban_prefix, bic FROM ".MAIN_DB_PREFIX."bank_account WHERE clos = 0 AND entity = ".$conf->entity." ORDER BY label";
$resql = $db->query($sql);
print '<select name="LEMONFACTURX_BANK_ACCOUNT" class="flat minwidth300">';
print '<option value="0">-- '.$langs->trans("Select").' --</option>';
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$infoIban = !empty($obj->iban_prefix) ? ' ('.substr($obj->iban_prefix, 0, 4).'...'.substr($obj->iban_prefix, -4).')' : ' (pas d\'IBAN)';
		print '<option value="'.$obj->rowid.'"'.($currentBankAccount == $obj->rowid ? ' selected' : '').'>';
		print dol_escape_htmltag($obj->label.$infoIban);
		print '</option>';
	}
}
print '</select>';
print '</td>';
print '</tr>';

// Moyen de paiement
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXPaymentMeans").'</td>';
print '<td>';
$currentMeans = getDolGlobalString('LEMONFACTURX_PAYMENT_MEANS', '30');
print '<select name="LEMONFACTURX_PAYMENT_MEANS" class="flat">';
print '<option value="30"'.($currentMeans == '30' ? ' selected' : '').'>30 - '.$langs->trans("PaymentMeans30").'</option>';
print '<option value="58"'.($currentMeans == '58' ? ' selected' : '').'>58 - '.$langs->trans("PaymentMeans58").'</option>';
print '<option value="49"'.($currentMeans == '49' ? ' selected' : '').'>49 - '.$langs->trans("PaymentMeans49").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// Mode strict
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXStrictMode");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXStrictModeHint").'</span>';
print '</td>';
print '<td>';
$strict = getDolGlobalInt('LEMONFACTURX_STRICT_MODE', 0);
print '<select name="LEMONFACTURX_STRICT_MODE" class="flat">';
print '<option value="0"'.($strict == 0 ? ' selected' : '').'>'.$langs->trans("LemonFacturXStrictModeBestEffort").'</option>';
print '<option value="1"'.($strict == 1 ? ' selected' : '').'>'.$langs->trans("LemonFacturXStrictModeStrict").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// Chemin PHP CLI
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXPhpCliPath");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXPhpCliPathHint").'</span>';
print '</td>';
print '<td>';
print '<input type="text" name="LEMONFACTURX_PHP_CLI_PATH" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('LEMONFACTURX_PHP_CLI_PATH', 'php')).'" placeholder="php ou /usr/bin/php8.2">';
print '</td>';
print '</tr>';

// Mentions légales BR-FR : PMD / PMT / AAB
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LemonFacturXLegalNotes").'</td></tr>';

foreach ([
	['LEMONFACTURX_NOTE_PMD', 'LemonFacturXNotePMD', $defaultPMD],
	['LEMONFACTURX_NOTE_PMT', 'LemonFacturXNotePMT', $defaultPMT],
	['LEMONFACTURX_NOTE_AAB', 'LemonFacturXNoteAAB', $defaultAAB],
] as $note) {
	$val = getDolGlobalString($note[0], $note[2]);
	print '<tr class="oddeven">';
	print '<td>'.$langs->trans($note[1]).'</td>';
	print '<td><textarea name="'.$note[0].'" class="flat minwidth500" rows="3">'.dol_escape_htmltag($val).'</textarea></td>';
	print '</tr>';
}

print '</table>';

print '<br>';
print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

// Info
print '<br>';
print '<div class="info">';
print $langs->trans("LemonFacturXInfo");
print '</div>';

// === Diagnostic des infos obligatoires ===
print '<br>';
print load_fiche_titre($langs->trans("LemonFacturXDiagTitle"), '', '');

$diagErrors = [];
$diagOk = [];

if (empty($mysoc->name)) {
	$diagErrors[] = $langs->trans("LemonFacturXDiagSellerName");
} else {
	$diagOk[] = $langs->trans("LemonFacturXDiagSellerName").' : '.dol_escape_htmltag($mysoc->name);
}

if (empty($mysoc->address) || empty($mysoc->zip) || empty($mysoc->town)) {
	$diagErrors[] = $langs->trans("LemonFacturXDiagSellerAddress");
} else {
	$diagOk[] = $langs->trans("LemonFacturXDiagSellerAddress").' : '.dol_escape_htmltag($mysoc->zip).' '.dol_escape_htmltag($mysoc->town);
}

if (empty($mysoc->tva_intra)) {
	$diagErrors[] = $langs->trans("LemonFacturXDiagSellerVAT");
} else {
	$diagOk[] = $langs->trans("LemonFacturXDiagSellerVAT").' : '.dol_escape_htmltag($mysoc->tva_intra);
}

if (empty($mysoc->idprof2)) {
	$diagErrors[] = $langs->trans("LemonFacturXDiagSellerSIRET").' (BR-FR-10)';
} else {
	$siren = substr(preg_replace('/[^0-9]/', '', $mysoc->idprof2), 0, 9);
	$diagOk[] = $langs->trans("LemonFacturXDiagSellerSIRET").' : SIREN '.dol_escape_htmltag($siren).' (SIRET '.dol_escape_htmltag($mysoc->idprof2).')';
}

if (empty($mysoc->email)) {
	$diagErrors[] = $langs->trans("LemonFacturXDiagSellerEmail").' (BR-FR-13 / BT-34)';
} else {
	$diagOk[] = $langs->trans("LemonFacturXDiagSellerEmail").' : '.dol_escape_htmltag($mysoc->email);
}

$bankId = getDolGlobalInt('LEMONFACTURX_BANK_ACCOUNT');
if ($bankId > 0) {
	$bankCheck = new Account($db);
	if ($bankCheck->fetch($bankId) > 0) {
		if (empty($bankCheck->iban)) {
			$diagErrors[] = $langs->trans("LemonFacturXDiagIBAN");
		} else {
			$diagOk[] = $langs->trans("LemonFacturXDiagIBAN").' : '.substr($bankCheck->iban, 0, 4).'...'.substr($bankCheck->iban, -4);
		}
		if (empty($bankCheck->bic)) {
			$diagErrors[] = $langs->trans("LemonFacturXDiagBIC");
		} else {
			$diagOk[] = $langs->trans("LemonFacturXDiagBIC").' : '.$bankCheck->bic;
		}
	} else {
		$diagErrors[] = $langs->trans("LemonFacturXDiagBankNotFound");
	}
} else {
	$diagErrors[] = $langs->trans("LemonFacturXDiagBankNotSet");
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LemonFacturXDiagResults").'</td></tr>';

foreach ($diagOk as $ok) {
	print '<tr class="oddeven"><td><span style="color: green;">&#10004;</span> '.$ok.'</td><td></td></tr>';
}
foreach ($diagErrors as $err) {
	print '<tr class="oddeven"><td><span style="color: red;">&#10008;</span> <strong>'.$err.'</strong></td>';
	print '<td><a href="'.DOL_URL_ROOT.'/admin/company.php">'.$langs->trans("LemonFacturXDiagFixLink").'</a></td></tr>';
}

if (empty($diagErrors)) {
	print '<tr class="oddeven"><td colspan="2"><span style="color: green;"><strong>'.$langs->trans("LemonFacturXDiagAllOk").'</strong></span></td></tr>';
}

print '</table>';

// === Bloc "À propos de Lemon" — vitrine éditeur ===
print '<div style="margin:30px 0;padding:20px 25px;border:1px solid #e0e0e0;border-left:4px solid #FFD21F;border-radius:6px;background:linear-gradient(135deg,#fffef7 0%,#fafafa 100%);">';
print '<h3 style="margin:0 0 10px 0;color:#333;">'.$langs->trans("LemonFacturXAboutTitle").'</h3>';
print '<p style="margin:0 0 12px 0;color:#555;">'.$langs->trans("LemonFacturXAboutIntro").'</p>';
print '<ul style="margin:0 0 15px 20px;color:#555;">';
print '<li><strong>'.$langs->trans("LemonFacturXAboutSvc1Title").'</strong> : '.$langs->trans("LemonFacturXAboutSvc1Desc").'</li>';
print '<li><strong>'.$langs->trans("LemonFacturXAboutSvc2Title").'</strong> : '.$langs->trans("LemonFacturXAboutSvc2Desc").'</li>';
print '<li><strong>'.$langs->trans("LemonFacturXAboutSvc3Title").'</strong> : '.$langs->trans("LemonFacturXAboutSvc3Desc").'</li>';
print '<li><strong>'.$langs->trans("LemonFacturXAboutSvc4Title").'</strong> : '.$langs->trans("LemonFacturXAboutSvc4Desc").'</li>';
print '</ul>';
print '<p style="margin:0;">';
print '<a href="https://hellolemon.fr" target="_blank" rel="noopener" class="butAction" style="text-decoration:none;">'.$langs->trans("LemonFacturXAboutCTA").'</a>';
print ' <span style="color:#999;margin-left:15px;">'.$langs->trans("LemonFacturXAboutLocation").'</span>';
print '</p>';
print '</div>';

llxFooter();
$db->close();
