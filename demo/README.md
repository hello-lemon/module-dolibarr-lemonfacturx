# Dolibarr de démo — LemonFacturX

Environnement de test isolé pour valider le module sans toucher à un Dolibarr de production.

## Accès

| Service | URL | Credentials |
|---|---|---|
| Dolibarr | http://ard.hellolemon.dev | axel / 0000 (ou admin / admin) |
| Mailpit (SMTP sink) | http://192.168.1.224:8025 | aucun |

Aucun mail n'est envoyé à l'extérieur : Postfix est désactivé et Dolibarr envoie vers mailpit (127.0.0.1:1025).

## Infra

| | |
|---|---|
| LXC | 115 (alias SSH `dolibarr-ard`, IP 192.168.1.224) |
| Dolibarr | 22.0.4 dans `/var/www/dolibarr/htdocs/` |
| Module | `/var/www/dolibarr/htdocs/custom/lemonfacturx/` |
| DB | `dolibarr_ard` (MariaDB) |
| Snapshot | `/root/dolibarr-demo-snapshot.sql.gz` |

## Fixtures générées

- 1 société émettrice : LEMON DEMO SASU, SIREN 732829320 (exemple Factur-X), TVA FR44732829320, IBAN FR7630001007941234567890185 (IBAN de test Banque de France)
- 6 tiers couvrant tous les cas métier
- 10 factures couvrant tous les cas du backlog Factur-X :

| Ref | Tiers | Cas métier testé |
|---|---|---|
| F001 | FR standard | Facture simple TVA 20% |
| F002 | FR standard | Multi-TVA (20% + 5,5%) |
| F003 | FR standard | TVA 0% (franchise) |
| F004 | FR standard | Avoir (`TYPE_CREDIT_NOTE`) |
| F005 | FR standard | Ligne en heures (10h × 80€) |
| F006 | FR standard | Ligne en jours (5j × 450€) |
| F007 | FR sans email | Cas BR-FR-13 : buyer sans email |
| F008 | DE GmbH | Autoliquidation UE, TVA 0% avec TVA intra |
| F009 | FR standard | Facture d'acompte (`TYPE_DEPOSIT`) 240€ TTC |
| F010 | FR standard | Facture finale avec F009 imputée (test `getSumDepositsUsed()`) |

## Scripts

### `reset-demo.sh`
Reset rapide (~5s) : drop DB + réimport du snapshot. À utiliser entre deux tests pour revenir à l'état propre.

### `rebuild-demo.sh`
Reconstruction complète (~1 min) : drop DB, réinstall Dolibarr, activation modules, constantes, fixtures, regénère le snapshot. À utiliser après toute modification de `fixtures.php`.

### `fixtures.php`
Script PHP invoqué par `rebuild-demo.sh`. Crée user/société/tiers/factures. Idempotent sur le user admin `axel`.

## Workflow de test d'un patch module

```
# sur le poste de dev
cd ~/Claude/Modules\ Dolibarr/module-dolibarr-lemonfacturx
# ... modifier le code ...
tar czf /tmp/lmx.tgz --exclude='.git' .
scp /tmp/lmx.tgz proxmox:/tmp/

# sur proxmox
pct push 115 /tmp/lmx.tgz /tmp/lmx.tgz
pct exec 115 -- bash -c 'rm -rf /var/www/dolibarr/htdocs/custom/lemonfacturx && mkdir /var/www/dolibarr/htdocs/custom/lemonfacturx && tar xzf /tmp/lmx.tgz -C /var/www/dolibarr/htdocs/custom/lemonfacturx && chown -R www-data:www-data /var/www/dolibarr/htdocs/custom/lemonfacturx && bash /var/www/dolibarr/htdocs/custom/lemonfacturx/demo/reset-demo.sh'
```

Puis ouvrir http://ard.hellolemon.dev, générer les PDF des 10 factures, valider manuellement ou via un script de test.
