<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Générateur XML CrossIndustryInvoice EN16931 pour Factur-X
 * Conforme aux règles BR-FR (XP Z12-012 V1.2.0)
 */

/**
 * Génère le XML Factur-X EN16931 à partir d'une facture Dolibarr
 *
 * @param Facture $invoice   Objet facture Dolibarr (avec lines chargées)
 * @param Societe $mysoc     Société émettrice (vendeur)
 * @return string            XML CrossIndustryInvoice
 */
function lemonfacturx_build_xml($invoice, $mysoc)
{
	global $conf;

	$typeCode = lemonfacturx_resolve_document_type($invoice);
	$issueDate = date('Ymd', $invoice->date);
	$dueDate = !empty($invoice->date_lim_reglement) ? date('Ymd', $invoice->date_lim_reglement) : $issueDate;
	$currency = !empty($conf->currency) ? $conf->currency : 'EUR';

	// Vendeur
	$sellerCountry = !empty($mysoc->country_code) ? $mysoc->country_code : 'FR';
	$sellerVat = $mysoc->tva_intra ?? '';
	$sellerSiren = lemonfacturx_extract_siren($mysoc->idprof2 ?? '');
	$sellerEmail = $mysoc->email ?? '';

	// Acheteur
	$buyer = $invoice->thirdparty;
	$buyerCountry = !empty($buyer->country_code) ? $buyer->country_code : 'FR';
	$buyerVat = $buyer->tva_intra ?? '';
	$buyerSiren = lemonfacturx_extract_siren($buyer->idprof2 ?? '');
	$buyerEmail = lemonfacturx_get_buyer_email($buyer, $invoice->db);

	// IBAN/BIC depuis le compte bancaire configuré
	$iban = '';
	$bic = '';
	$bankAccountId = getDolGlobalInt('LEMONFACTURX_BANK_ACCOUNT');
	if ($bankAccountId > 0) {
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$bankAccount = new Account($invoice->db);
		if ($bankAccount->fetch($bankAccountId) > 0) {
			$iban = str_replace(' ', '', $bankAccount->iban);
			$bic = str_replace(' ', '', $bankAccount->bic);
		}
	}
	$paymentMeans = getDolGlobalString('LEMONFACTURX_PAYMENT_MEANS', '30');

	// Construction XML
	$xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$xml .= '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"';
	$xml .= ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"';
	$xml .= ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"';
	$xml .= ' xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100">'."\n";

	// === ExchangedDocumentContext ===
	$xml .= '<rsm:ExchangedDocumentContext>'."\n";
	$xml .= '  <ram:GuidelineSpecifiedDocumentContextParameter>'."\n";
	$xml .= '    <ram:ID>urn:cen.eu:en16931:2017</ram:ID>'."\n";
	$xml .= '  </ram:GuidelineSpecifiedDocumentContextParameter>'."\n";
	$xml .= '</rsm:ExchangedDocumentContext>'."\n";

	// === ExchangedDocument ===
	$xml .= '<rsm:ExchangedDocument>'."\n";
	$xml .= '  <ram:ID>'.xmlEncode($invoice->ref).'</ram:ID>'."\n";
	$xml .= '  <ram:TypeCode>'.xmlEncode($typeCode).'</ram:TypeCode>'."\n";
	$xml .= '  <ram:IssueDateTime>'."\n";
	$xml .= '    <udt:DateTimeString format="102">'.xmlEncode($issueDate).'</udt:DateTimeString>'."\n";
	$xml .= '  </ram:IssueDateTime>'."\n";

	// BR-FR-05 : Mentions légales obligatoires (BG-3 IncludedNote)
	$notePMD = getDolGlobalString('LEMONFACTURX_NOTE_PMD', 'En cas de retard de paiement, une pénalité égale à 3 fois le taux d\'intérêt légal sera exigible (article L.441-10 du Code de commerce).');
	$notePMT = getDolGlobalString('LEMONFACTURX_NOTE_PMT', 'Une indemnité forfaitaire de 40 euros sera exigible pour frais de recouvrement en cas de retard de paiement.');
	$noteAAB = getDolGlobalString('LEMONFACTURX_NOTE_AAB', 'Pas d\'escompte pour paiement anticipé.');

	$xml .= '  <ram:IncludedNote>'."\n";
	$xml .= '    <ram:Content>'.xmlEncode($notePMD).'</ram:Content>'."\n";
	$xml .= '    <ram:SubjectCode>PMD</ram:SubjectCode>'."\n";
	$xml .= '  </ram:IncludedNote>'."\n";
	$xml .= '  <ram:IncludedNote>'."\n";
	$xml .= '    <ram:Content>'.xmlEncode($notePMT).'</ram:Content>'."\n";
	$xml .= '    <ram:SubjectCode>PMT</ram:SubjectCode>'."\n";
	$xml .= '  </ram:IncludedNote>'."\n";
	$xml .= '  <ram:IncludedNote>'."\n";
	$xml .= '    <ram:Content>'.xmlEncode($noteAAB).'</ram:Content>'."\n";
	$xml .= '    <ram:SubjectCode>AAB</ram:SubjectCode>'."\n";
	$xml .= '  </ram:IncludedNote>'."\n";

	$xml .= '</rsm:ExchangedDocument>'."\n";

	// === SupplyChainTradeTransaction ===
	$xml .= '<rsm:SupplyChainTradeTransaction>'."\n";

	$xmlLineNum = 0;
	foreach ($invoice->lines as $line) {
		// Ignorer les lignes sans montant (descriptions, titres, sous-totaux)
		if ((float) $line->qty == 0 && (float) $line->total_ht == 0) {
			continue;
		}
		$xmlLineNum++;
		$xml .= lemonfacturx_build_line_xml($line, $xmlLineNum, $currency, $invoice, $buyer, $mysoc);
	}

	$xml .= '  <ram:ApplicableHeaderTradeAgreement>'."\n";

	$xml .= lemonfacturx_build_trade_party_xml('Seller', $mysoc->name, $mysoc->address, $mysoc->zip, $mysoc->town, $sellerCountry, $sellerVat, $sellerSiren, $sellerEmail);
	$xml .= lemonfacturx_build_trade_party_xml('Buyer', $buyer->name, $buyer->address ?? '', $buyer->zip ?? '', $buyer->town ?? '', $buyerCountry, $buyerVat, $buyerSiren, $buyerEmail);

	$xml .= '  </ram:ApplicableHeaderTradeAgreement>'."\n";

	$xml .= '  <ram:ApplicableHeaderTradeDelivery>'."\n";
	$xml .= '    <ram:ActualDeliverySupplyChainEvent>'."\n";
	$xml .= '      <ram:OccurrenceDateTime>'."\n";
	$xml .= '        <udt:DateTimeString format="102">'.xmlEncode($issueDate).'</udt:DateTimeString>'."\n";
	$xml .= '      </ram:OccurrenceDateTime>'."\n";
	$xml .= '    </ram:ActualDeliverySupplyChainEvent>'."\n";
	$xml .= '  </ram:ApplicableHeaderTradeDelivery>'."\n";
	$xml .= '  <ram:ApplicableHeaderTradeSettlement>'."\n";
	$xml .= '    <ram:InvoiceCurrencyCode>'.xmlEncode($currency).'</ram:InvoiceCurrencyCode>'."\n";

	$xml .= '    <ram:SpecifiedTradeSettlementPaymentMeans>'."\n";
	$xml .= '      <ram:TypeCode>'.xmlEncode($paymentMeans).'</ram:TypeCode>'."\n";
	if (!empty($iban)) {
		$xml .= '      <ram:PayeePartyCreditorFinancialAccount>'."\n";
		$xml .= '        <ram:IBANID>'.xmlEncode($iban).'</ram:IBANID>'."\n";
		$xml .= '      </ram:PayeePartyCreditorFinancialAccount>'."\n";
		if (!empty($bic)) {
			$xml .= '      <ram:PayeeSpecifiedCreditorFinancialInstitution>'."\n";
			$xml .= '        <ram:BICID>'.xmlEncode($bic).'</ram:BICID>'."\n";
			$xml .= '      </ram:PayeeSpecifiedCreditorFinancialInstitution>'."\n";
		}
	}
	$xml .= '    </ram:SpecifiedTradeSettlementPaymentMeans>'."\n";

	$taxBreakdown = lemonfacturx_get_tax_breakdown($invoice, $buyer, $mysoc);
	foreach ($taxBreakdown as $rate => $amounts) {
		$xml .= '    <ram:ApplicableTradeTax>'."\n";
		$xml .= '      <ram:CalculatedAmount>'.formatAmount($amounts['tax']).'</ram:CalculatedAmount>'."\n";
		$xml .= '      <ram:TypeCode>VAT</ram:TypeCode>'."\n";
		// ExemptionReason émis pour toute catégorie non-standard quand un motif est disponible
		if (!empty($amounts['exemption']) && in_array($amounts['categoryCode'], ['E', 'K', 'G', 'O', 'Z'], true)) {
			$xml .= '      <ram:ExemptionReason>'.xmlEncode($amounts['exemption']).'</ram:ExemptionReason>'."\n";
		}
		$xml .= '      <ram:BasisAmount>'.formatAmount($amounts['base']).'</ram:BasisAmount>'."\n";
		$xml .= '      <ram:CategoryCode>'.xmlEncode($amounts['categoryCode']).'</ram:CategoryCode>'."\n";
		$xml .= '      <ram:RateApplicablePercent>'.formatAmount($rate).'</ram:RateApplicablePercent>'."\n";
		$xml .= '    </ram:ApplicableTradeTax>'."\n";
	}

	$xml .= '    <ram:SpecifiedTradePaymentTerms>'."\n";
	$xml .= '      <ram:DueDateDateTime>'."\n";
	$xml .= '        <udt:DateTimeString format="102">'.xmlEncode($dueDate).'</udt:DateTimeString>'."\n";
	$xml .= '      </ram:DueDateDateTime>'."\n";
	$xml .= '    </ram:SpecifiedTradePaymentTerms>'."\n";

	$xml .= lemonfacturx_build_monetary_summation_xml($invoice, $currency);

	$xml .= '  </ram:ApplicableHeaderTradeSettlement>'."\n";

	$xml .= '</rsm:SupplyChainTradeTransaction>'."\n";
	$xml .= '</rsm:CrossIndustryInvoice>';

	return $xml;
}

