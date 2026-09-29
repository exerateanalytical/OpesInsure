# Guide du collaborateur courtier

Le portail courtier (**/broker**) permet à votre cabinet de vendre, gérer les polices et suivre les sinistres de ses clients. En tant que collaborateur (producteur), vous voyez les dossiers qui vous sont affectés ; votre superviseur voit ceux de l’équipe et l’administrateur courtier ceux de tout le cabinet. Vous ne voyez jamais les clients d’un autre cabinet.

## Connexion et tableau de bord {#getting-started}

- Connectez-vous sur **/broker/login** (réinitialisation du mot de passe sur la même page). Votre profil est dans le menu utilisateur ; **EN / FR** change la langue.
- Le **Tableau de bord** résume votre activité. Le **Tableau de bord clients** et le **Tableau de bord sinistres** donnent le détail.
- La **Recherche globale** retrouve un client, un devis, une police ou un sinistre de votre périmètre.
- Les modifications passent par les actions nommées des menus **Actions…** de chaque fiche, jamais par une modification directe. Si une action manque, votre rôle ne la donne pas ou le dossier n’est pas au bon statut.

## Clients et prospects {#customers}

- **Mes clients** (**/broker/customers**) liste vos clients. **Nouveau client** en enregistre un ; sur une ligne : **Nouveau devis**, **Ouvrir le dossier KYC**, **Soumettre le dossier KYC**.
- **Prospects** est votre pipeline : **Nouveau prospect**, **Enregistrer l’activité**, **Faire avancer le prospect**. Ouvrez un prospect pour voir son historique.
- **Activité clients** affiche la chronologie d’un client (choisissez d’abord le client).
- Le menu **Espace partenaire** renvoie vers votre espace (**/account**) pour **Mes clients**, **Nouveau client**, **Nouveau devis**, **Prospects** et **Rapports** – les mêmes écrans que l’application mobile.

## Devis {#quotes}

1. Lancez un devis depuis **Mes clients → Nouveau devis** ou **Espace partenaire → Nouveau devis**.
2. Ouvrez-le dans **Devis** (**/broker/quotes**). La fiche compare les offres des assureurs.
3. Utilisez **Actions sur le devis** : **Retarifer le devis**, **Envoyer au client**, **Demander un devis à l’assureur** (risques nécessitant une cotation de l’assureur), **Saisir le devis de l’assureur**, **Demander une dérogation de prime**, **Générer la cotation**, **Enregistrer la comparaison**.
4. Quand le client accepte, choisissez **Transformer en proposition**. S’il refuse, **Enregistrer le refus du client** ; **Annuler le devis** le clôt.

Files utiles : **Tableau des devis**, **Devis exceptionnels**, **Éligibilité produits** (**Vérifier l’éligibilité** avant de coter).

## Propositions et paiement {#proposals}

1. Ouvrez la proposition dans **Propositions**. Utilisez **Actions sur la proposition** : **Saisir les réponses au questionnaire**, **Attester les déclarations**, **Enregistrer les déclarations**, **Définir les conditions de couverture**, **Joindre un document**.
2. **Soumettre la proposition** à l’assureur. S’il demande des informations, utilisez **Répondre à la demande**.
3. **Demander le paiement de la prime** envoie une demande de paiement mobile money au client ; **Télécharger le reçu** une fois payé.
4. Une fois la proposition payée, **Demander l’émission**.

Files : **Complétude des propositions**, **Demandes d’information**, **Offres conditionnelles**, **Propositions refusées**, **Paiements en attente**, **Paiements échoués** (**Relancer le paiement**).

## Polices et gestion {#policies}

- **Polices** liste les polices de votre portefeuille. Ouvrez-en une pour ses documents et son historique.
- **Actions police** : **Demander un avenant**, **Demander la résiliation**. **Gestion du contrat** : **Demander la remise en vigueur**, **Transférer le contrat**, **Export de portabilité**, **Demander un recouvrement**. **Demande de gestion** enregistre une demande du client.
- **Émettre l’attestation** est disponible sur les polices actives si votre rôle le permet.
- Files : **Tableau des polices**, **File des résiliations**, **Émissions échouées**, **Documents attendus**, **Affectation des vignettes**.

## Renouvellements {#renewals}

- Les **dossiers de renouvellement** listent les polices de votre portefeuille qui arrivent à échéance.
- **Recoter le renouvellement** (statut DUE ou CONTACTED) prépare les nouvelles conditions ; **Lier la police de remplacement** (statut QUOTED) rattache la police renouvelée.
- **Polices déchues** liste les polices non renouvelées.

## Sinistres {#claims}

- **Sinistres** liste les sinistres de vos clients. **Déclarer un sinistre pour un client** pour en ouvrir un ; **Vérifier la garantie au sinistre** d’abord en cas de doute.
- Ouvrez un sinistre pour le suivre dans ses onglets (revue des justificatifs, experts, règlements…) et ajouter des pièces. L’assureur expertise et décide.
- Les files des opérations sinistres (revue des justificatifs, préparation du règlement, recours, réouverture) montrent ce que le processus attend de vous.
