<?php

// Portail courtier (/broker) : libellés ajoutés par l'audit UI du 2026-09-27 (expérience web courtier / agent).
return [
    'columns' => [
        'policy' => 'Police', 'amount' => 'Montant', 'vested' => 'Acquis', 'paid' => 'Payé', 'status' => 'Statut',
        'settlement_number' => 'N° de règlement', 'period_start' => 'Début de période', 'period_end' => 'Fin de période', 'net_amount' => 'Montant net',
        'bordereau_number' => 'N° de bordereau', 'type' => 'Type', 'item_count' => 'Lignes', 'gross_premium' => 'Prime brute',
    ],
    'financial_operations' => 'Opérations financières',
    'workspace' => [
        'group' => 'Espace partenaire',
        'clients' => 'Mes clients',
        'new_quote' => 'Nouveau devis',
        'new_client' => 'Nouveau client',
        'leads' => 'Prospects',
        'commissions' => 'Commissions et relevés',
    ],
    // Liste du personnel / des accès (MembershipResource partagé : admin « Accès et rôles », portail « Personnel »).
    'staff' => [
        'model' => 'Attribution d’accès', 'plural' => 'Accès et rôles', 'user' => 'Utilisateur', 'organization' => 'Organisation', 'branch' => 'Agence', 'all_branches' => 'Toutes les agences',
        'role' => 'Rôle principal', 'status' => 'Statut', 'revoke' => 'Révoquer l’accès', 'empty_t' => 'Aucune attribution d’accès', 'empty_d' => 'Invitez un collègue ou rattachez un utilisateur existant à une organisation.',
    ],
    'statuses' => ['ACTIVE' => 'Actif', 'SUSPENDED' => 'Suspendu', 'REVOKED' => 'Révoqué'],
    'roles' => ['BROKER_ADMIN' => 'Administrateur du cabinet', 'BROKER_SUPERVISOR' => 'Superviseur', 'BROKER_STAFF' => 'Collaborateur', 'BRANCH_MANAGER' => 'Directeur d’agence', 'AGENT' => 'Agent'],
];