/**
 * Génère le XML d'une ligne de facture
 *
 * @param object $line        Ligne de facture Dolibarr
 * @param int    $lineNum     Numéro de ligne séquentiel
 * @param string $currency    Devise (ex: EUR)
 * @param object $invoice     Facture Dolibarr (nécessaire pour déterminer la catégorie TVA)
 * @param object $thirdparty  Tiers acheteur
 * @param object $mysoc       Société émettrice
 * @return string XML
 */
function lemonfacturx_build_line_xml($line, $lineNum, $currency, $invoice, $thirdparty, $mysoc)
{
	$xml = '  <ram:IncludedSupplyChainTradeLineItem>'."\n";

	$xml .= '    <ram:AssociatedDocumentLineDocument>'."\n";
	$xml .= '      <ram:LineID>'.xmlEncode((string) $lineNum).'</ram:LineID>'."\n";
	$xml .= '    </ram:AssociatedDocumentLineDocument>'."\n";

	$desc = trim(strip_tags($line->desc ?: ($line->description ?? $line->label ?? '')));
	if (empty($desc)) {
		$desc = 'Article';
	}

	$xml .= '    <ram:SpecifiedTradeProduct>'."\n";
	$xml .= '      <ram:Name>'.xmlEncode($desc).'</ram:Name>'."\n";
	$xml .= '    </ram:SpecifiedTradeProduct>'."\n";

	$qty       = (float) $line->qty;
	$vatRate   = (float) $line->tva_tx;
	$lineTotal = (float) $line->total_ht;
	$unitCode  = lemonfacturx_map_unit_code($line);
	$taxCat    = lemonfacturx_resolve_tax_category($line, $invoice, $thirdparty, $mysoc);
	// Prix net unitaire : total_ht / qty pour tenir compte des remises
	$unitPrice = ($qty != 0) ? $lineTotal / $qty : (float) $line->subprice;

	$xml .= '    <ram:SpecifiedLineTradeAgreement>'."\n";
	$xml .= '      <ram:NetPriceProductTradePrice>'."\n";
	$xml .= '        <ram:ChargeAmount>'.formatAmount($unitPrice).'</ram:ChargeAmount>'."\n";
	$xml .= '      </ram:NetPriceProductTradePrice>'."\n";
	$xml .= '    </ram:SpecifiedLineTradeAgreement>'."\n";

	$xml .= '    <ram:SpecifiedLineTradeDelivery>'."\n";
	$xml .= '      <ram:BilledQuantity unitCode="'.xmlEncode($unitCode).'">'.formatAmount($qty).'</ram:BilledQuantity>'."\n";
	$xml .= '    </ram:SpecifiedLineTradeDelivery>'."\n";

	$xml .= '    <ram:SpecifiedLineTradeSettlement>'."\n";
	$xml .= '      <ram:ApplicableTradeTax>'."\n";
	$xml .= '        <ram:TypeCode>VAT</ram:TypeCode>'."\n";
	$xml .= '        <ram:CategoryCode>'.xmlEncode($taxCat['code']).'</ram:CategoryCode>'."\n";
	$xml .= '        <ram:RateApplicablePercent>'.formatAmount($vatRate).'</ram:RateApplicablePercent>'."\n";
	$xml .= '      </ram:ApplicableTradeTax>'."\n";
	$xml .= '      <ram:SpecifiedTradeSettlementLineMonetarySummation>'."\n";
	$xml .= '        <ram:LineTotalAmount>'.formatAmount($lineTotal).'</ram:LineTotalAmount>'."\n";
	$xml .= '      </ram:SpecifiedTradeSettlementLineMonetarySummation>'."\n";
	$xml .= '    </ram:SpecifiedLineTradeSettlement>'."\n";

	$xml .= '  </ram:IncludedSupplyChainTradeLineItem>'."\n";

	return $xml;
}

