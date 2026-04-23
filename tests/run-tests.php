<?php
/*
 * Suite de tests métier LemonFacturX.
 *
 * S'appuie sur les 10 fixtures Factur-X (F001-F010) créées par demo/fixtures.php.
 * Pour chaque cas, génère le XML via lemonfacturx_build_xml() puis vérifie :
 *  - TypeCode documentaire attendu (380/381/386)
 *  - CategoryCode(s) présents (S/K/G/O/E)
 *  - unitCode UN/ECE (C62, HUR, DAY...)
 *  - Présence/absence d'ExemptionReason et URIUniversalCommunication buyer
 *  - Présence/absence de TotalPrepaidAmount et valeur de DuePayableAmount
 *  - Validation XSD Factur-X EN16931
 *
 * Usage :
 *   php tests/run-tests.php
 *   Exit code 0 = tous les tests passent, 1 = au moins un échec
 *
 * Prérequis : Dolibarr de démo avec fixtures.php exécuté (ids 1 à 10 = F001..F010).
 */

// Localisation de master.inc.php : 2 niveaux au-dessus du module, ou DOL_DOCUMENT_ROOT
$candidates = [
	__DIR__.'/../../../master.inc.php',
	'/var/www/dolibarr/htdocs/master.inc.php',
	'/var/www/html/master.inc.php',
];
$loaded = false;
foreach ($candidates as $c) {
	if (file_exists($c)) {
		require_once $c;
		$loaded = true;
		break;
	}
}
if (!$loaded) {
	fwrite(STDERR, "ERROR: master.inc.php introuvable. Lancer depuis un Dolibarr installé.\n");
	exit(2);
}

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once __DIR__.'/../lib/xml_builder.php';

$xsdPath = __DIR__.'/../vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.08_EN16931.xsd';

