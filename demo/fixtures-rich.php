<?php
/*
 * LemonFacturX - Fixtures marketing (6 mois de données IMMORENO SARL)
 *
 * Société : IMMORENO SARL, artisan multiservices à Aurillac (15000),
 * créée en 2015, 3 salariés dont le patron.
 * Période : 2025-10-01 → aujourd'hui.
 * Volumétrie : ~25 tiers (mix pro/particulier), ~15 produits catalogue,
 * ~130 factures, ~50 devis, ~110 paiements, ~35 factures fournisseurs.
 *
 * Usage : php /var/www/dolibarr/htdocs/custom/lemonfacturx/demo/fixtures-rich.php
 *
 * Ce script écrase la société émettrice (MAIN_INFO_SOCIETE_*) et AJOUTE
 * des entités. Les 6 tiers et 10 factures créés par fixtures.php restent.
 */

mt_srand(42); // graine fixe : reproductible

$res = @include "/var/www/dolibarr/htdocs/master.inc.php";
if (!$res) {
	die("Impossible de charger master.inc.php\n");
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

$user = new User($db);
$user->fetch(1);
$user->getrights();

// ============================================================
// 1. SOCIÉTÉ ÉMETTRICE : IMMORENO SARL
// ============================================================
echo "=== Société émettrice IMMORENO SARL (Aurillac) ===\n";
$consts = [
	'MAIN_INFO_SOCIETE_NOM'     => 'IMMORENO SARL',
	'MAIN_INFO_SOCIETE_ADDRESS' => "24 avenue Georges Pompidou",
	'MAIN_INFO_SOCIETE_ZIP'     => '15000',
	'MAIN_INFO_SOCIETE_TOWN'    => 'Aurillac',
	'MAIN_INFO_SOCIETE_COUNTRY' => '1:FR:France',
	'MAIN_INFO_SIREN'           => '812456782',
	'MAIN_INFO_SIRET'           => '81245678200018',
	'MAIN_INFO_TVAINTRA'        => 'FR27812456782', // Nom de constante Dolibarr réel (sans underscore)
	'MAIN_INFO_SOCIETE_MAIL'    => 'contact@immoreno.fr',
	'MAIN_INFO_SOCIETE_TEL'     => '04 71 48 32 10',
	'MAIN_INFO_SOCIETE_WEB'     => 'www.immoreno.fr',
	'MAIN_INFO_SOCIETE_OBJECT'  => 'Artisan multiservices : plomberie, électricité, peinture, rénovation énergétique. 3 salariés.',
	'MAIN_INFO_CAPITAL'         => '15000',
	'MAIN_INFO_SOCIETE_FORME_JURIDIQUE' => '5',
	'MAIN_INFO_RCS'             => 'RCS AURILLAC 812 456 782',
	'SOCIETE_FISCAL_MONTH_START' => '1',
];
foreach ($consts as $k => $v) {
	dolibarr_set_const($db, $k, $v, 'chaine', 0, '', 1);
}
echo "  ".count($consts)." constantes société posées\n";

$bankId = getDolGlobalInt('LEMONFACTURX_BANK_ACCOUNT');
if ($bankId > 0) {
	$sql = "UPDATE ".MAIN_DB_PREFIX."bank_account SET label='Compte pro Crédit Agricole Aurillac', bank='Crédit Agricole Centre France' WHERE rowid=$bankId";
	$db->query($sql);
	echo "  compte bancaire relabélisé\n";

	// Affichage des modes de paiement sur les factures PDF (supprime le warning
	// "mode CHQ défini mais config Facture incomplète"). CHQ=-1 = bénéficiaire
	// = mysoc->name. RIB = id du compte bancaire pour virement.
	dolibarr_set_const($db, 'FACTURE_CHQ_NUMBER', -1, 'chaine', 0, '', 1);
	dolibarr_set_const($db, 'FACTURE_RIB_NUMBER', $bankId, 'chaine', 0, '', 1);
	echo "  FACTURE_CHQ_NUMBER + FACTURE_RIB_NUMBER posées\n";
}

// ============================================================
// 2. PRODUITS / SERVICES CATALOGUE
// ============================================================
echo "=== Catalogue produits/services ===\n";
// Mix produits (matériaux, type=0) et services (main d'oeuvre, type=1)
$products = [
	['P-PLOMB-CHF',  'Chauffe-eau électrique 150L',             450,  10, 0], // produit
	['P-PLOMB-FUI',  'Réparation fuite + remplacement joints',  180,  10, 1], // service
	['P-PLOMB-ROB',  'Pose robinetterie mitigeur',              120,  10, 1], // service
	['P-ELEC-TAB',   'Mise aux normes tableau électrique',      850,  10, 1], // service
	['P-ELEC-DEP',   'Dépannage électricité urgent (forfait)',  150,  10, 1], // service
	['P-ELEC-PRI',   'Pose prise ou interrupteur supplémentaire', 65, 10, 1], // service
	['P-PEINT-PIE',  'Peinture pièce standard (murs + plafond, jusqu\'à 20m²)', 480, 10, 1], // service
	['P-PEINT-FAC',  'Peinture extérieure façade (pot 10L)',     92,  10, 0], // produit
	['P-REV-PARQ',   'Parquet chêne flottant (m²)',              38,  10, 0], // produit
	['P-REV-CAR',    'Carrelage grès cérame (m²)',               52,  10, 0], // produit
	['P-MEN-PORTE',  'Porte intérieure standard H204×L83',       220, 10, 0], // produit
	['P-SDB-RENO',   'Rénovation salle de bain complète',        4800, 10, 1], // service
	['P-ISO-COMB',   'Laine de roche isolation combles (m²)',    28,  5.5, 0], // produit
	['P-PAC-INST',   'Pompe à chaleur air/eau 8 kW',             3200, 5.5, 0], // produit
	['P-ENTR-FORF',  'Contrat entretien annuel multiservice',    280, 20, 1], // service
];
$productIds = [];
foreach ($products as $p) {
	$prod = new Product($db);
	$prod->ref             = $p[0];
	$prod->label           = $p[1];
	$prod->price           = $p[2];
	$prod->price_base_type = 'HT';
	$prod->tva_tx          = $p[3];
	$prod->type            = $p[4];
	$prod->status          = 1;
	$prod->status_buy      = 0;
	$id = $prod->create($user);
	if ($id > 0) {
		$productIds[$p[0]] = $id;
	}
}
echo "  ".count($productIds)." produits créés\n";

// ============================================================
// 3. TIERS CLIENTS (25) : mix Aurillac et environs
// ============================================================
echo "=== Tiers clients (Aurillac et environs) ===\n";

function createClient($db, $user, $p)
{
	$s = new Societe($db);
	$s->name       = $p['name'];
	$s->client     = 1;
	$s->address    = $p['address']   ?? '';
	$s->zip        = $p['zip']       ?? '15000';
	$s->town       = $p['town']      ?? 'Aurillac';
	$s->country_id = 1;
	$s->email      = $p['email']     ?? '';
	$s->phone      = $p['phone']     ?? '';
	$s->idprof2    = $p['idprof2']   ?? '';
	$s->tva_intra  = $p['tva_intra'] ?? '';
	$s->tva_assuj  = empty($p['part']) ? 1 : 0;
	$id = $s->create($user);
	if ($id <= 0) {
		echo "  ERR ".$p['name']." : ".implode(', ', $s->errors)."\n";
		return 0;
	}
	return $id;
}

// 12 particuliers (Aurillac et communes voisines)
$particuliers = [
	['name'=>'M. et Mme LECLERC',   'address'=>'14 rue de la Coste',          'zip'=>'15000', 'town'=>'Aurillac',            'email'=>'jp.leclerc@gmail.com',         'phone'=>'06 12 34 56 78', 'part'=>1],
	['name'=>'Mme BERNARD Sophie',   'address'=>'27 rue Émile Duclaux',       'zip'=>'15000', 'town'=>'Aurillac',            'email'=>'sophie.bernard@orange.fr',     'phone'=>'06 23 45 67 89', 'part'=>1],
	['name'=>'M. AUBRY Thomas',      'address'=>'5 rue du Rieu',              'zip'=>'15130', 'town'=>'Arpajon-sur-Cère',    'email'=>'thomas.aubry@free.fr',         'phone'=>'06 34 56 78 90', 'part'=>1],
	['name'=>'Famille DESCHAMPS',    'address'=>'42 route de Saint-Flour',    'zip'=>'15000', 'town'=>'Aurillac',            'email'=>'deschamps.famille@gmail.com',  'phone'=>'06 45 67 89 01', 'part'=>1],
	['name'=>'Mme LOPEZ Maria',      'address'=>'9 place du Square',          'zip'=>'15000', 'town'=>'Aurillac',            'email'=>'maria.lopez@sfr.fr',           'phone'=>'06 56 78 90 12', 'part'=>1],
	['name'=>'M. FAURE Olivier',     'address'=>'18 rue des Carmes',          'zip'=>'15130', 'town'=>'Ytrac',               'email'=>'o.faure@laposte.net',          'phone'=>'06 67 89 01 23', 'part'=>1],
	['name'=>'Mme KOUASSI Aminata',  'address'=>'36 avenue du Général Leclerc','zip'=>'15000', 'town'=>'Aurillac',            'email'=>'a.kouassi@gmail.com',          'phone'=>'06 78 90 12 34', 'part'=>1],
	['name'=>'M. TRAN Van',          'address'=>'11 rue du Buis',             'zip'=>'15250', 'town'=>'Jussac',              'email'=>'van.tran@wanadoo.fr',          'phone'=>'06 89 01 23 45', 'part'=>1],
	['name'=>'Famille RIVIÈRE',      'address'=>'23 chemin des Fontilles',    'zip'=>'15130', 'town'=>'Naucelles',           'email'=>'riviere@bbox.fr',              'phone'=>'06 90 12 34 56', 'part'=>1],
	['name'=>'Mme AIT-BELKACEM Samira', 'address'=>'7 rue du Monastère',      'zip'=>'15000', 'town'=>'Aurillac',            'email'=>'samira.ab@yahoo.fr',           'phone'=>'06 01 23 45 67', 'part'=>1],
	['name'=>'M. et Mme GRAND',      'address'=>'85 avenue de la République', 'zip'=>'15000', 'town'=>'Aurillac',            'email'=>'jc.grand@gmail.com',           'phone'=>'06 12 23 34 45', 'part'=>1],
	['name'=>'M. LAURENT David',     'address'=>'3 place Gerbert',            'zip'=>'15000', 'town'=>'Aurillac',            'email'=>'david.laurent@gmail.com',      'phone'=>'06 98 76 54 32', 'part'=>1],
];

// 10 pros
$pros = [
	['name'=>'Boulangerie Les Blés d\'Or',         'address'=>'18 rue des Forgerons',        'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'contact@blesdor-aurillac.fr', 'phone'=>'04 71 48 12 45', 'idprof2'=>'45287651200022', 'tva_intra'=>'FR98452876512'],
	['name'=>'Cabinet Dr BERNARD & Associés',      'address'=>'22 avenue de la République',  'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'secretariat@cabinet-bernard.fr', 'phone'=>'04 71 43 30 00', 'idprof2'=>'51234567800011', 'tva_intra'=>'FR27512345678'],
	['name'=>'Restaurant Le Pommereuil',           'address'=>'5 place Gerbert',             'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'contact@le-pommereuil.fr',    'phone'=>'04 71 48 65 43', 'idprof2'=>'49876543200017', 'tva_intra'=>'FR38498765432'],
	['name'=>'Pharmacie Centrale Aurillac',        'address'=>'57 avenue de la Libération',  'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'pharmacie.centrale15@offi.fr', 'phone'=>'04 71 48 10 10', 'idprof2'=>'38765432100029', 'tva_intra'=>'FR52387654321'],
	['name'=>'Coiffure Studio C',                  'address'=>'14 rue des Frères Delmas',    'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'contact@studioc-coiffure.fr', 'phone'=>'04 71 48 88 12', 'idprof2'=>'67890123400013', 'tva_intra'=>'FR79678901234'],
	['name'=>'Syndic CANTAL Gestion',              'address'=>'12 cours d\'Angoulême',       'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'gestion@cantal-gestion.fr',   'phone'=>'04 71 48 30 00', 'idprof2'=>'78901234500014', 'tva_intra'=>'FR12789012345'],
	['name'=>'Syndic COPROGÉRANCE AUVERGNE',       'address'=>'67 avenue des Volontaires',   'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'contact@coprogerance-auv.fr', 'phone'=>'04 71 48 50 60', 'idprof2'=>'23456789100035', 'tva_intra'=>'FR83234567891'],
	['name'=>'Garage Aurillac Auto',               'address'=>'8 route de Clermont-Ferrand', 'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'contact@aurillac-auto.fr',    'phone'=>'04 71 48 50 50', 'idprof2'=>'34567891200026', 'tva_intra'=>'FR45345678912'],
	['name'=>'Agence Immobilière Volcans',         'address'=>'95 avenue Georges Pompidou',  'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'contact@volcans-immo.fr',     'phone'=>'04 71 48 20 00', 'idprof2'=>'89012345600020', 'tva_intra'=>'FR67890123456'],
	['name'=>'Cantal Assurances',                  'address'=>'25 rue du Rieu',              'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'sinistres@cantal-assurances.fr', 'phone'=>'04 71 48 80 90', 'idprof2'=>'90123456700023', 'tva_intra'=>'FR89901234567'],
];

// 3 divers (asso, école, paroisse)
$divers = [
	['name'=>'Association Les P\'tits Loups du Cantal', 'address'=>'11 rue des Frères',        'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'contact@ptitsloups-cantal.org', 'phone'=>'04 71 48 44 44', 'idprof2'=>'44556677800012'],
	['name'=>'Club Pétanque Aurillacoise',             'address'=>'Place des Fontilles',      'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'petanque.aurillac@gmail.com',   'phone'=>'04 71 48 71 10', 'idprof2'=>'55667788900028'],
	['name'=>'Paroisse Notre-Dame-aux-Neiges',         'address'=>'Place Saint-Géraud',       'zip'=>'15000', 'town'=>'Aurillac',         'email'=>'accueil@nd-neiges-aurillac.fr', 'phone'=>'04 71 48 10 30', 'idprof2'=>'66778899000015'],
];

$clientIds = [];
foreach (array_merge($particuliers, $pros, $divers) as $c) {
	$id = createClient($db, $user, $c);
	if ($id > 0) {
		$clientIds[] = ['id' => $id, 'name' => $c['name'], 'part' => !empty($c['part'])];
	}
}
echo "  ".count($clientIds)." clients créés (".count($particuliers)." particuliers + ".count($pros)." pros + ".count($divers)." divers)\n";

// Fournisseurs locaux
echo "=== Tiers fournisseurs ===\n";
$fournisseurs_data = [
	['name'=>'POINT P Matériaux Aurillac',   'address'=>'ZA du Puy Griou',             'zip'=>'15000', 'town'=>'Aurillac',       'email'=>'aurillac@pointp.fr',     'idprof2'=>'57902111100017'],
	['name'=>'BRICOMARCHÉ Aurillac',         'address'=>'Route d\'Arpajon',            'zip'=>'15000', 'town'=>'Aurillac',       'email'=>'aurillac@bricomarche.fr','idprof2'=>'38453627800042'],
	['name'=>'CEDEO Sanitaire Chauffage',    'address'=>'12 rue de l\'Industrie',      'zip'=>'15000', 'town'=>'Aurillac',       'email'=>'aurillac@cedeo.fr',      'idprof2'=>'30210333400019'],
	['name'=>'REXEL Électricité',            'address'=>'ZA de Sistrières',            'zip'=>'15000', 'town'=>'Aurillac',       'email'=>'aurillac@rexel.fr',      'idprof2'=>'30919555500044'],
	['name'=>'Station TOTAL Arpajon',        'address'=>'Route de Figeac',             'zip'=>'15130', 'town'=>'Arpajon-sur-Cère','email'=>'arpajon@total.fr',      'idprof2'=>'54202102800024'],
	['name'=>'Cabinet Expert-Comptable MARTINEAU', 'address'=>'14 place du Square',    'zip'=>'15000', 'town'=>'Aurillac',       'email'=>'contact@ec-martineau.fr','idprof2'=>'44012345600013'],
	['name'=>'URSSAF Auvergne',              'address'=>'6 rue Pélissier',             'zip'=>'15000', 'town'=>'Aurillac',       'email'=>'contact@urssaf-auv.fr',  'idprof2'=>'18000000000000'],
	['name'=>'SCI LES VOLCANS (bailleur local)', 'address'=>'24 avenue Georges Pompidou', 'zip'=>'15000', 'town'=>'Aurillac',     'email'=>'sci.lesvolcans@gmail.com','idprof2'=>'80123456700019'],
];
$fournIds = [];
foreach ($fournisseurs_data as $f) {
	$s = new Societe($db);
	$s->name        = $f['name'];
	$s->fournisseur = 1;
	$s->client      = 0;
	$s->address     = $f['address'];
	$s->zip         = $f['zip'];
	$s->town        = $f['town'];
	$s->country_id  = 1;
	$s->email       = $f['email'];
	$s->idprof2     = $f['idprof2'];
	$s->tva_assuj   = 1;
	$id = $s->create($user);
	if ($id > 0) {
		$fournIds[] = $id;
	}
}
echo "  ".count($fournIds)." fournisseurs créés\n";

// ============================================================
// 4. GÉNÉRATION CHRONOLOGIQUE DES ÉVÉNEMENTS
// ============================================================
$factures_par_mois = ['2025-10' => 18, '2025-11' => 22, '2025-12' => 12, '2026-01' => 15, '2026-02' => 20, '2026-03' => 28, '2026-04' => 15];
$devis_par_mois    = ['2025-10' => 8,  '2025-11' => 9,  '2025-12' => 4,  '2026-01' => 7,  '2026-02' => 8,  '2026-03' => 10, '2026-04' => 5];
$ff_par_mois       = ['2025-10' => 6,  '2025-11' => 6,  '2025-12' => 4,  '2026-01' => 5,  '2026-02' => 5,  '2026-03' => 6,  '2026-04' => 4];

function randDayInMonth($ym, $skipSunday = true) {
	$lastDay = (int) date('t', strtotime("$ym-01"));
	$cap = (date('Y-m', time()) === $ym) ? (int) date('d') : $lastDay;
	if ($cap < 1) $cap = 1;
	do {
		$d = mt_rand(1, $cap);
		$ts = strtotime(sprintf('%s-%02d', $ym, $d));
	} while ($skipSunday && (int) date('N', $ts) === 7);
	return $ts;
}

// Les signatures Facture::addline et Propal::addline divergent à partir du 9e paramètre.
// Facture : ..., $fk_product, $remise, $date_start, $date_end, $ventil, $info_bits, $fk_remise_except, $price_base_type='HT', ...
// Propal  : ..., $fk_product, $remise, $price_base_type='HT', $pu_ttc, $info_bits, $type, $rang, ...
// D'où deux helpers distincts.
function pickLinesArtisan($isPart, $productsRef, $productIds) {
	if ($isPart) {
		$candidates = ['P-PLOMB-CHF','P-PLOMB-FUI','P-PLOMB-ROB','P-ELEC-DEP','P-ELEC-PRI','P-PEINT-PIE','P-REV-PARQ','P-REV-CAR','P-MEN-PORTE','P-SDB-RENO','P-ISO-COMB','P-PAC-INST','P-ELEC-TAB'];
	} else {
		$candidates = ['P-ENTR-FORF','P-PLOMB-FUI','P-ELEC-DEP','P-PEINT-PIE','P-MEN-PORTE','P-REV-CAR','P-ELEC-TAB','P-PLOMB-ROB','P-PEINT-FAC'];
	}
	$out = [];
	$nb = mt_rand(1, 3);
	for ($i = 0; $i < $nb; $i++) {
		$ref = $candidates[array_rand($candidates)];
		$pid = $productIds[$ref] ?? 0;
		$p = null;
		foreach ($productsRef as $prd) {
			if ($prd[0] === $ref) { $p = $prd; break; }
		}
		if (!$p) continue;
		$tva = $isPart ? $p[3] : (($p[3] == 5.5) ? 5.5 : 20);
		$qty = 1;
		if (in_array($ref, ['P-PEINT-FAC', 'P-REV-PARQ', 'P-REV-CAR', 'P-ISO-COMB'])) {
			$qty = mt_rand(8, 60);
		}
		$remise = (mt_rand(1, 10) > 8) ? mt_rand(5, 10) : 0;
		$out[] = ['desc' => $p[1], 'pu' => $p[2], 'qty' => $qty, 'tva' => $tva, 'fk_product' => $pid, 'remise' => $remise];
	}
	return $out;
}

function addLignesFacture(&$facture, $lines) {
	foreach ($lines as $l) {
		$facture->addline($l['desc'], $l['pu'], $l['qty'], $l['tva'], 0, 0, $l['fk_product'], $l['remise'], '', '', 0, 0, '', 'HT', 0, 0, -1, 0, '', 0, 0, null, 0, '', 0, 100, 0, null);
	}
}

function addLignesPropal(&$propal, $lines) {
	foreach ($lines as $l) {
		$propal->addline($l['desc'], $l['pu'], $l['qty'], $l['tva'], 0, 0, $l['fk_product'], $l['remise'], 'HT', 0, 0, 0, -1, 0, 0, 0, 0, '', '', '', [], null, '', 0, 0, 0, 0);
	}
}

// ============================================================
// 5. DEVIS
// ============================================================
echo "=== Devis ===\n";
$nbDevis = 0;
$devisSignes = [];
foreach ($devis_par_mois as $ym => $nb) {
	for ($i = 0; $i < $nb; $i++) {
		$cli = $clientIds[array_rand($clientIds)];
		$date = randDayInMonth($ym);
		$propal = new Propal($db);
		$propal->socid = $cli['id'];
		$propal->date = $date;
		$propal->datep = $date;
		$propal->duree_validite = 30;
		$propal->fin_validite = $date + 30*86400;
		$propal->cond_reglement_id = 1;
		$propal->mode_reglement_id = 7;
		$id = $propal->create($user);
		if ($id <= 0) { continue; }
		addLignesPropal($propal, pickLinesArtisan($cli['part'], $products, $productIds));
		$propal->fetch($id);
		$propal->valid($user);
		$r = mt_rand(1, 100);
		if ($r <= 80) {
			$propal->closeProposal($user, 2);
			$devisSignes[] = ['id' => $id, 'cli' => $cli, 'date' => $date];
		} elseif ($r > 92) {
			$propal->closeProposal($user, 3);
		}
		$nbDevis++;
	}
}
echo "  $nbDevis devis créés (".count($devisSignes)." signés)\n";

// ============================================================
// 6. FACTURES CLIENTS
// ============================================================
echo "=== Factures clients ===\n";
$facturesIds = [];
$nbFact = 0;
foreach ($factures_par_mois as $ym => $nb) {
	for ($i = 0; $i < $nb; $i++) {
		if ((mt_rand(1, 100) <= 30) && count($devisSignes) > 0) {
			$src = array_shift($devisSignes);
			$cli = $src['cli'];
			$date = max($src['date'] + mt_rand(7, 20)*86400, strtotime("$ym-01"));
			if (date('Y-m', $date) !== $ym) { $date = randDayInMonth($ym); }
		} else {
			$cli = $clientIds[array_rand($clientIds)];
			$date = randDayInMonth($ym);
		}
		$f = new Facture($db);
		$f->socid              = $cli['id'];
		$f->type               = 0;
		$f->date               = $date;
		$f->date_lim_reglement = $date + 30*86400;
		$f->cond_reglement_id  = 1;
		$f->mode_reglement_id  = (mt_rand(1, 100) <= 50) ? 7 : 2;
		$id = $f->create($user);
		if ($id <= 0) { continue; }
		addLignesFacture($f, pickLinesArtisan($cli['part'], $products, $productIds));
		$f->fetch($id);
		$f->validate($user);
		$facturesIds[] = ['id' => $id, 'date' => $date, 'total_ttc' => $f->total_ttc, 'cli' => $cli, 'mode' => $f->mode_reglement_id];
		$nbFact++;
	}
}
echo "  $nbFact factures clients créées\n";

// ============================================================
// 7. PAIEMENTS (85% soldé, 10% partiel, 5% impayé)
// ============================================================
echo "=== Paiements ===\n";
$nbPay = 0;
foreach ($facturesIds as $fx) {
	$r = mt_rand(1, 100);
	if ($r > 95) continue;
	$paiement = new Paiement($db);
	$paiement->datepaye = $fx['date'] + mt_rand(3, 35) * 86400;
	if ($paiement->datepaye > time()) {
		$paiement->datepaye = time() - 86400;
	}
	$amount = ($r > 85) ? round($fx['total_ttc'] * 0.5, 2) : $fx['total_ttc'];
	$paiement->amounts = [$fx['id'] => $amount];
	$paiement->paiementid = $fx['mode'];
	$paiement->num_payment = ($fx['mode'] == 7) ? ('CHQ'.mt_rand(1000000, 9999999)) : '';
	$payId = $paiement->create($user, 1);
	if ($payId > 0 && $bankId > 0) {
		$paiement->addPaymentToBank($user, 'payment', '(Paiement facture '.$fx['id'].')', $bankId, '', '');
		$nbPay++;
	}
}
echo "  $nbPay paiements créés\n";

// ============================================================
// 8. FACTURES FOURNISSEURS
// ============================================================
echo "=== Factures fournisseurs ===\n";
$nbFF = 0;
$lignesFournisseurs = [
	['Matériaux chantier (ciment, plâtre, joints)', [200, 900], 20, 0],
	['Quincaillerie et outillage',                  [50, 350],  20, 1],
	['Sanitaire et chauffage',                      [300, 1500], 20, 2],
	['Fournitures électriques',                     [100, 800], 20, 3],
	['Carburant véhicules de chantier',             [80, 180],  20, 4],
	['Honoraires expertise comptable mensuelle',    [180, 180], 20, 5],
	['Cotisations sociales URSSAF',                 [2200, 2800], 0, 6],
	['Loyer local commercial',                      [850, 850], 20, 7],
];
foreach ($ff_par_mois as $ym => $nb) {
	for ($i = 0; $i < $nb; $i++) {
		$spec = $lignesFournisseurs[array_rand($lignesFournisseurs)];
		$date = randDayInMonth($ym, false);
		$ff = new FactureFournisseur($db);
		$ff->socid             = $fournIds[$spec[3]];
		$ff->ref_supplier      = 'FRN-'.date('ym', $date).'-'.mt_rand(1000, 9999);
		$ff->date              = $date;
		$ff->datef             = $date;
		$ff->date_echeance     = $date + 30*86400;
		$ff->cond_reglement_id = 1;
		$ff->mode_reglement_id = 2;
		$id = $ff->create($user);
		if ($id <= 0) { continue; }
		$pu = mt_rand($spec[1][0], $spec[1][1]);
		// Signature FactureFournisseur::addline() : qty en 6e position, pas 3e (différent de Facture::addline()).
		$ff->addline($spec[0], $pu, $spec[2], 0, 0, 1, 0, 0, '', '', 0, 0, 'HT', 0, -1, 0, [], null, 0, 0, '', 0, 0, 0);
		$ff->fetch($id);
		$ff->validate($user);
		$nbFF++;
	}
}
echo "  $nbFF factures fournisseurs créées\n";

echo "\n=== Récap ===\n";
echo "- Société émettrice : IMMORENO SARL (SIREN 812456782, Aurillac 15000)\n";
echo "- 3 salariés dont le patron\n";
echo "- Période : 2025-10-01 → ".date('Y-m-d', time())."\n";
echo "- Produits : ".count($productIds)." | Clients : ".count($clientIds)." | Fournisseurs : ".count($fournIds)."\n";
echo "- Devis : $nbDevis | Factures clients : $nbFact | Paiements : $nbPay | Factures fournisseurs : $nbFF\n";
echo "\nFini. http://ard.hellolemon.dev (axel / 0000)\n";
