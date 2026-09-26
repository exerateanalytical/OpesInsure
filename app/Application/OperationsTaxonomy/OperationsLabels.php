<?php

declare(strict_types=1);

namespace App\Application\OperationsTaxonomy;

/** French display labels for the pack 10 codes (English labels are derived from the code). Display only — codes are the contract. */
final class OperationsLabels
{
    public const FR = [
        // task types
        'REVIEW' => 'Examiner', 'VERIFY_DOCUMENT' => 'Vérifier un document', 'REQUEST_INFORMATION' => 'Demander des informations', 'APPROVE' => 'Approuver',
        'REJECT' => 'Rejeter', 'ESCALATE' => 'Escalader', 'CALL_CUSTOMER' => 'Appeler le client', 'CONTACT_PARTNER' => 'Contacter le partenaire',
        'RECONCILE' => 'Rapprocher', 'INVESTIGATE' => 'Enquêter', 'ASSESS' => 'Évaluer', 'PAY' => 'Payer', 'CLOSE' => 'Clôturer',
        // queue types
        'UNASSIGNED' => 'Non attribué', 'MY_QUEUE' => 'Ma file', 'TEAM_QUEUE' => "File d'équipe", 'COMPLIANCE' => 'Conformité', 'UNDERWRITING' => 'Souscription',
        'CLAIMS' => 'Sinistres', 'FINANCE' => 'Finance', 'PROVIDER' => 'Prestataires', 'REINSURANCE' => 'Réassurance', 'CUSTOMER_SERVICE' => 'Service client', 'SECURITY' => 'Sécurité',
        // escalation reasons
        'SLA_RISK' => 'Risque de dépassement du délai', 'SLA_BREACH' => 'Délai dépassé', 'AUTHORITY_LIMIT' => 'Limite de pouvoir', 'COMPLIANCE_RISK' => 'Risque de conformité',
        'FRAUD_RISK' => 'Risque de fraude', 'HIGH_VALUE' => 'Montant élevé', 'CUSTOMER_ESCALATION' => 'Escalade du client', 'PARTNER_DELAY' => 'Retard du partenaire',
        'TECHNICAL_EXCEPTION' => 'Exception technique', 'OTHER' => 'Autre',
        // closure reasons
        'COMPLETED' => 'Terminé', 'APPROVED' => 'Approuvé', 'REJECTED' => 'Rejeté', 'WITHDRAWN' => 'Retiré', 'DUPLICATE' => 'Doublon', 'NO_ACTION_REQUIRED' => 'Aucune action requise',
        'REFERRED_EXTERNALLY' => "Renvoyé à l'externe", 'EXPIRED' => 'Expiré', 'CANCELLED' => 'Annulé',
        // complaint categories
        'SALES_CONDUCT' => 'Pratiques commerciales', 'MISREPRESENTATION' => 'Présentation trompeuse', 'PREMIUM' => 'Prime', 'POLICY_ISSUANCE' => 'Émission du contrat',
        'DOCUMENT' => 'Document', 'CLAIM_DELAY' => 'Retard de sinistre', 'CLAIM_DECISION' => 'Décision de sinistre', 'CLAIM_PAYMENT' => 'Paiement de sinistre',
        'REFUND' => 'Remboursement', 'COMMISSION' => 'Commission', 'BROKER_AGENT' => 'Courtier / agent', 'PRIVACY_DATA' => 'Données personnelles',
        'SERVICE_QUALITY' => 'Qualité de service', 'DIGITAL_ACCESS' => 'Accès numérique', 'FRAUD_CONCERN' => 'Soupçon de fraude',
        // complaint resolution reasons
        'RESOLVED_IN_FAVOR_CUSTOMER' => 'Résolu en faveur du client', 'RESOLVED_PARTIAL' => 'Résolu partiellement', 'NO_ERROR_FOUND' => 'Aucune erreur constatée',
        'REFERRED' => 'Renvoyé', 'OUTSIDE_SCOPE' => 'Hors champ',
        // notification events
        'QUOTE_CREATED' => 'Devis créé', 'QUOTE_EXPIRING' => 'Devis bientôt expiré', 'PROPOSAL_SUBMITTED' => 'Proposition soumise', 'UNDERWRITING_REFERRED' => 'Renvoyé en souscription',
        'PAYMENT_REQUESTED' => 'Paiement demandé', 'PAYMENT_RECEIVED' => 'Paiement reçu', 'PAYMENT_FAILED' => 'Paiement échoué', 'POLICY_ISSUED' => 'Contrat émis',
        'DOCUMENT_AVAILABLE' => 'Document disponible', 'POLICY_EXPIRING' => 'Contrat bientôt échu', 'RENEWAL_AVAILABLE' => 'Renouvellement disponible',
        'RENEWAL_OVERDUE' => 'Renouvellement en retard', 'ENDORSEMENT_APPROVED' => 'Avenant approuvé', 'CLAIM_ACKNOWLEDGED' => 'Sinistre enregistré',
        'CLAIM_EVIDENCE_REQUIRED' => 'Pièces justificatives requises', 'CLAIM_ASSESSMENT_SCHEDULED' => 'Expertise programmée', 'CLAIM_DECISION_AVAILABLE' => 'Décision de sinistre disponible',
        'CLAIM_PAYMENT_SENT' => 'Indemnité versée', 'COMMISSION_ACCRUED' => 'Commission constatée', 'COMMISSION_PAYABLE' => 'Commission exigible', 'COMMISSION_PAID' => 'Commission payée',
        'SETTLEMENT_DUE' => 'Règlement dû', 'SETTLEMENT_PAID' => 'Règlement effectué', 'KYC_REFRESH_DUE' => 'Mise à jour KYC requise', 'COMPLAINT_RECEIVED' => 'Réclamation reçue',
        'COMPLAINT_UPDATED' => 'Réclamation mise à jour', 'SECURITY_ALERT' => 'Alerte de sécurité', 'ACCOUNT_LOCKED' => 'Compte verrouillé', 'PASSWORD_CHANGED' => 'Mot de passe modifié',
        // document reasons
        'ISSUED_IN_ERROR' => 'Émis par erreur', 'FRAUD_OR_COMPROMISE' => 'Fraude ou compromission', 'POLICY_CANCELLED' => 'Contrat résilié',
        'LEGAL_OR_REGULATORY_ORDER' => 'Décision légale ou réglementaire', 'SECURITY_COMPROMISE' => 'Compromission de sécurité', 'DAMAGED' => 'Endommagé', 'LOST' => 'Perdu',
        'CORRECTED_DATA' => 'Données corrigées', 'SUPERSEDED_VERSION' => 'Version remplacée', 'NAME_OR_DETAIL_CORRECTION' => 'Correction du nom ou des détails',
        'CUSTOMER_REQUEST' => 'Demande du client', 'LOST_ORIGINAL' => 'Original perdu', 'AUTHENTICATED_COPY' => 'Copie certifiée',
        // retention classes
        'KYC_AML' => 'KYC / LBC-FT', 'POLICY_CONTRACT' => "Contrat d'assurance", 'CLAIM' => 'Sinistre', 'FINANCIAL_ACCOUNTING' => 'Comptabilité financière',
        'PROVIDER_HEALTH' => 'Prestataires santé', 'MEDICAL_RESTRICTED' => 'Médical restreint', 'REGULATORY_REPORTING' => 'Déclarations réglementaires',
        'AUDIT_SECURITY' => 'Audit et sécurité', 'COMMUNICATION' => 'Communications', 'COMPLAINT' => 'Réclamations', 'CONSENT' => 'Consentements', 'SYSTEM_LOG' => 'Journaux système',
    ];

    public static function en(string $code): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $code)));
    }

    public static function fr(string $code): string
    {
        return self::FR[$code] ?? self::en($code);
    }
}
