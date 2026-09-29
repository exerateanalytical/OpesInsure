# Guide de l’administrateur plateforme

La console d’administration (**/admin**) sert à l’équipe plateforme OpesInsure pour paramétrer les organisations, utilisateurs, produits et intégrations, et superviser l’activité de tous les tenants. Chaque écran et chaque action dépendent des permissions : si vous ne voyez pas une entrée, votre rôle ne la donne pas.

## Connexion et navigation {#getting-started}

- Connectez-vous sur **/admin/login**. Utilisez le lien de réinitialisation si besoin ; votre profil est dans le menu utilisateur.
- La barre latérale regroupe les écrans par domaine ; réduisez-la avec la flèche. Changez **EN / FR** dans la barre du haut.
- La **recherche** retrouve un client, une police, un sinistre, un devis, un document ou un véhicule dans votre périmètre.
- Les listes ont des filtres, une recherche et le choix des colonnes ; une ligne ouvre la fiche, dont les actions sont en en-tête.

## Organisations et agences {#tenants}

- Les **tenants** regroupent toutes les organisations (assureurs, courtiers, prestataires). **Créez** un tenant, **modifiez** ses informations (raison sociale, immatriculation, fuseau horaire) et changez son statut pour l’activer ou le suspendre.
- **Agences** enregistre les agences d’une organisation ; un chef d’agence ne voit que la sienne.
- Les **paramètres de l’organisation** concernent l’organisation dans laquelle vous travaillez.

## Utilisateurs, adhésions et invitations {#users}

- **Utilisateurs** – créez et modifiez les comptes.
- Les **adhésions** relient un utilisateur à une organisation avec un rôle (et éventuellement une agence). Créez une adhésion pour donner accès ; **Révoquer** le retire.
- Les **invitations** invitent une personne par e-mail ou téléphone avec un rôle ; elles affichent destinataire, rôle, organisation et expiration. Révoquez une invitation qui ne doit plus servir.
- Les rôles portent les permissions ; un utilisateur ne voit que les écrans et données que son rôle autorise.

## Produits et tarifs {#products}

- **Produits d’assurance** : créez un produit, puis utilisez les actions du constructeur de produit sur sa fiche (attributs, cas de test, exécution des tests, passage en revue, publication ou rejet).
- **Versions de tarifs** : créez et modifiez les tarifs d’un produit. Seuls les produits publiés avec un tarif valide peuvent être vendus.
- Les écrans du dictionnaire réglementaire CIMA rattachent les produits aux branches et catégories de reporting CIMA.

## Polices et sinistres {#policies-claims}

- **Polices** : ouvrez une police pour faire un avenant, décider une demande de service, demander, examiner ou décider une résiliation, et émettre une attestation pour une police active (modèle, numéro de série, vignette).
- **Sinistres** : enregistrez un sinistre avec l’assistant pas à pas de la liste. Sur un sinistre, le menu d’actions couvre tout le cycle : affectation, expertise, revue de l’expertise, provisions et leur approbation, décision et son approbation, offre de règlement, règlement, demande / approbation / annulation de paiement, contestation et recours.
- Les décisions au-delà de l’autorité d’un utilisateur passent par les **demandes d’approbation** pour une seconde personne (principe des quatre yeux).

## Paiements et finance {#finance}

- **Demandes de paiement** : consultez tous les paiements mobile money ou bancaires et initiez-en un si nécessaire.
- **Rapprochements** : comparez les encaissements aux montants attendus ; la liste montre les lignes rapprochées et les exceptions.
- Pages finance : **relevés de compte** et **centre des exceptions** listent soldes et éléments à traiter.

## Documents {#documents}

- La **vue d’ensemble du moteur documentaire** mène au registre des documents (types et familles), aux documents par produit, à la traduction, à la configuration QR et signature, aux clés de signature, à la qualité et à l’audit.
- Le **concepteur d’en-tête** définit l’en-tête des documents émis.

## Exploitation, intégrations et paramètres {#operations}

- Les bureaux **Opérations**, **Plateforme** et **Configuration** rassemblent les files quotidiennes : tâches en échec, modèles de notification, calculs de tarification, exceptions de rapprochement, affectations d’experts, calendriers, catalogue d’indicateurs et contrôle des mises en production.
- **État des intégrations** montre l’état des connexions externes (paiements, SMS, assureurs).
- **Santé du système**, **sauvegarde et restauration**, **piste d’audit** et **activité de connexion** servent à la supervision et à la sécurité.
- **Paramètres de la plateforme** : canaux SMS, WhatsApp et Twilio, priorité des canaux et des fournisseurs, **fuseau horaire par défaut** (Africa/Douala sauf changement), vérification à l’inscription et e-mail d’intégration des partenaires. Ces changements s’appliquent à toute la plateforme : validez avec l’équipe avant d’enregistrer.
