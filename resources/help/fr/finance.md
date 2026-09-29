# Guide finance

Les utilisateurs finance travaillent dans le portail assureur (**/insurer**), le portail courtier (**/broker**, pour la finance d’un cabinet) ou la console d’administration (**/admin**). Vous ne voyez que l’argent de votre organisation. La plupart des mouvements exigent deux personnes : celle qui prépare ne peut pas approuver.

## Pour commencer {#getting-started}

- Connectez-vous à votre portail. **Rapports** donne les rapports financiers autorisés par votre rôle ; **EN / FR** change la langue.
- Les montants sont affichés en FCFA (XAF). Chaque étape est une action sur la fiche et n’apparaît que si votre rôle et le statut le permettent.

## Paiements de primes {#payments}

- Les clients paient par MTN Mobile Money ou Orange Money ; les courtiers peuvent envoyer une demande de paiement depuis une proposition.
- Dans **/broker** : **Tableau des paiements**, **Paiements en attente**, **Paiements échoués** (**Relancer le paiement**) et paiements en double (**Demander un remboursement**).
- Dans **/admin** : les **demandes de paiement** listent toutes les tentatives et permettent d’en initier une.

## Commissions et relevés {#commissions}

1. **Commissions à recevoir** : les commissions sont constatées (**Constater une commission**), deviennent acquises (**Marquer acquise**) puis exigibles (**Rendre exigible**) au fil de l’encaissement. **Approuver la commission**, ajuster le montant, contester / **Résoudre la contestation** ; reprise ou contrepassation si une police est résiliée.
2. **Relevés partenaire** : **Générer les relevés** d’une période, **Approuver le relevé**, **Publier le relevé**. Le partenaire peut contester ou demander un ajustement, qui est approuvé ou rejeté.
3. **Demander un versement** sur un relevé publié crée une demande de versement : **Approuver le versement**, **Envoyer en paiement**, **Confirmer le paiement** (ou **Enregistrer l’échec**) ; **Annuler le versement** si nécessaire.

## Règlements et bordereaux {#settlements}

- **Règlements** entre courtiers et assureurs : **Préparer un règlement (polices)** ou un nouveau règlement depuis les obligations, **Calculer**, **Soumettre en revue**. L’approbateur utilise **Approuver le règlement** ou **Renvoyer**. Puis **Transmettre à la banque** / **Envoyer en paiement**, **Confirmer le paiement** (ou **Enregistrer l’échec bancaire**), confirmer le règlement. **Rapprocher** le relie à la banque ; **Contrepasser le règlement** l’annule.
- **Bordereaux** : le courtier utilise **Préparer un bordereau**, **Approuver le bordereau** et **Transmettre à l’assureur** ; l’assureur **Enregistrer l’accusé de réception** ou **Enregistrer le rejet**.
- Les **paiements de sinistres** sont demandés par l’équipe sinistres, puis approuvés et payés avec le même contrôle à deux personnes.

## Rapprochement et comptabilité {#reconciliation}

- **/admin → Rapprochements** compare l’argent reçu à l’argent attendu ; la liste montre les lignes rapprochées et les exceptions. Le **centre des exceptions** et les **relevés de compte** listent ce qui reste à traiter.
- Les écritures comptables (assureur) sont consultables en lecture seule.
- Les sessions de caisse et les taux de change sont accessibles aux rôles concernés.
- **Lots de règlement prestataires** (santé) : **Créer un lot de règlement**, puis **Payer le lot de règlement**.
