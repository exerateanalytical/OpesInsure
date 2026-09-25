<?php

return [
    'tabs' => ['overview' => 'Aperçu', 'governance' => 'Gouvernance', 'completeness' => 'Complétude', 'tests' => 'Tests et bac à sable', 'changes' => 'Modifications'],
    'overview' => ['class' => 'Classe', 'family' => 'Famille', 'branches' => 'Branches CIMA', 'coverages' => 'Garanties'],
    'stages' => ['DRAFT' => 'Brouillon', 'CONFIGURATION' => 'Configuration', 'TECHNICAL_REVIEW' => 'Revue technique', 'COMPLIANCE_REVIEW' => 'Revue conformité',
        'BUSINESS_APPROVAL' => 'Approbation métier', 'READY' => 'Prêt', 'PUBLISHED' => 'Publié', 'REJECTED' => 'Rejeté'],
    'governance' => ['workflow' => 'Circuit de gouvernance', 'next' => 'Étape suivante', 'scheduled' => 'Publication programmée le', 'target_market' => 'Marché cible',
        'prohibited_market' => 'Marché exclu', 'review_date' => 'Prochaine revue', 'when' => 'Date', 'from' => 'De', 'to' => 'Vers', 'decision' => 'Décision',
        'notes' => 'Notes', 'no_history' => 'Aucune étape de gouvernance enregistrée.'],
    'completeness' => ['status' => 'Complétude', 'COMPLETE' => 'Complet', 'COMPLETE_WITH_WARNINGS' => 'Complet avec avertissements', 'INCOMPLETE' => 'Incomplet — publication bloquée',
        'blocking' => 'Bloquant', 'warning' => 'Avertissement'],
    'checks' => ['REGULATORY_MAPPING' => 'Rattachement réglementaire', 'CIMA_BRANCH_AUTHORIZED' => 'Agrément de branche CIMA', 'PRICING_MODE' => 'Mode de tarification',
        'UNDERWRITING_MODE' => 'Mode de souscription', 'DOCUMENT_MAPPING' => 'Rattachement documentaire', 'ACCOUNTING_MAPPING' => 'Rattachement comptable',
        'CLAIMS_CONFIGURATION' => 'Configuration sinistres', 'PRODUCT_TESTS' => 'Tests produit', 'DOCUMENT_WARNING' => 'Documents', 'DOCUMENT_ENGINE_WARNING' => 'Moteur documentaire',
        'COVERAGES' => 'Garanties', 'WORDING_EN_FR' => 'Libellés EN/FR', 'GOVERNANCE_OWNER' => 'Responsable produit', 'REVIEW_DATE' => 'Date de revue'],
    'tests' => ['latest' => 'Dernière exécution', 'stale' => 'configuration modifiée depuis — nouvelle exécution requise', 'never_run' => 'Le jeu de tests n’a jamais été exécuté.',
        'code' => 'Code', 'name' => 'Nom', 'expected' => 'Attendu', 'no_cases' => 'Aucun cas de test.', 'result' => 'Résultat', 'failed_cases' => 'Cas en échec'],
    'diff' => ['no_base' => 'Première version : rien à comparer.', 'none' => 'Aucune modification par rapport à la version de base.', 'path' => 'Champ', 'change' => 'Modification', 'from' => 'Avant', 'to' => 'Après'],
    'actions' => ['advance' => 'Passer à l’étape suivante', 'reject' => 'Rejeter', 'publish' => 'Publier / programmer', 'publish_at' => 'Publier le (vide = maintenant)',
        'add_case' => 'Ajouter un cas de test', 'run_tests' => 'Exécuter les tests', 'attributes' => 'Attributs de gouvernance', 'notes' => 'Notes', 'reason' => 'Motif',
        'facts' => 'Données de risque', 'expected_eligibility' => 'Éligibilité attendue', 'expected_total' => 'Prime totale attendue (unités mineures)', 'owner' => 'Responsable produit',
        'code' => 'Code', 'name' => 'Nom', 'advanced' => 'Étape de gouvernance validée.', 'tests_done' => 'Exécution des tests terminée.'],
];
