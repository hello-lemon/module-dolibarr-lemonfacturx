# Politique de sécurité — LemonFacturX

Ce document décrit le modèle de menace du module LemonFacturX, les protections en place, les limitations assumées, et le processus de signalement responsable d'une faille.

## Signaler une vulnérabilité

Merci de **ne pas** ouvrir d'issue publique pour une faille de sécurité. Écrivez à :

**hello@hellolemon.fr**

Précisez :

- Version du module concernée (ou commit SHA)
- Description de la vulnérabilité et impact estimé
- Étapes de reproduction minimales
- Éventuelle preuve de concept

Nous nous engageons à :

- Accuser réception sous 72 heures
- Vous tenir informé de l'avancement de l'analyse
- Mentionner votre contribution (si vous le souhaitez) une fois le correctif publié
- Appliquer un délai de divulgation coordonnée de 90 jours maximum avant publication publique du détail

Merci d'éviter toute action qui pourrait dégrader un service en production, accéder à des données tierces, ou exploiter une faille au-delà du strict nécessaire pour la démontrer.

## Modèle de menace

LemonFacturX est un module Dolibarr qui convertit automatiquement les PDF factures clients au format Factur-X EN16931 (PDF/A-3 avec XML CII embarqué). Il s'exécute **à l'intérieur** d'une instance Dolibarr authentifiée, branché sur le hook `afterPDFCreation`. Le modèle de menace est celui d'une application métier en intranet.

### Rôles

| Rôle | Accès | Confiance |
|---|---|---|
| Administrateur Dolibarr | Configuration du module, y compris `LEMONFACTURX_PHP_CLI_PATH` | **Confiance forte**. Un admin compromis implique de toute façon une compromission totale de Dolibarr. |
| Utilisateur Dolibarr avec droit de générer un PDF facture | Déclenche l'injection Factur-X via le hook standard | Confiance interne. |
| Utilisateur anonyme (hors Dolibarr) | Aucun accès | Non concerné : le module n'expose aucun endpoint public. |

### Surface exposée

- **Hook `afterPDFCreation`** : exécuté dans le contexte d'une génération PDF facture (utilisateur authentifié)
- **Page de configuration admin** : `admin/setup.php`, réservée aux admins via `accessforbidden()`
- **Script CLI** : `lib/inject_facturx.php` — protégé contre l'accès HTTP direct par `php_sapi_name() === 'cli'`
- **Aucun endpoint web exposé publiquement**

### Ce qui est **hors** modèle de menace

- Un administrateur Dolibarr malveillant. Un admin peut déjà tout faire dans Dolibarr (modules custom, `/admin/tools/*`, accès base, `/admin/const.php`). Aucun mécanisme ne protège contre un admin hostile (et ne le peut pas dans l'architecture Dolibarr).
- La sécurité des bibliothèques tierces vendored (`atgp/factur-x`, `setasign/fpdi`, `setasign/fpdf`, `smalot/pdfparser`, `symfony/polyfill-mbstring`). Leur sécurité dépend des mainteneurs amont et des versions embarquées.

## Protections en place

### Exécution d'un subprocess PHP (`exec`)

Le module lance un subprocess CLI pour éviter un conflit de classes entre FPDF (utilisé par `atgp/factur-x`) et TCPDF (utilisé par Dolibarr). Le binaire est configurable via la constante `LEMONFACTURX_PHP_CLI_PATH`.

Protections :

- `escapeshellarg()` est appliqué sur **tous** les tokens de la commande (binaire PHP, script, PDF, fichier XML temporaire). Une valeur piégée dans la constante est quotée, et le shell cherche un binaire avec ce nom littéral qui n'existe pas → `command not found`. Pas de chaînage de commandes possible.
- Depuis la version avec hardening : validation par regex `^[A-Za-z0-9/._-]+$` sur `LEMONFACTURX_PHP_CLI_PATH` avant l'appel. Toute valeur contenant des caractères exotiques (espaces, `;`, `&`, `$`, guillemets, etc.) est refusée avec un message d'erreur clair.
- Si le chemin est absolu, `is_executable()` vérifie qu'un exécutable existe effectivement.
- `function_exists('exec')` est testé en amont (certains hébergeurs désactivent `exec`).
- Le script CLI `inject_facturx.php` refuse tout appel via HTTP : `if (php_sapi_name() !== 'cli') { http_response_code(403); die(...); }`

### Manipulation de fichiers

- Le PDF source provient du flux interne Dolibarr (hook `afterPDFCreation`), pas d'un upload direct.
- Les fichiers XML temporaires sont créés via `tempnam(sys_get_temp_dir(), 'facturx_')` (permissions 0600) puis supprimés immédiatement après l'exec.
- Aucun path fourni par l'utilisateur n'est utilisé en lecture/écriture.

### Génération XML

- Le XML CII est **construit** programmatiquement à partir des objets Dolibarr (`$invoice`, `$mysoc`, `$thirdparty`). Aucune donnée utilisateur n'est injectée en brut : les valeurs sont échappées avec `htmlspecialchars(..., ENT_XML1 | ENT_QUOTES, 'UTF-8')` via les helpers du module.
- Aucun parsing de XML externe n'est effectué dans le chemin critique.

### Patch FPDF pour PDF/A-3

La bibliothèque `setasign/fpdf` vendored reçoit un patch pour ajouter le flag `/F 4` aux annotations (conformité PDF/A-3). Ce patch est appliqué au build du vendor et n'introduit pas de vecteur d'attaque.

### Constantes Dolibarr

Les constantes du module (`LEMONFACTURX_ENABLED`, `LEMONFACTURX_BANK_ACCOUNT`, `LEMONFACTURX_PAYMENT_MEANS`, `LEMONFACTURX_PHP_CLI_PATH`) sont stockées en clair dans `llx_const`, convention Dolibarr. Aucune de ces constantes n'est un secret (le PHP CLI path est public par nature, les autres sont des paramètres métier).

## Dépendances vendored

Le dossier `vendor/` embarque les bibliothèques suivantes (pas de Composer requis au déploiement) :

| Bibliothèque | Rôle |
|---|---|
| `atgp/factur-x` v3.0.0 | Génération PDF Factur-X |
| `setasign/fpdi` | Lecture/écriture PDF |
| `setasign/fpdf` | Moteur PDF (utilisé par atgp, patch `/F 4` appliqué) |
| `smalot/pdfparser` | Parsing PDF |
| `symfony/polyfill-mbstring` | Compatibilité mbstring |

Ces bibliothèques ne sont pas maintenues par Lemon. Leur sécurité dépend des mainteneurs amont. Tout signalement de CVE amont nous sera transmis par les canaux habituels (GitHub, composer audit, etc.) et une nouvelle version vendored sera publiée au besoin.

## Historique des avis

_Aucune vulnérabilité corrigée n'a été publiée à ce jour._

---

Pour toute question sur la sécurité de ce module : hello@hellolemon.fr