/**
 * Calcule la ventilation TVA par taux, avec catégorie EN16931 qualifiée
 * (S / K / G / O / E) et motif d'exonération associé.
 */
function lemonfacturx_get_tax_breakdown($invoice, $thirdparty, $mysoc)
{
	$breakdown = [];

	foreach ($invoice->lines as $line) {
		// Ignorer les lignes sans montant (descriptions, titres, sous-totaux)
		if ((float) $line->qty == 0 && (float) $line->total_ht == 0) {
			continue;
		}
		$rate = (string) ((float) $line->tva_tx);
		$taxCat = lemonfacturx_resolve_tax_category($line, $invoice, $thirdparty, $mysoc);
		if (!isset($breakdown[$rate])) {
			$breakdown[$rate] = [
				'base'         => 0,
				'tax'          => 0,
				'categoryCode' => $taxCat['code'],
				'exemption'    => $taxCat['exemption'],
			];
		}
		$breakdown[$rate]['base'] += (float) $line->total_ht;
		$breakdown[$rate]['tax']  += (float) $line->total_tva;
	}

	return $breakdown;
}

/**
 * Vérifie les infos obligatoires pour Factur-X EN16931 + BR-FR
 * Retourne un tableau de warnings (vide si tout est OK)
 *
 * @param Facture $invoice   Objet facture Dolibarr
 * @param Societe $mysoc     Société émettrice
 * @return array             Liste de messages d'avertissement
 */
