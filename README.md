# LemonFacturX

Module Dolibarr pour la génération automatique de factures **Factur-X EN16931** (PDF/A-3 avec XML CrossIndustryInvoice embarqué).

Chaque facture client générée dans Dolibarr est automatiquement convertie au format Factur-X, conforme aux règles **BR-FR** (norme XP Z12-012 V1.2.0) pour la facturation électronique française.

Développé et maintenu par [Lemon](https://hellolemon.fr), agence web et communication à Clermont-Ferrand, spécialisée dans Dolibarr, WordPress et la facturation électronique.

## Prérequis

- **Dolibarr** 22.0.x
- **PHP** 8.2+
- **Constante Dolibarr** `MAIN_PDF_FORCE_FONT` = `pdfahelvetica` (pour embarquer les polices, requis PDF/A-3)

## Installation

1. Copier le dossier `lemonfacturx/` dans le répertoire custom de Dolibarr :

```bash
cp -r lemonfacturx/ /var/www/html/custom/
chown -R www-data:www-data /var/www/html/custom/lemonfacturx
```

2. Activer le module dans Dolibarr : **Accueil > Configuration > Modules**
3. Configurer le module dans **Accueil > Configuration > Modules > LemonFacturX** :
   - Sélectionner le compte bancaire (IBAN/BIC)
   - Choisir le moyen de paiement par défaut (virement, SEPA, prélèvement)
4. Vérifier le diagnostic en bas de la page de configuration (toutes les coches vertes = OK)
5. Ajouter dans "Divers" la constante `MAIN_PDF_FORCE_FONT` avec `pdfahelvetica` comme valeur
**

## Architecture

```
lemonfacturx/
├── core/modules/modLemonFacturX.class.php   # Descripteur module (n° 500200)
├── class/actions_lemonfacturx.class.php     # Hook afterPDFCreation
├── lib/
│   ├── xml_builder.php                      # Générateur XML EN16931
│   └── inject_facturx.php                   # Injection PDF (subprocess)
├── admin/setup.php                          # Page de configuration
├── langs/fr_FR/lemonfacturx.lang            # Traductions
└── vendor/                                  # Lib atgp/factur-x v3.0.0
```

## Fonctionnement

Le module se branche sur le hook `afterPDFCreation` (contexte `pdfgeneration`). À chaque génération de PDF facture client :

1. **Vérification** des infos obligatoires (vendeur, acheteur, IBAN) — affiche des warnings si incomplet
2. **Génération du XML** CrossIndustryInvoice EN16931 avec les données de la facture Dolibarr
3. **Injection** du XML dans le PDF via la lib `atgp/factur-x` (subprocess séparé pour éviter le conflit FPDF/TCPDF)

L'injection se fait dans un **subprocess PHP séparé** (`inject_facturx.php`) pour éviter le conflit de classes entre FPDF (utilisé par atgp/factur-x) et TCPDF (utilisé par Dolibarr).

### Sécurité

- **`inject_facturx.php`** est protégé contre l'accès HTTP direct (vérification `php_sapi_name() === 'cli'`)
- **`exec()`** : le module vérifie que la fonction est disponible avant de l'appeler (certains hébergeurs la désactivent)
- Le binaire PHP CLI est configurable via la constante `LEMONFACTURX_PHP_CLI_PATH` (défaut : `php`)

## Données mappées (Dolibarr → Factur-X)

| Champ Factur-X | Source Dolibarr |
|---|---|
| BT-1 Invoice ID | `$invoice->ref` |
| BT-2 Issue date | `$invoice->date` |
| BT-3 Type code | 380 (standard) / 381 (avoir) / 386 (acompte) |
| BT-9 Due date | `$invoice->date_lim_reglement` |
| Seller | `$mysoc` (config société) |
| Buyer | `$invoice->thirdparty` |
| Seller SIREN (BT-30) | 9 premiers chiffres de `$mysoc->idprof2` |
| Seller email (BT-34) | `$mysoc->email` |
| Buyer email (BT-49) | `$thirdparty->email` ou 1er contact (bloc omis si vide) |
| Lines | `$invoice->lines[]` |
| BT-130 unitCode | Mappé depuis `$line->fk_unit` vers UN/ECE Rec 20 |
| BT-151 CategoryCode | Calculé selon contexte (S / K / G / O / E) |
| BT-113 TotalPrepaidAmount | `$invoice->getSumDepositsUsed()` si acompte imputé |
| IBAN / BIC | Compte bancaire Dolibarr sélectionné |
| Payment means | Configurable (30=virement, 58=SEPA) |

### Types de facture supportés

Le module détecte automatiquement le type documentaire EN16931 :

| Cas Dolibarr | TypeCode EN16931 | Mapping |
|---|---|---|
| Facture standard | **380** | Commercial invoice |
| `Facture::TYPE_CREDIT_NOTE` | **381** | Credit note (avoir) |
| `Facture::TYPE_DEPOSIT` | **386** | Prepayment / advance invoice (acompte) |

Une facture finale qui impute un acompte précédemment facturé écrit automatiquement `<ram:TotalPrepaidAmount>` dans le bloc de synthèse monétaire, et `<ram:DuePayableAmount>` est ajusté en conséquence (`total_ttc − acompte imputé`, minoré à zéro si négatif).

### Catégories TVA (BT-151)

Le code résout la catégorie EN16931 selon le contexte de la ligne, plutôt que de forcer une valeur binaire :

| CategoryCode | Signification | Cas déclenchant |
|---|---|---|
| **S** | Standard rate | TVA > 0 |
| **K** | Intra-community reverse charge | Acheteur UE hors FR avec TVA intra + TVA = 0 |
| **G** | Free export outside EU | Acheteur hors UE + TVA = 0 |
| **O** | Outside scope of tax | Émetteur non assujetti (franchise en base, micro) |
| **E** | Exempt from tax | TVA = 0 par défaut (exonération) |

Les catégories K, G, O et E génèrent systématiquement un `<ram:ExemptionReason>` avec un motif humain lisible par le destinataire.

### Mapping unités UN/ECE

Les quantités de ligne utilisent le code UN/ECE Rec 20 correspondant à l'unité Dolibarr (`llx_c_units.short_label`) :

| Dolibarr | UN/ECE | | Dolibarr | UN/ECE |
|---|---|---|---|---|
| h | HUR | | kg | KGM |
| d | DAY | | l | LTR |
| min | MIN | | m | MTR |
| week | WEE | | m² (`m2`) | MTK |
| month | MON | | m³ (`m3`) | MTQ |
| p, pc, pcs, u | C62 | | km | KMT |

Si l'unité n'est pas mappée ou si `fk_unit` n'est pas renseigné, le code `C62` (pièce) est utilisé en fallback.

### Mentions légales FR (BR-FR-05)

Le XML inclut automatiquement les notes obligatoires :
- **PMD** : pénalités de retard (3x taux d'intérêt légal, art. L.441-10)
- **PMT** : indemnité forfaitaire de recouvrement (40 €)
- **AAB** : escompte pour paiement anticipé

## Constantes du module

| Constante | Type | Défaut | Description |
|---|---|---|---|
| `LEMONFACTURX_ENABLED` | int | 1 | Activer/désactiver la conversion |
| `LEMONFACTURX_BANK_ACCOUNT` | int | 0 | ID du compte bancaire Dolibarr |
| `LEMONFACTURX_PAYMENT_MEANS` | string | 30 | Code moyen de paiement |
| `LEMONFACTURX_PHP_CLI_PATH` | string | php | Chemin vers le binaire PHP CLI (voir note ci-dessous) |

> **Note PHP CLI** : Le subprocess d'injection utilise `php` par défaut. Sur les serveurs avec plusieurs versions de PHP, ou si `php` n'est pas dans le PATH, configurer `LEMONFACTURX_PHP_CLI_PATH` avec le chemin complet (ex: `/usr/bin/php8.2`). Ne **pas** utiliser `PHP_BINARY` : en contexte php-fpm, cette constante pointe vers le binaire fpm et non le CLI.

## Dépendances embarquées

Le dossier `vendor/` contient les libs nécessaires (pas de Composer requis sur le serveur) :

- `atgp/factur-x` v3.0.0 — génération PDF Factur-X
- `setasign/fpdi` — lecture/écriture PDF
- `setasign/fpdf` — moteur PDF (utilisé par atgp, **pas** par Dolibarr)
- `smalot/pdfparser` — parsing PDF
- `symfony/polyfill-mbstring` — compatibilité mbstring

## Conformité PDF/A-3

La conformité PDF/A-3 est assurée par :
- **Polices embarquées** : constante Dolibarr `MAIN_PDF_FORCE_FONT=pdfahelvetica` (à configurer via `/admin/const.php`)
- **Annotations /F flag** : patch appliqué dans `vendor/setasign/fpdf/fpdf.php` (ajout `/F 4` aux liens)
- **Profil ICC sRGB** + **métadonnées XMP** : gérés par la lib `atgp/factur-x`

> **Note** : si un module tiers (ex: milestone/jalons) hardcode la police `'Helvetica'`, il faudra le patcher pour utiliser `pdf_getPDFFont($outputlangs)`.

## Validation

Validation externe via [B2Brouter Factur-X Validator](https://www.b2brouter.net/fr/factur-x-validator/) :
- Valid XMP, Valid XSD, Valid Schematron, Valid PDF/A-3
- Profile EN 16931 (Comfort)

Validation XSD locale rapide (sur les 10 cas de test fournis dans `demo/` — standard, multi-TVA, TVA 0%, avoir, heures, jours, sans email, autoliquidation UE, acompte, facture finale avec acompte imputé) :

```bash
xmllint --noout --schema vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.08_EN16931.xsd chemin/vers/facturx.xml
```

Un environnement Dolibarr de démo prêt à l'emploi est disponible via les scripts dans `demo/` (voir `demo/README.md`). Il permet de tester le module sans toucher à un Dolibarr de production.

## Licence

Ce module est distribué sous licence [GPLv3](https://www.gnu.org/licenses/gpl-3.0.html) — Copyright (C) 2026 [SASU Lemon](https://hellolemon.fr).

## À propos de Lemon

[Lemon](https://hellolemon.fr) est une agence web et communication basée à Clermont-Ferrand, fondée en 2012. Nous accompagnons TPE, PME et indépendants bien au-delà du simple site web :

- **Déploiement et hébergement Dolibarr** : installation, migration, paramétrage métier, formation de vos équipes
- **Modules Dolibarr sur mesure** : CRM, pointeuse NFC, facturation électronique, intégrations API, automatisations — on développe le module qui manque à votre ERP
- **Facturation électronique** : mise en conformité Factur-X EN16931, raccordement aux Plateformes Agréées (PA/PDP), accompagnement réforme 2026-2027
- **IA au service des pros** : extraction automatique de factures fournisseurs, rapprochement bancaire, génération de contenus, assistants métier — on met l'IA au travail pour vous faire gagner du temps
- **Sites web** : WordPress, Astro, Symfony — performance, SEO, éco-conception
- **Communication & print** : identité visuelle, impression, fabrication (laser, 3D)

Un projet Dolibarr, une idée d'automatisation, un besoin IA ? [Parlons-en](https://hellolemon.fr) — Clermont-Ferrand (63).
