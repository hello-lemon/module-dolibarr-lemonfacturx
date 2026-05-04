# LemonFacturX

**Version 1.1.1** — Module Dolibarr pour la génération automatique de factures **Factur-X EN16931** (PDF/A-3 avec XML CrossIndustryInvoice embarqué).

Chaque facture client générée dans Dolibarr est automatiquement convertie au format Factur-X, conforme aux règles **BR-FR** (norme XP Z12-012 V1.2.0) pour la facturation électronique française.

Développé et maintenu par [Lemon](https://hellolemon.fr), agence web et communication à Clermont-Ferrand, spécialisée dans Dolibarr, WordPress et la facturation électronique.

## Prérequis

- **Dolibarr** 22.0.x
- **PHP** 8.2+
- **Fonction `exec()`** activée (subprocess d'injection PDF)
- **Constante Dolibarr** `MAIN_PDF_FORCE_FONT` = `pdfahelvetica` (polices embarquées, requis PDF/A-3)

## Installation

1. **Télécharger l'archive de la dernière release** sur
   [github.com/hello-lemon/module-dolibarr-lemonfacturx/releases/latest](https://github.com/hello-lemon/module-dolibarr-lemonfacturx/releases/latest).

   Récupérer l'asset `lemonfacturx-vX.Y.Z.zip` attaché à la release (et **non** le
   bouton "Download ZIP" du code source — voir l'avertissement plus bas).

2. Décompresser et copier le dossier `lemonfacturx/` dans le répertoire custom de Dolibarr :

   ```bash
   unzip lemonfacturx-vX.Y.Z.zip
   cp -r lemonfacturx/ /var/www/html/custom/
   chown -R www-data:www-data /var/www/html/custom/lemonfacturx
   ```

3. Activer le module : **Accueil > Configuration > Modules**
4. Configurer via **Accueil > Configuration > Modules > LemonFacturX** :
   - Compte bancaire (IBAN/BIC)
   - Moyen de paiement par défaut (virement, SEPA, prélèvement)
   - Mode de gestion d'erreur (best-effort / strict)
   - Éventuellement chemin PHP CLI et mentions légales
5. Poser `MAIN_PDF_FORCE_FONT = pdfahelvetica` via **Accueil > Configuration > Divers**
6. Vérifier le **diagnostic** en bas de la page de configuration du module (coches vertes = OK)

> **Attention** — N'utilisez pas le bouton "Download ZIP" de la page d'accueil du dépôt
> (le code source brut). Cette archive se décompresse en `module-dolibarr-lemonfacturx-main/`
> au lieu de `lemonfacturx/`, ce qui casse l'installation Dolibarr (erreur *"You requested
> a website or a page that does not exists"* en ouvrant la page de configuration du module).
> Téléchargez l'asset ZIP de la release, ou clonez directement avec `git clone`
> (cf. section [Mise à jour](#mise-à-jour)).

## Mise à jour

```bash
# Sauvegarder l'ancienne version (au cas où)
cp -r /var/www/html/custom/lemonfacturx /var/www/html/custom/lemonfacturx.bak

# Récupérer la nouvelle version
git clone https://github.com/hello-lemon/module-dolibarr-lemonfacturx.git /tmp/lemonfacturx-new
rm -rf /var/www/html/custom/lemonfacturx
mv /tmp/lemonfacturx-new /var/www/html/custom/lemonfacturx
chown -R www-data:www-data /var/www/html/custom/lemonfacturx
```

Dolibarr ne notifie pas automatiquement des mises à jour d'un module custom. Pour rester à jour, [suivre les releases GitHub](https://github.com/hello-lemon/module-dolibarr-lemonfacturx/releases) ou faire un `git pull` périodique si le module est versionné.

Consulter la section **Changelog** en bas de ce README pour connaître les changements et migrations éventuelles.

## Architecture

```
lemonfacturx/
├── core/modules/modLemonFacturX.class.php   # Descripteur module (n° 210000)
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

- `inject_facturx.php` est protégé contre l'accès HTTP direct (`php_sapi_name() === 'cli'`)
- `exec()` vérifié avant appel, binaire PHP CLI configurable via `LEMONFACTURX_PHP_CLI_PATH`, chemin validé par regex et `is_executable()` si absolu
- Validation XML interne avant injection PDF (well-formed + XSD EN16931)
- Mode `LEMONFACTURX_STRICT_MODE` : choisir fail-open (best-effort) vs fail-closed (strict)
- CSRF du POST admin aligné sur `currentToken()` Dolibarr 22
- Aucun endpoint web public exposé
- Un seul appel HTTP sortant : check de version GitHub toutes les 24h (cache en DB)

Modèle de menace, protections détaillées et processus de signalement : voir [SECURITY.md](SECURITY.md). Contact disclosure : **hello@hellolemon.fr**.

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

**Catégories EN16931 non encore supportées** :

| Code | Cas | Comportement actuel |
|---|---|---|
| **AE** | Autoliquidation domestique (sous-traitance bâtiment CGI art. 283 nonies, déchets ferreux, composants électroniques) | Retombe sur `S` si TVA > 0 ou `E` si TVA 0%. La résolution automatique nécessiterait un flag manuel par ligne ou par tiers ; patch prévu sur demande. |
| **Z** | Zero rated (livres, presse, certaines livraisons spéciales) | Retombe sur `E`. Cas très rare en pratique. |
| **L** / **M** | TVA Canaries / Ceuta-Melilla | Non pertinent pour la France métropolitaine, non implémenté. |

Pour les 99 % des cas FR + UE standard (standard rate, autoliquidation intracommunautaire, export, franchise en base, exonération simple), le mapping automatique actuel suffit.

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

Toutes sont configurables via l'écran d'administration du module (**Accueil > Configuration > Modules > LemonFacturX**).

| Constante | Type | Défaut | Description |
|---|---|---|---|
| `LEMONFACTURX_ENABLED` | int | 1 | Activer/désactiver la conversion |
| `LEMONFACTURX_BANK_ACCOUNT` | int | 0 | ID du compte bancaire Dolibarr |
| `LEMONFACTURX_PAYMENT_MEANS` | string | 30 | Code moyen de paiement |
| `LEMONFACTURX_STRICT_MODE` | int | 0 | 0 = best-effort (défaut), 1 = strict (voir ci-dessous) |
| `LEMONFACTURX_PHP_CLI_PATH` | string | php | Chemin vers le binaire PHP CLI (voir note ci-dessous) |
| `LEMONFACTURX_NOTE_PMD` | text | voir ci-dessous | Mention pénalités de retard (BR-FR-05) |
| `LEMONFACTURX_NOTE_PMT` | text | voir ci-dessous | Mention indemnité de recouvrement |
| `LEMONFACTURX_NOTE_AAB` | text | voir ci-dessous | Mention escompte anticipé |

> **Note PHP CLI** : Le subprocess d'injection utilise `php` par défaut. Sur les serveurs avec plusieurs versions de PHP, ou si `php` n'est pas dans le PATH, configurer `LEMONFACTURX_PHP_CLI_PATH` avec le chemin complet (ex: `/usr/bin/php8.2`). Ne **pas** utiliser `PHP_BINARY` : en contexte php-fpm, cette constante pointe vers le binaire fpm et non le CLI.

### Mode strict vs best-effort

Par défaut le module est en **best-effort** : si le XML Factur-X est invalide ou si l'injection PDF échoue, un warning est affiché à l'utilisateur et le PDF classique (sans Factur-X embarqué) est conservé. Les erreurs sont loguées dans `syslog` avec le tag `LemonFacturX`.

En **mode strict** (`LEMONFACTURX_STRICT_MODE=1`), la même situation retourne une erreur bloquante visible à l'utilisateur. À utiliser quand la conformité Factur-X est impérative (obligation légale, contrainte client, transmission PA/PDP downstream).

Avant injection PDF, le module valide systématiquement le XML en interne :

1. **Well-formed** : parse via `DOMDocument::loadXML()` pour détecter un XML cassé avant d'appeler la lib d'injection
2. **Conformité XSD EN16931** : `DOMDocument::schemaValidate()` contre le XSD embarqué dans `vendor/atgp/factur-x/xsd/factur-x/en16931/`

Si l'une de ces deux validations échoue, le comportement dépend du `LEMONFACTURX_STRICT_MODE` ci-dessus.

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

### Suite de tests automatisée

`tests/run-tests.php` couvre les 10 cas de fixtures (standard, multi-TVA, TVA 0%, avoir, heures, jours, sans email, autoliquidation UE, acompte, finale avec acompte imputé) avec assertions sur TypeCode, CategoryCode, unitCode, présence/absence des blocs optionnels, montants calculés et validation XSD.

```bash
# Depuis la racine du module, sur un Dolibarr avec les fixtures chargées :
php tests/run-tests.php
# Exit code 0 = tous les tests passent, 1 = au moins un échec
```

À lancer après toute modification de `core/lib/lemonfacturx.lib.php` pour vérifier qu'aucune régression n'a été introduite.

## Changelog

### 1.1.1 (avril 2026)

Maintenance des dépendances vendored, suite aux [PRs `.gitattributes`](https://github.com/hello-lemon/module-dolibarr-lemonfacturx/issues/3) fusionnées upstream par William Desportes :

| Lib | Avant | Après |
|---|---|---|
| `atgp/factur-x` | v3.0.0 | **v3.3.0** |
| `smalot/pdfparser` | v2.12.3 | **v2.12.5** |
| `setasign/fpdf` | 1.8.2 | **1.8.6** (patch `/F 4` pour PDF/A-3 réappliqué) |
| `setasign/fpdi` | v2.6.4 | **v2.6.6** |
| `symfony/polyfill-mbstring` | v1.33.0 | **v1.36.0** |

Dossier `vendor/` plus léger et sans fichiers de dev (grâce aux `.gitattributes` upstream). 80/80 tests automatisés passent toujours, aucune régression.

### 1.1.0 (avril 2026)

Module **distribué publiquement sur GitHub**. Mise à niveau pour couvrir tous les cas EN16931 et fiabiliser le comportement en production partagée.

- **Conformité EN16931 renforcée** :
  - Support des factures d'acompte (`Facture::TYPE_DEPOSIT` → TypeCode `386`)
  - Support de `TotalPrepaidAmount` sur les factures finales ayant imputé un acompte (via `getSumDepositsUsed()`)
  - CategoryCode TVA intelligent selon le contexte : `S` / `K` (autoliquidation UE) / `G` (export hors UE) / `O` (hors champ) / `E` (exonéré), au lieu du binaire S/E précédent
  - Mapping des unités de ligne Dolibarr vers les codes UN/ECE Rec 20 (HUR, DAY, KGM, MTR...) au lieu de C62 en dur
  - `URIUniversalCommunication` rendu conditionnel : plus de bloc vide si l'email est absent
  - `ExemptionReason` généré dynamiquement selon le motif réel
- **Qualité module distribué** :
  - Validation XML interne avant injection PDF (well-formed + XSD EN16931 local)
  - Nouveau mode `LEMONFACTURX_STRICT_MODE` (0 = best-effort, 1 = bloquant)
  - Message unique consolidé à l'utilisateur : vert si injection OK, orange avec liste des warnings sinon
  - CSRF de la page admin aligné sur le pattern Dolibarr 22 (`currentToken()`)
  - Exposition dans l'UI admin des mentions légales PMD/PMT/AAB et du chemin PHP CLI
- **Outillage** :
  - `demo/` : environnement Dolibarr de démo (fixtures pour tests Factur-X + fixtures marketing 6 mois)
  - `tests/run-tests.php` : suite de tests automatisés couvrant 10 cas × 8 assertions (80/80 PASS)
  - `docs/spec-acomptes.md` : spécification détaillée du support des acomptes

Aucune migration DB nécessaire. Les anciennes constantes restent compatibles. Les nouvelles constantes (`STRICT_MODE`, `PHP_CLI_PATH`, `NOTE_PMD/PMT/AAB`) ont des valeurs par défaut raisonnables.

### 1.0.0

Version initiale : génération XML EN16931, injection PDF/A-3, conformité B2Brouter sur le cas standard.

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