function lemonfacturx_check_mandatory($invoice, $mysoc)
{
	$warnings = [];

	// Vendeur
	$sellerChecks = [
		'name'      => 'nom de la société émettrice manquant',
		'address'   => 'adresse de la société émettrice manquante',
		'zip'       => 'code postal de la société émettrice manquant',
		'town'      => 'ville de la société émettrice manquante',
		'tva_intra' => 'numéro de TVA intracommunautaire manquant (société)',
		'idprof2'   => 'SIRET/SIREN manquant (société) — obligatoire BR-FR-10',
		'email'     => 'email de la société manquant — obligatoire BR-FR-13 (BT-34)',
	];
	foreach ($sellerChecks as $field => $message) {
		if (empty($mysoc->$field)) {
			$warnings[] = 'Factur-X : '.$message;
		}
	}

	// Acheteur
	$buyer = $invoice->thirdparty;
	$buyerChecks = [
		'name'    => 'nom du tiers acheteur manquant',
		'address' => 'adresse du tiers acheteur manquante',
		'zip'     => 'code postal du tiers acheteur manquant',
		'town'    => 'ville du tiers acheteur manquante',
	];
	foreach ($buyerChecks as $field => $message) {
		if (empty($buyer->$field)) {
			$warnings[] = 'Factur-X : '.$message;
		}
	}

	// Email acheteur (fiche tiers + contacts)
	if (empty(lemonfacturx_get_buyer_email($buyer, $invoice->db))) {
		$warnings[] = 'Factur-X : email du tiers acheteur manquant (ni sur la fiche tiers, ni sur ses contacts) — obligatoire BR-FR-12 (BT-49)';
	}

	if (getDolGlobalInt('LEMONFACTURX_BANK_ACCOUNT') <= 0) {
		$warnings[] = 'Factur-X : aucun compte bancaire configuré dans le module';
	}

	return $warnings;
}

