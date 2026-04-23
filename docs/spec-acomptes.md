# Spec : Support des acomptes dans LemonFacturX

Prompt prêt à l'emploi pour une autre IA qui reprendra le patch. Rédigé par Axel (brief initial), formaté pour exécution.

## Contexte

Le module génère aujourd'hui un XML Factur-X EN16931 pour :

- facture standard (TypeCode 380)
- avoir (`Facture::TYPE_CREDIT_NOTE` → 381)

Il ne traite pas explicitement :

- facture d'acompte (`Facture::TYPE_DEPOSIT`)
- facture de situation (`Facture::TYPE_SITUATION`)
- facture finale avec acomptes déjà imputés

## Fichiers à modifier

- `lib/xml_builder.php` (principal)
- `class/actions_lemonfacturx.class.php` (warnings métier)
- `README.md` (documenter le support)

## Problème actuel

Dans `lib/xml_builder.php:25`, la résolution du type est un ternaire :

```php
$typeCode = ($invoice->type == Facture::TYPE_CREDIT_NOTE) ? '381' : '380';
```

Conséquences :

- une facture d'acompte Dolibarr est exportée comme une facture standard
- une facture finale ayant consommé un acompte est exportée sans `TotalPrepaidAmount`
- `DuePayableAmount` est faux dès qu'un acompte a déjà été imputé

Le module ignore aussi les API Dolibarr existantes : `Facture::TYPE_DEPOSIT`, `CommonInvoice::getSumDepositsUsed()`.

## Objectif fonctionnel

Supporter deux cas distincts :

1. **Facture d'acompte Dolibarr** (`$invoice->type == Facture::TYPE_DEPOSIT`) : le XML Factur-X doit refléter le cas explicitement, ou à défaut être marqué avec une stratégie documentée.
2. **Facture finale avec acompte(s) imputé(s)** : le XML doit intégrer le prépaiement dans les montants de règlement via `TotalPrepaidAmount`.

## Décision d'architecture

Arrêter de mélanger logique métier Dolibarr, mapping Factur-X et rendu XML. Créer les fonctions dédiées suivantes dans `lib/xml_builder.php` :

- `lemonfacturx_resolve_document_type($invoice)`
- `lemonfacturx_is_deposit_invoice($invoice)`
- `lemonfacturx_get_prepaid_amount($invoice)`
- `lemonfacturx_build_monetary_summation_xml($invoice, $currency)`
- éventuellement `lemonfacturx_add_business_warnings_for_invoice_type($invoice, &$warnings)`

## Règles métier

### Détection d'une facture d'acompte

Règle minimale : `$invoice->type == Facture::TYPE_DEPOSIT`. Ne pas déduire depuis le libellé, le montant, la présence du mot "acompte" ou une ligne unique.

```php
function lemonfacturx_is_deposit_invoice($invoice)
{
	return ((int) $invoice->type === (int) Facture::TYPE_DEPOSIT);
}
```

### Résolution du type documentaire

```php
function lemonfacturx_resolve_document_type($invoice)
{
	if ((int) $invoice->type === (int) Facture::TYPE_CREDIT_NOTE) {
		return '381';
	}

	if ((int) $invoice->type === (int) Facture::TYPE_DEPOSIT) {
		// Mapping à documenter après validation métier/normative
		return '386'; // seulement si confirmé validateur externe
	}

	return '380';
}
```

**Important** : ne pas supposer aveuglément `386` sans validation. Si incertain :

- garder temporairement `380`
- ou logger un warning explicite
- ou rendre le mapping configurable (constante)

Critère d'acceptation : le mapping n'est plus dans un ternaire, `TYPE_DEPOSIT` est traité explicitement.

### Détection des acomptes imputés

Utiliser l'API Dolibarr si disponible :

```php
function lemonfacturx_get_prepaid_amount($invoice)
{
	if (!is_object($invoice)) {
		return 0.0;
	}

	if (method_exists($invoice, 'getSumDepositsUsed')) {
		$amount = $invoice->getSumDepositsUsed();
		return max(0.0, (float) $amount);
	}

	return 0.0;
}
```

