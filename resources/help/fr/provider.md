# Guide du prestataire de santé

Le portail prestataire (**/provider**) est l’espace web des hôpitaux, cliniques, pharmacies et laboratoires qui travaillent avec les assureurs sur OpesInsure. Chaque entrée de menu n’apparaît que si votre rôle l’autorise (accueil, médecin, facturation, pharmacie, laboratoire, finance ou administrateur prestataire).

## Connexion et tableau de bord {#getting-started}

- Connectez-vous sur **/provider/login**. Utilisez le lien de mot de passe oublié de la page de connexion pour le réinitialiser.
- Le **Tableau de bord prestataire** affiche des indicateurs pour votre établissement (demandes en attente, factures, paiements).
- Changez de langue avec **EN / FR** dans la barre du haut. Chaque ligne de tableau a une action **Ouvrir** pour voir le détail.

## Vérifier l’éligibilité {#eligibility}

1. Ouvrez **Vérification d’éligibilité**.
2. Choisissez une **méthode de recherche** : numéro de carte santé, scan du QR de la carte, numéro d’adhérent, numéro de police, pièce d’identité ou autre identifiant, ou nom + date de naissance.
3. Saisissez la recherche patient, le code de l’acte et la date, puis lancez **Vérification d’éligibilité**.
4. Le résultat indique si l’adhérent est couvert. Vous pouvez enchaîner directement sur **Nouvel accord préalable**.

## Accords préalables {#preauthorizations}

- **Liste des accords préalables** affiche vos demandes et leur statut.
- **Nouvel accord préalable** : renseignez le type de demande, l’ID de police, la référence adhérent, l’établissement, le code de l’acte, la quantité, le prix unitaire et les notes cliniques, puis **Envoyer la demande**.
- Si l’assureur pose une question, ouvrez la demande et répondez dans **Réponse à la demande d’information**, puis **Envoyer**.
- **Annuler la demande** retire une demande encore ouverte. Les actions n’apparaissent que si le statut le permet.

## Hospitalisations et épisodes de soins {#admissions}

- **Liste des hospitalisations → Nouvelle hospitalisation → Enregistrer l’admission** enregistre un séjour.
- **Demande de prolongation → Demander une prolongation** demande des jours supplémentaires (nouvelle date de fin et motif).
- **Sortie** enregistre la date de sortie.
- **Épisodes de soins** : ouvrez un épisode (**Nouvel épisode de soins**), **Ajouter un acte** pour chaque prestation, **Clôturer l’épisode**, puis **Générer la demande de remboursement**.

## Transmettre les factures {#claims}

1. Ouvrez **Liste des factures** puis **Nouvelle facture**.
2. Renseignez le code prestataire, l’établissement, la convention, l’ID de police, l’ID d’accord préalable (s’il existe), la référence adhérent et la référence de facture.
3. Ajoutez chaque acte avec **Ajouter une ligne** (code, date, quantité, prix unitaire).
4. **Enregistrer le brouillon** pour terminer plus tard, ou **Transmettre à l’assureur**.
5. Si l’assureur pose une question, répondez dans **Réponse à la question sur la facture** puis **Envoyer**.

L’assureur décide de la prise en charge ; vous suivez le statut dans la liste.

## Paiements, rapprochement et contestations {#settlements}

- **Règlements** : ouvrez un règlement pour voir les factures payées et **Télécharger le relevé**.
- **Rapprochement** : **Enregistrer un paiement reçu**, puis **Affecter à une demande** pour rapprocher l’argent de vos factures.
- **Contestations** : **Ouvrir une contestation** sur une facture, une ligne, un règlement ou un rapprochement (motif, montant, description) et **Soumettre la contestation**. L’assureur la traite.
- **Comptes prestataire** affiche votre solde avec chaque assureur (lecture seule).

## Conventions, documents et rapports {#documents}

- **Conventions** liste vos accords avec les assureurs ; **Grilles tarifaires** affiche les prix convenus.
- **Documents** : filtrez par type, **Télécharger** ou **Vérifier** un document. Les documents sont produits par la plateforme ; cette page ne permet pas de téléverser.
- **Rapports** : filtrez par établissement, statut et dates, puis **Exporter CSV**.
- **Notifications** liste les messages adressés à votre établissement.

## Administration {#administration}

Pour les administrateurs prestataire :

- **Gestion des utilisateurs** : **Affecter un utilisateur** à un rôle et à un périmètre d’établissements (tous ou ceux affectés), et **Révoquer** un accès.
- **Gestion des établissements** : ajoutez des services à un établissement.
- **Profil du prestataire** : les informations de votre organisation.
- **Paramètres d’intégration** : l’adresse de l’API et les règles pour connecter votre système hospitalier.
- **Journal d’audit** : qui a fait quoi dans votre espace.