/**
 * Génère le bloc XML d'un TradeParty (vendeur ou acheteur)
 */
function lemonfacturx_build_trade_party_xml($role, $name, $address, $zip, $city, $country, $vat, $siren, $email)
{
	$tag = ($role === 'Seller') ? 'SellerTradeParty' : 'BuyerTradeParty';

	$xml = '    <ram:'.$tag.'>'."\n";
	$xml .= '      <ram:Name>'.xmlEncode($name).'</ram:Name>'."\n";
	if (!empty($siren)) {
		$xml .= '      <ram:SpecifiedLegalOrganization>'."\n";
		$xml .= '        <ram:ID schemeID="0002">'.xmlEncode($siren).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedLegalOrganization>'."\n";
	}
	$xml .= '      <ram:PostalTradeAddress>'."\n";
	$xml .= '        <ram:PostcodeCode>'.xmlEncode($zip).'</ram:PostcodeCode>'."\n";
	$xml .= '        <ram:LineOne>'.xmlEncode($address).'</ram:LineOne>'."\n";
	$xml .= '        <ram:CityName>'.xmlEncode($city).'</ram:CityName>'."\n";
	$xml .= '        <ram:CountryID>'.xmlEncode($country).'</ram:CountryID>'."\n";
	$xml .= '      </ram:PostalTradeAddress>'."\n";
	// BR-FR-13 / BT-49 : n'émettre le bloc URI que si l'email est réellement renseigné.
	// Sinon le XML contient une URIID vide qui est rejetée par certains validateurs.
	if (!empty($email)) {
		$xml .= '      <ram:URIUniversalCommunication>'."\n";
		$xml .= '        <ram:URIID schemeID="EM">'.xmlEncode($email).'</ram:URIID>'."\n";
		$xml .= '      </ram:URIUniversalCommunication>'."\n";
	}
	if (!empty($vat)) {
		$xml .= '      <ram:SpecifiedTaxRegistration>'."\n";
		$xml .= '        <ram:ID schemeID="VA">'.xmlEncode($vat).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedTaxRegistration>'."\n";
	}
	$xml .= '    </ram:'.$tag.'>'."\n";

	return $xml;
}

/**
 * Mappe l'unité Dolibarr d'une ligne vers le code UN/ECE Rec 20 attendu par Factur-X.
 * Cherche via $line->fk_unit dans llx_c_units, fallback C62 (pièce / one) si absent.
 *
 * @param object $line Ligne de facture Dolibarr
 * @return string Code UN/ECE (ex: HUR, DAY, MTR, KGM, C62...)
 */
function lemonfacturx_map_unit_code($line)
{
	static $map = [
		'h'      => 'HUR', // heure
		'min'    => 'MIN', // minute
		'd'      => 'DAY', // jour
		'week'   => 'WEE', // semaine
		'wk'     => 'WEE',
		'month'  => 'MON', // mois
		'm'      => 'MTR', // mètre (conflit avec min/month résolu par unit_type)
		'cm'     => 'CMT',
		'mm'     => 'MMT',
		'km'     => 'KMT',
		'm2'     => 'MTK', // mètre carré
		'm3'     => 'MTQ', // mètre cube
		'kg'     => 'KGM',
		'g'      => 'GRM',
		't'      => 'TNE', // tonne métrique
		'l'      => 'LTR',
		'cl'     => 'CLT',
		'ml'     => 'MLT',
		'p'      => 'C62', // pièce
		'pc'     => 'C62',
		'pcs'    => 'C62',
		'piece'  => 'C62',
		'u'      => 'C62', // unité
	];

	$fkUnit = !empty($line->fk_unit) ? (int) $line->fk_unit : 0;
	if ($fkUnit <= 0) {
		return 'C62';
	}

	global $db;
	$sql = "SELECT short_label, unit_type FROM ".MAIN_DB_PREFIX."c_units WHERE rowid=".((int) $fkUnit);
	$res = $db->query($sql);
	if (!$res) {
		return 'C62';
	}
	$obj = $db->fetch_object($res);
	if (!$obj || empty($obj->short_label)) {
		return 'C62';
	}

	$code = strtolower(trim($obj->short_label));
	// Désambiguïser 'm' : time=minute (MIN), size=mètre (MTR)
	if ($code === 'm') {
		return ($obj->unit_type === 'time') ? 'MIN' : 'MTR';
	}
	return $map[$code] ?? 'C62';
}