Contraintes : jamais négatif, toujours float, ne casse pas si la méthode n'existe pas.

### Génération des totaux monétaires

Extraire la logique actuelle (`lib/xml_builder.php:163-169`) dans une fonction dédiée :

```php
function lemonfacturx_build_monetary_summation_xml($invoice, $currency)
{
	$lineTotal     = (float) $invoice->total_ht;
	$taxBasisTotal = (float) $invoice->total_ht;
	$taxTotal      = (float) $invoice->total_tva;
	$grandTotal    = (float) $invoice->total_ttc;
	$totalPrepaid  = lemonfacturx_get_prepaid_amount($invoice);

	$duePayable = $grandTotal - $totalPrepaid;
	if ($duePayable < 0) {
		$duePayable = 0.0;
	}

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
```

Critères d'acceptation :

- `TotalPrepaidAmount` écrit uniquement si > 0
- `DuePayableAmount` n'est plus égal mécaniquement à `total_ttc`
- baisse corrélée de `DuePayableAmount` quand un acompte est utilisé

### Facture d'acompte elle-même

Niveau minimal : détecter, ne pas faire semblant, logger un warning.

```php
if (lemonfacturx_is_deposit_invoice($invoice)) {
	$warnings[] = 'Factur-X : facture d\'acompte détectée ; vérifier le mapping documentaire et la conformité sur validateur externe.';
}
```

Niveau cible : `TypeCode` correct, mentions spécifiques si nécessaires, validation externe.

## Refactoring dans `lemonfacturx_build_xml()`

Remplacer le ternaire et le bloc inline :

```php
$typeCode      = lemonfacturx_resolve_document_type($invoice);
$prepaidAmount = lemonfacturx_get_prepaid_amount($invoice);

// ... plus loin ...
$xml .= lemonfacturx_build_monetary_summation_xml($invoice, $currency);
```

Aucun calcul de totaux ne doit rester inline.

## Warnings métier à ajouter

Dans `class/actions_lemonfacturx.class.php` :

- facture d'acompte détectée mais mapping non validé
- acompte(s) imputé(s) détecté(s), `TotalPrepaidAmount` ajouté
- montant d'acompte > TTC, ramené à 0 côté `DuePayableAmount`

Routing : `dol_syslog` + `setEventMessages(..., 'warnings')`.

## Tests métier (via Dolibarr de démo)

LXC 115 (`dolibarr-ard`, 192.168.1.224) contient 10 factures fixtures couvrant les cas.

| Cas | Ref fixture | Attendu XML |
|---|---|---|
| Standard sans acompte | F001 | pas de `TotalPrepaidAmount`, `DuePayableAmount` = `total_ttc` |
| Finale avec acompte | F010 | `TotalPrepaidAmount` = 240€, `DuePayableAmount` = `total_ttc` − 240€ |
| Facture d'acompte | F009 | type documentaire traité explicitement, warning si mapping non validé |
| Avoir | F004 | pas de régression sur 381 |
| Acompte incohérent | (à créer) | `DuePayableAmount` ≥ 0, warning |

Workflow : modifier le code → redéployer sur LXC 115 → `bash reset-demo.sh` → générer PDF de chaque facture → extraire et valider le XML.

## Critères d'acceptation globaux

Acceptable si :

- détection explicite de `TYPE_DEPOSIT`
- plus de ternaire simpliste pour le type documentaire
- usage de `getSumDepositsUsed()` si disponible
- XML peut contenir `TotalPrepaidAmount`
- `DuePayableAmount` ajusté
- aucune régression sur standard / avoir
- comportement documenté dans le README

**Pas** acceptable si :

- ajout d'un `if` local sans refactoring
- mapping d'acompte inventé sans le signaler
- `DuePayableAmount` toujours égal à `total_ttc`
- détection d'acompte via heuristique textuelle

## Mise à jour README

Préciser :

- si les factures d'acompte sont supportées
- si le support est complet ou expérimental
- si les factures finales avec acomptes imputés écrivent `TotalPrepaidAmount`
- qu'une validation externe reste recommandée