$cases = [
	1  => ['ref' => 'F001 standard FR 20%',    'typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'exemption' => false, 'prepaid' => null, 'due' => 1200.00],
	2  => ['ref' => 'F002 multi-TVA 20% + 5,5%','typeCode' => '380','mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'exemption' => false, 'prepaid' => null, 'due' => 1727.50],
	3  => ['ref' => 'F003 TVA 0% franchise',   'typeCode' => '380', 'mainCategory' => 'E',  'unitCode' => 'C62', 'buyerUri' => true,  'exemption' => true,  'prepaid' => null, 'due' => 500.00],
	4  => ['ref' => 'F004 avoir',              'typeCode' => '381', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'exemption' => false, 'prepaid' => null, 'due' => 0.00],
	5  => ['ref' => 'F005 ligne en heures',    'typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'HUR', 'buyerUri' => true,  'exemption' => false, 'prepaid' => null, 'due' => 960.00],
	6  => ['ref' => 'F006 ligne en jours',     'typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'DAY', 'buyerUri' => true,  'exemption' => false, 'prepaid' => null, 'due' => 2700.00],
	7  => ['ref' => 'F007 buyer sans email',   'typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => false, 'exemption' => false, 'prepaid' => null, 'due' => 600.00],
	8  => ['ref' => 'F008 UE autoliquidation', 'typeCode' => '380', 'mainCategory' => 'K',  'unitCode' => 'C62', 'buyerUri' => true,  'exemption' => true,  'prepaid' => null, 'due' => 2000.00],
	9  => ['ref' => 'F009 acompte (deposit)',  'typeCode' => '386', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'exemption' => false, 'prepaid' => null, 'due' => 240.00],
	10 => ['ref' => 'F010 finale avec acompte','typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'exemption' => false, 'prepaid' => 240.00, 'due' => 960.00],
];

$totalPassed = 0;
$totalFailed = 0;
$failures = [];

function assertEquals($expected, $actual, $label, &$pass, &$fail, &$failures, $id)
{
	if ($expected === $actual || (is_numeric($expected) && is_numeric($actual) && abs($expected - $actual) < 0.01)) {
		$pass++;
		return;
	}
	$fail++;
	$failures[] = sprintf("F%03d %s : attendu=%s obtenu=%s", $id, $label, var_export($expected, true), var_export($actual, true));
}

function getXmlSingleMatch($pattern, $xml)
{
	return preg_match($pattern, $xml, $m) ? $m[1] : null;
}

echo "=== LemonFacturX - Suite de tests métier ===\n\n";

foreach ($cases as $id => $c) {
	$pass = 0;
	$fail = 0;
	$caseFailures = [];

	$f = new Facture($db);
	if ($f->fetch($id) <= 0) {
		echo "F".str_pad($id,3,'0',STR_PAD_LEFT)." SKIP (fixture absente, lancer demo/fixtures.php)\n";
		continue;
	}
	$f->fetch_thirdparty();
	$xml = lemonfacturx_build_xml($f, $mysoc);

	// Extractions
	$typeCode = getXmlSingleMatch('#<ram:TypeCode>([^<]+)</ram:TypeCode>#', $xml);
	preg_match_all('#<ram:CategoryCode>([^<]+)</ram:CategoryCode>#', $xml, $mCat);
	$categories = array_unique($mCat[1] ?? []);
	preg_match_all('#unitCode="([A-Z0-9]+)"#', $xml, $mUnit);
	$units = array_unique($mUnit[1] ?? []);
	$prepaid = getXmlSingleMatch('#<ram:TotalPrepaidAmount>([0-9.]+)</ram:TotalPrepaidAmount>#', $xml);
	$due = getXmlSingleMatch('#<ram:DuePayableAmount>([0-9.]+)</ram:DuePayableAmount>#', $xml);
	preg_match_all('#<ram:ExemptionReason>([^<]+)</ram:ExemptionReason>#', $xml, $mEx);
	$hasExemption = !empty($mEx[1]);
	$buyerBlock = '';
	if (preg_match('#<ram:BuyerTradeParty>.+?</ram:BuyerTradeParty>#s', $xml, $mB)) {
		$buyerBlock = $mB[0];
	}
	$buyerHasUri = strpos($buyerBlock, '<ram:URIUniversalCommunication>') !== false;

	// Assertions
	assertEquals($c['typeCode'], $typeCode, 'TypeCode', $pass, $fail, $caseFailures, $id);
	assertEquals(true, in_array($c['mainCategory'], $categories, true), 'CategoryCode contient '.$c['mainCategory'], $pass, $fail, $caseFailures, $id);
	assertEquals(true, in_array($c['unitCode'], $units, true), 'unitCode contient '.$c['unitCode'], $pass, $fail, $caseFailures, $id);
	assertEquals($c['buyerUri'], $buyerHasUri, 'BuyerTradeParty URI présent', $pass, $fail, $caseFailures, $id);
	assertEquals($c['exemption'], $hasExemption, 'ExemptionReason présent', $pass, $fail, $caseFailures, $id);
	assertEquals($c['prepaid'] === null ? null : (float) $c['prepaid'], $prepaid === null ? null : (float) $prepaid, 'TotalPrepaidAmount', $pass, $fail, $caseFailures, $id);
	assertEquals((float) $c['due'], $due === null ? null : (float) $due, 'DuePayableAmount', $pass, $fail, $caseFailures, $id);

	// Validation XSD
	libxml_use_internal_errors(true);
	libxml_clear_errors();
	$dom = new DOMDocument();
	$xsdOk = $dom->loadXML($xml) && file_exists($xsdPath) && $dom->schemaValidate($xsdPath);
	if (!$xsdOk) {
		$errs = libxml_get_errors();
		$err = !empty($errs) ? trim($errs[0]->message) : 'unknown';
		$caseFailures[] = sprintf("F%03d XSD validation KO : %s", $id, $err);
		$fail++;
	} else {
		$pass++;
	}
	libxml_clear_errors();

	$totalPassed += $pass;
	$totalFailed += $fail;
	$failures = array_merge($failures, $caseFailures);

	$status = ($fail === 0) ? 'PASS' : 'FAIL';
	printf("F%03d %-35s %s (%d/%d)\n", $id, $c['ref'], $status, $pass, $pass + $fail);
}

echo "\n=== Résultat ===\n";
echo "Passed : $totalPassed\n";
echo "Failed : $totalFailed\n";
if (!empty($failures)) {
	echo "\nDétail des échecs :\n";
	foreach ($failures as $f) {
		echo "  - $f\n";
	}
}
exit($totalFailed > 0 ? 1 : 0);