/**
 * Résout la catégorie TVA EN16931 (VATEX / CategoryCode) selon le contexte métier.
 *
 * @param object $line        Ligne de facture
 * @param object $invoice     Facture Dolibarr
 * @param object $thirdparty  Tiers acheteur
 * @param object $mysoc       Société émettrice
 * @return array ['code' => 'S|K|G|O|E|Z', 'exemption' => string|null]
 */
function lemonfacturx_resolve_tax_category($line, $invoice, $thirdparty, $mysoc)
{
	$tvaTx = (float) $line->tva_tx;

	// Société émettrice non assujettie (franchise en base, micro-entreprise) : O = hors champ
	if (isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0) {
		return ['code' => 'O', 'exemption' => 'TVA non applicable, art. 293 B du CGI'];
	}

	// TVA > 0 : standard
	if ($tvaTx > 0) {
		return ['code' => 'S', 'exemption' => null];
	}

	// TVA = 0 : qualifier selon le contexte
	$buyerCountry = strtoupper(!empty($thirdparty->country_code) ? $thirdparty->country_code : 'FR');
	$buyerVat     = !empty($thirdparty->tva_intra) ? $thirdparty->tva_intra : '';

	static $euCountries = [
		'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU',
		'IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK',
	];

	// Export hors UE : G
	if (!in_array($buyerCountry, $euCountries, true)) {
		return ['code' => 'G', 'exemption' => 'Export hors Union européenne'];
	}

	// UE hors FR avec TVA intra : K (autoliquidation)
	if ($buyerCountry !== 'FR' && !empty($buyerVat)) {
		return ['code' => 'K', 'exemption' => 'Autoliquidation intracommunautaire, TVA due par le preneur'];
	}

	// FR ou UE sans TVA intra et TVA=0 : exonération par défaut
	return ['code' => 'E', 'exemption' => 'Exonéré de TVA'];
}

/**
 * Résout le TypeCode documentaire EN16931 selon le type de facture Dolibarr.
 *
 * @param object $invoice Facture Dolibarr
 * @return string '380' (standard), '381' (avoir), '386' (acompte)
 */
function lemonfacturx_resolve_document_type($invoice)
{
	if ((int) $invoice->type === (int) Facture::TYPE_CREDIT_NOTE) {
		return '381';
	}
	if ((int) $invoice->type === (int) Facture::TYPE_DEPOSIT) {
		return '386'; // EN16931 : prepayment / advance invoice
	}
	return '380';
}

/**
 * Retourne le montant total déjà prépayé via acomptes imputés sur la facture finale.
 *
 * @param object $invoice Facture Dolibarr
 * @return float Montant prépayé ≥ 0
 */
function lemonfacturx_get_prepaid_amount($invoice)
{
	if (!method_exists($invoice, 'getSumDepositsUsed')) {
		return 0.0;
	}
	return max(0.0, (float) $invoice->getSumDepositsUsed());
}

/**
 * Génère le bloc SpecifiedTradeSettlementHeaderMonetarySummation.
 * Inclut TotalPrepaidAmount si un acompte a été imputé, ajuste DuePayableAmount.
 */
function lemonfacturx_build_monetary_summation_xml($invoice, $currency)
{
	$lineTotal     = (float) $invoice->total_ht;
	$taxBasisTotal = (float) $invoice->total_ht;
	$taxTotal      = (float) $invoice->total_tva;
	$grandTotal    = (float) $invoice->total_ttc;
	$totalPrepaid  = lemonfacturx_get_prepaid_amount($invoice);
	$duePayable    = max(0.0, $grandTotal - $totalPrepaid);

	$xml  = '    <ram:SpecifiedTradeSettlementHeaderMonetarySummation>'."\n";
	$xml .= '      <ram:LineTotalAmount>'.formatAmount($lineTotal).'</ram:LineTotalAmount>'."\n";
	$xml .= '      <ram:TaxBasisTotalAmount>'.formatAmount($taxBasisTotal).'</ram:TaxBasisTotalAmount>'."\n";
	$xml .= '      <ram:TaxTotalAmount currencyID="'.xmlEncode($currency).'">'.formatAmount($taxTotal).'</ram:TaxTotalAmount>'."\n";
	$xml .= '      <ram:GrandTotalAmount>'.formatAmount($grandTotal).'</ram:GrandTotalAmount>'."\n";
	if ($totalPrepaid > 0) {
		$xml .= '      <ram:TotalPrepaidAmount>'.formatAmount($totalPrepaid).'</ram:TotalPrepaidAmount>'."\n";
	}
	$xml .= '      <ram:DuePayableAmount>'.formatAmount($duePayable).'</ram:DuePayableAmount>'."\n";
	$xml .= '    </ram:SpecifiedTradeSettlementHeaderMonetarySummation>'."\n";

	return $xml;
}

/**
 * Extrait le SIREN (9 premiers chiffres) d'un SIRET
 */
function lemonfacturx_extract_siren($siret)
{
	if (empty($siret)) {
		return '';
	}
	return substr(preg_replace('/[^0-9]/', '', $siret), 0, 9);
}

/**
 * Cherche l'email d'un tiers : d'abord sur la fiche, sinon sur le 1er contact
 */
function lemonfacturx_get_buyer_email($buyer, $db)
{
	if (!empty($buyer->email)) {
		return $buyer->email;
	}
	if (empty($buyer->id)) {
		return '';
	}

	$sql = "SELECT email FROM ".MAIN_DB_PREFIX."socpeople"
		." WHERE fk_soc = ".((int) $buyer->id)
		." AND email IS NOT NULL AND email != ''"
		." ORDER BY rowid ASC LIMIT 1";
	$res = $db->query($sql);
	if ($res) {
		$obj = $db->fetch_object($res);
		if ($obj && !empty($obj->email)) {
			return $obj->email;
		}
	}
	return '';
}

function xmlEncode($str)
{
	return htmlspecialchars((string) $str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function formatAmount($amount)
{
	return number_format((float) $amount, 2, '.', '');
}

/**
 * Interroge l'API GitHub pour la dernière release publiée du module.
 * Résultat mis en cache 24h dans une constante Dolibarr pour ne pas taper
 * l'API à chaque ouverture de la page admin.
 *
 * @param object $db               Handle DB Dolibarr
 * @param string $currentVersion   Version actuelle du module (ex: "1.1.0")
 * @return array|null              ['version' => 'x.y.z', 'url' => 'https://...']
 *                                 si une version plus récente existe, null sinon
 */
function lemonfacturx_check_latest_release($db, $currentVersion)
{
	$now = time();
	$cacheRaw = getDolGlobalString('LEMONFACTURX_UPDATE_CHECK_CACHE', '');
	$cache = !empty($cacheRaw) ? json_decode($cacheRaw, true) : null;

	$latest = null;
	$htmlUrl = '';
	if (is_array($cache) && isset($cache['ts']) && ($now - (int) $cache['ts']) < 86400) {
		$latest  = $cache['version'] ?? null;
		$htmlUrl = $cache['url']     ?? '';
	} else {
		$url = 'https://api.github.com/repos/hello-lemon/module-dolibarr-lemonfacturx/releases/latest';
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'LemonFacturX-UpdateCheck');
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		$json = @curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode !== 200 || empty($json)) {
			return null;
		}
		$data = json_decode($json, true);
		if (!is_array($data) || empty($data['tag_name'])) {
			return null;
		}
		$latest  = ltrim($data['tag_name'], 'v');
		$htmlUrl = $data['html_url'] ?? '';
		// Validation défensive : on n'accepte qu'une URL github.com officielle du repo
		if (!preg_match('#^https://github\.com/hello-lemon/module-dolibarr-lemonfacturx/#', $htmlUrl)) {
			$htmlUrl = 'https://github.com/hello-lemon/module-dolibarr-lemonfacturx/releases';
		}

		dolibarr_set_const($db, 'LEMONFACTURX_UPDATE_CHECK_CACHE', json_encode([
			'ts'      => $now,
			'version' => $latest,
			'url'     => $htmlUrl,
		]), 'chaine', 0, '', 0);
	}

	if (!empty($latest) && version_compare($latest, $currentVersion, '>')) {
		return ['version' => $latest, 'url' => $htmlUrl];
	}
	return null;
}
