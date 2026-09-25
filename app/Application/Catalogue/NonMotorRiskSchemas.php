<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

/**
 * Quote-wizard schemas for every non-motor line, built on institutional
 * master data (docs/spec/INSTITUTIONAL_MASTER_DATA_CATALOGUE_V1.md): ordered
 * steps category → subcategory → standard attributes → risk characteristics →
 * values/limits → usage/exposure → coverage. Free text is the fallback, not
 * the default: controlled fields are SELECT_MASTER / MULTI_SELECT_MASTER with
 * an "Other / Not listed" path to the review queue.
 *
 * Field keys: {key,label,label_en,label_fr,type,step,required, source:{domain,list},
 * parent_field, other_allowed, visible_if, item_fields, allocation, min,max,pattern,
 * currency, derived_from, maps_to, bulk_import}.
 * Types: text, number, money, date, boolean, select, select_master,
 * multi_select_master, repeater, file. visible_if: {field: value | [values] |
 * {not_empty:true} | {contains:x}}.
 *
 * `required` (schema root) lists the legacy tariff fact keys: they are derived
 * server-side from master codes by RiskFactsProcessor (maps_to documents how),
 * so approved tariffs keep rating unchanged.
 */
final class NonMotorRiskSchemas
{
    public const VERSION = 3; // v3: selection-first audit (docs/audit/FREE_TEXT_FIELDS_AUDIT.md)

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'HOME' => self::home(),
            'BUSINESS' => self::business(),
            'HEALTH' => self::health(),
            'LIFE' => self::life(),
            'TRAVEL' => self::travel(),
            'ACCIDENT' => self::accident(),
            'GROUP_ACCIDENT' => self::groupAccident(),
            'PROFESSIONAL_LIABILITY' => self::professional(),
            'CARGO' => self::cargo(),
            'CONSTRUCTION' => self::construction(),
            'EQUIPMENT' => self::equipment(),
            'AGRICULTURE' => self::agriculture(),
            'LIVESTOCK' => self::livestock(),
            'AQUACULTURE' => self::aquaculture(),
        ];
    }

    private static function home(): array
    {
        return self::schema([
            ['property', 'Property', 'Bien', [
                self::m('building_type', 'Property type', 'Type de bien', 'property', 'property_type', true, ['maps_to' => 'property_type']),
                self::m('occupancy_status', 'Occupancy', 'Occupation', 'property', 'occupancy', true, ['maps_to' => 'occupancy']),
                self::m('tenure_type', 'Tenure', 'Statut foncier', 'property', 'tenure_type', false),
                self::m('business_use', 'Business use of the premises', 'Usage professionnel', 'property', 'business_use', false),
            ]],
            ['construction', 'Construction', 'Construction', [
                self::m('construction_material', 'Construction material', 'Matériaux de construction', 'property', 'construction_material', true),
                self::m('roof_type', 'Roof', 'Toiture', 'property', 'roof_type', true),
                self::m('building_age_band', 'Building age', 'Âge du bâtiment', 'property', 'building_age_band', true),
                self::m('floor_count', 'Number of floors', "Nombre d'étages", 'property', 'floor_count', false),
            ]],
            ['protection', 'Security and fire protection', 'Sécurité et protection incendie', [
                self::mm('security_measures', 'Security', 'Sécurité', 'property', 'security_measure', false, ['maps_to' => 'security_features']),
                self::mm('fire_protection', 'Fire protection', 'Protection incendie', 'property', 'fire_protection', false),
                self::m('electrical_system', 'Electrical supply', 'Alimentation électrique', 'property', 'electrical_system', false),
                self::m('water_source', 'Water source', "Source d'eau", 'property', 'water_source', false),
            ]],
            ['location', 'Location', 'Localisation', [
                self::m('region', 'Region', 'Région', 'geography', 'cameroon_region', true),
                self::m('department', 'Department', 'Département', 'geography', 'cameroon_department', false, ['parent_field' => 'region']),
                self::m('city', 'City', 'Ville', 'geography', 'city', true, ['parent_field' => 'department']),
                self::f('address', 'Street / landmark', 'Rue / repère', 'text', false, ['max_length' => 255]),
                self::f('floor_area_m2', 'Floor area (m²)', 'Surface (m²)', 'number', false, ['min' => 1, 'max' => 1000000]),
            ]],
            ['values', 'Values to insure', 'Valeurs à assurer', [
                self::f('building_value_minor', 'Building replacement value (FCFA)', 'Valeur à neuf du bâtiment (FCFA)', 'money', false, ['min' => 0]),
                self::f('contents_value_minor', 'Contents value (FCFA)', 'Valeur du contenu (FCFA)', 'money', false, ['min' => 0]),
                self::f('stock_equipment_value_minor', 'Stock and equipment value (FCFA)', 'Valeur du stock et des équipements (FCFA)', 'money', false, ['min' => 0]),
            ]],
            ['cover', 'Cover', 'Garanties', [
                self::m('cover_package', 'Package', 'Formule', 'property', 'cover_package', true),
                self::m('deductible', 'Deductible', 'Franchise', 'common', 'deductible', false),
            ]],
        ], ['property_type', 'occupancy', 'city', 'declared_value_minor']);
    }

    private static function business(): array
    {
        return self::schema([
            ['business', 'Business', 'Entreprise', [
                self::m('business_sector', 'Industry sector', "Secteur d'activité", 'industries', 'sector', true),
                self::m('business_activity', 'Activity', 'Activité', 'industries', 'activity', true, ['parent_field' => 'business_sector', 'maps_to' => 'business_type']),
                self::m('business_size', 'Business size', "Taille de l'entreprise", 'business', 'size', false),
                self::m('legal_form', 'Legal form', 'Forme juridique', 'organizations', 'legal_entity_type', false),
                self::f('employee_count', 'Number of employees', "Nombre d'employés", 'number', true, ['min' => 0, 'max' => 100000]),
                self::m('turnover_band', 'Annual turnover', "Chiffre d'affaires annuel", 'business', 'turnover_band', false),
            ]],
            ['premises', 'Premises', 'Locaux', [
                self::m('premises_type', 'Premises', 'Locaux', 'business', 'premises_type', true),
                self::m('region', 'Region', 'Région', 'geography', 'cameroon_region', true),
                self::m('department', 'Department', 'Département', 'geography', 'cameroon_department', false, ['parent_field' => 'region']),
                self::m('city', 'City', 'Ville', 'geography', 'city', true, ['parent_field' => 'department']),
                self::m('construction_material', 'Construction material', 'Matériaux de construction', 'property', 'construction_material', false),
            ]],
            ['assets', 'Stock and equipment', 'Stock et équipements', [
                self::mm('stock_types', 'Stock', 'Stock', 'business', 'stock_type', false),
                self::mm('equipment_categories', 'Equipment and machinery', 'Équipements et machines', 'equipment', 'category', false),
                self::f('premises_value', 'Premises, stock and equipment value (FCFA)', 'Valeur des locaux, stock et équipements (FCFA)', 'number', true, ['min' => 0]),
            ]],
            ['exposure', 'Exposure and protection', 'Exposition et protection', [
                self::mm('liability_exposures', 'Liability exposure', 'Exposition RC', 'business', 'liability_exposure', false),
                self::mm('security_measures', 'Security', 'Sécurité', 'property', 'security_measure', false),
                self::mm('fire_protection', 'Fire protection', 'Protection incendie', 'property', 'fire_protection', false),
            ]],
            ['cover', 'Cover', 'Garanties', [
                self::m('cover_type', 'Cover', 'Formule', 'business', 'cover_package', true),
                self::mm('optional_covers', 'Additional covers', 'Garanties complémentaires', 'business', 'optional_cover', false),
                self::m('deductible', 'Deductible', 'Franchise', 'common', 'deductible', false),
            ]],
        ], ['business_type', 'employee_count', 'city', 'premises_value']);
    }

    private static function health(): array
    {
        $members = self::f('members', 'Members to cover', 'Personnes à couvrir', 'repeater', true, [
            'min_items' => 1, 'max_items' => 50, 'maps_to' => ['beneficiary_count', 'oldest_age'],
            'item_fields' => [
                self::m('member_type', 'Member', 'Bénéficiaire', 'health', 'member_type', true),
                self::f('full_name', 'Full name', 'Nom complet', 'text', true),
                self::f('date_of_birth', 'Date of birth', 'Date de naissance', 'date', true, ['min' => '1900-01-01', 'max' => 'today']),
                self::m('sex', 'Sex', 'Sexe', 'persons', 'gender', false),
            ],
        ]);

        return self::schema([
            ['plan', 'Plan', 'Formule', [
                self::m('plan_type', 'Plan type', 'Type de formule', 'health', 'plan_type', true),
                self::m('geographic_zone', 'Coverage area', 'Zone de couverture', 'health', 'geographic_zone', true, ['maps_to' => 'coverage_zone']),
                self::m('room_category', 'Room category', 'Catégorie de chambre', 'health', 'room_category', false),
                self::mm('benefit_categories', 'Benefits wanted', 'Prestations souhaitées', 'health', 'benefit_category', false),
            ]],
            ['company', 'Company', 'Entreprise', [
                self::f('company_name', 'Company', 'Entreprise', 'text', true, ['visible_if' => ['plan_type' => 'GROUP_CORPORATE'], 'institution_ref' => true]),
                self::f('employee_count', 'Number of employees', 'Nombre de salariés', 'number', true, ['min' => 1, 'visible_if' => ['plan_type' => 'GROUP_CORPORATE']]),
                self::mm('employee_grades', 'Grades covered', 'Catégories couvertes', 'health', 'employee_grade', false, ['visible_if' => ['plan_type' => 'GROUP_CORPORATE']]),
                self::f('dependants_covered', 'Dependants covered?', 'Ayants droit couverts ?', 'boolean', false, ['visible_if' => ['plan_type' => 'GROUP_CORPORATE']]),
                self::f('census_file', 'Staff census (CSV/Excel)', 'Fichier du personnel (CSV/Excel)', 'file', false, ['visible_if' => ['plan_type' => 'GROUP_CORPORATE'], 'bulk_import' => true, 'import_format' => ['csv', 'xlsx'], 'census_fields' => ['domain' => 'health', 'list' => 'census_field']]),
            ]],
            ['members', 'Members', 'Bénéficiaires', [$members]],
            ['history', 'Health declaration', 'Déclaration de santé', [
                self::mm('medical_declaration', 'Which of these apply to anyone covered?', "Lesquelles de ces situations concernent une personne couverte ?", 'health', 'medical_question', false, ['parent' => 'STANDARD_HEALTH', 'other_allowed' => false, 'maps_to' => 'pre_existing_conditions']),
            ]],
        ], ['beneficiary_count', 'oldest_age', 'coverage_zone', 'plan_type']);
    }

    private static function life(): array
    {
        $beneficiaries = self::f('beneficiaries', 'Beneficiaries', 'Bénéficiaires', 'repeater', true, [
            'min_items' => 1, 'max_items' => 10,
            'allocation' => ['field' => 'share_pct', 'total' => 100, 'group_by' => 'priority'],
            'item_fields' => [
                self::f('full_name', 'Full name', 'Nom complet', 'text', true, ['party_ref' => true]),
                self::m('relationship', 'Relationship to the life assured', "Lien avec l'assuré", 'life_insurance', 'relationship', true),
                self::f('date_of_birth', 'Date of birth', 'Date de naissance', 'date', false, ['min' => '1900-01-01', 'max' => 'today']),
                self::m('priority', 'Rank', 'Rang', 'life', 'beneficiary_priority', true),
                self::f('share_pct', 'Share (%)', 'Quote-part (%)', 'number', true, ['min' => 1, 'max' => 100]),
            ],
        ]);

        return self::schema([
            ['product', 'Product', 'Produit', [
                self::m('product_type', 'Life product', 'Produit vie', 'life', 'product_type', true, ['maps_to' => 'purpose']),
            ]],
            ['people', 'Policyholder and life assured', 'Souscripteur et assuré', [
                self::f('life_assured_is_policyholder', 'I am the person insured', "Je suis la personne assurée", 'boolean', true),
                self::f('life_assured_name', 'Life assured — full name', "Assuré — nom complet", 'text', true, ['visible_if' => ['life_assured_is_policyholder' => false], 'party_ref' => true]),
                self::m('life_assured_relationship', 'Relationship to you', 'Lien avec vous', 'persons', 'relationship', true, ['visible_if' => ['life_assured_is_policyholder' => false]]),
                self::f('life_assured_date_of_birth', 'Life assured — date of birth', "Assuré — date de naissance", 'date', true, ['maps_to' => 'insured_age', 'min' => '1900-01-01', 'max' => 'today']),
                self::m('occupation', 'Occupation of the life assured', "Profession de l'assuré", 'occupations', 'occupation', true),
                self::f('payer_is_policyholder', 'I pay the premiums', 'Je paie les primes', 'boolean', false),
                self::f('payer_name', 'Premium payer', 'Payeur des primes', 'text', true, ['visible_if' => ['payer_is_policyholder' => false], 'party_ref' => true]),
            ]],
            ['cover', 'Cover', 'Garanties', [
                self::f('cover_amount_minor', 'Sum assured (FCFA)', 'Capital assuré (FCFA)', 'money', true, ['min' => 1]),
                self::m('policy_term', 'Policy term', 'Durée du contrat', 'life_insurance', 'term_years', true, ['maps_to' => 'term_years']),
                self::m('premium_frequency', 'Premium frequency', 'Périodicité des primes', 'life', 'premium_frequency', true),
            ]],
            ['beneficiaries', 'Beneficiaries', 'Bénéficiaires', [$beneficiaries]],
            ['medical', 'Health declaration', 'Déclaration de santé', [
                self::mm('medical_conditions', 'Which of these apply to the life assured?', "Lesquelles de ces situations concernent l'assuré ?", 'life_insurance', 'medical_question', false, ['maps_to' => 'smoker']),
            ]],
        ], ['insured_age', 'cover_amount_minor', 'term_years', 'purpose']);
    }

    private static function travel(): array
    {
        return self::schema([
            ['trip', 'Trip', 'Voyage', [
                self::m('traveller_type', 'Who is travelling', 'Qui voyage', 'travel', 'traveller_type', true),
                self::m('trip_purpose', 'Purpose of trip', 'Motif du voyage', 'travel', 'purpose', true),
                self::m('trip_type', 'Trip type', 'Type de voyage', 'travel', 'trip_type', false),
                self::m('destination_country', 'Destination country', 'Pays de destination', 'geography', 'country', true, ['maps_to' => 'schengen']),
                self::f('departure_date', 'Departure date', 'Date de départ', 'date', true, ['min' => 'today']),
                self::f('return_date', 'Return date', 'Date de retour', 'date', true, ['min' => 'today']),
            ]],
            ['travellers', 'Travellers', 'Voyageurs', [
                self::f('traveller_count', 'Number of travellers', 'Nombre de voyageurs', 'number', true, ['min' => 1, 'max' => 50]),
                self::f('oldest_age', 'Age of the oldest traveller', 'Âge du voyageur le plus âgé', 'number', false, ['min' => 0, 'max' => 100]),
            ]],
            ['options', 'Options', 'Options', [
                self::mm('travel_options', 'Options', 'Options', 'travel', 'option', false),
                self::mm('hazardous_activities', 'High-risk sports', 'Sports à risques', 'common', 'hazardous_activity', true, ['visible_if' => ['travel_options' => ['contains' => 'HIGH_RISK_SPORTS']]]),
            ]],
        ], ['destination_country', 'departure_date', 'return_date', 'traveller_count']);
    }

    private static function accident(): array
    {
        return self::schema([
            ['insured', 'Insured people', 'Personnes assurées', [
                self::m('occupation', 'Occupation', 'Profession', 'occupations', 'occupation', true, ['maps_to' => 'occupation_class']),
                self::f('insured_count', 'Number of people covered', 'Nombre de personnes couvertes', 'number', true, ['min' => 1, 'max' => 50]),
                self::f('oldest_age', 'Age of the oldest person', 'Âge de la personne la plus âgée', 'number', true, ['min' => 0, 'max' => 80]),
                self::mm('hazardous_activities', 'Hazardous activities', 'Activités à risques', 'common', 'hazardous_activity', false),
            ]],
            ['cover', 'Cover', 'Garanties', [
                self::mm('benefits', 'Benefits', 'Garanties', 'accident', 'benefit', true),
                self::m('cover_scope', 'Scope', 'Étendue', 'accident', 'cover_scope', true),
                self::f('cover_amount', 'Death / disability benefit (XAF)', 'Capital décès / invalidité (XAF)', 'number', true, ['min' => 0]),
            ]],
        ], ['insured_count', 'occupation_class', 'cover_amount']);
    }

    private static function groupAccident(): array
    {
        return self::schema([
            ['group', 'Group', 'Groupe', [
                self::m('group_type', 'Group type', 'Type de groupe', 'accident', 'group_type', true),
                self::f('organisation_name', 'Organisation', 'Organisation', 'text', true, ['institution_ref' => true]),
                self::mm('member_categories', 'Member categories', "Catégories d'adhérents", 'accident', 'member_category', true),
                self::f('insured_count', 'Number of members', "Nombre d'adhérents", 'number', true, ['min' => 2, 'max' => 100000]),
                self::mm('occupation_families', 'Occupations in the group', 'Professions du groupe', 'occupations', 'family', true),
            ]],
            ['benefits', 'Benefits', 'Garanties', [
                self::m('benefit_basis', 'Benefit basis', 'Base des capitaux', 'accident', 'benefit_basis', true),
                self::m('benefit_multiple', 'Salary multiple', 'Multiple du salaire', 'group_life', 'benefit_multiple', true, ['visible_if' => ['benefit_basis' => 'SALARY_LINKED']]),
                self::f('fixed_benefit_minor', 'Benefit per person (FCFA)', 'Capital par personne (FCFA)', 'money', true, ['min' => 1, 'visible_if' => ['benefit_basis' => 'FIXED']]),
                self::mm('benefits', 'Benefits', 'Garanties', 'accident', 'benefit', true),
                self::m('cover_scope', 'Scope', 'Étendue', 'accident', 'cover_scope', true),
            ]],
            ['census', 'Members', 'Adhérents', [
                self::f('census_file', 'Member list (CSV/Excel)', 'Liste des adhérents (CSV/Excel)', 'file', false, ['bulk_import' => true, 'import_format' => ['csv', 'xlsx']]),
                self::f('members', 'Members', 'Adhérents', 'repeater', false, ['max_items' => 200, 'item_fields' => [
                    self::f('full_name', 'Full name', 'Nom complet', 'text', true),
                    self::f('date_of_birth', 'Date of birth', 'Date de naissance', 'date', true, ['min' => '1900-01-01', 'max' => 'today']),
                    self::m('occupation', 'Occupation', 'Profession', 'occupations', 'occupation', true),
                    self::f('annual_salary_minor', 'Annual salary (FCFA)', 'Salaire annuel (FCFA)', 'money', false),
                ]]),
            ]],
        ], ['group_type', 'insured_count', 'benefit_basis']);
    }

    private static function professional(): array
    {
        return self::schema([
            ['profession', 'Profession', 'Profession', [
                self::m('profession_category', 'Category', 'Catégorie', 'professional', 'profession_category', true),
                self::m('profession', 'Profession', 'Profession', 'professional', 'profession', true, ['parent_field' => 'profession_category']),
                self::m('experience_band', 'Experience', 'Expérience', 'professional', 'experience_band', true),
                self::mm('services', 'Other services provided', 'Autres prestations', 'professional', 'profession', false),
            ]],
            ['size', 'Practice', 'Cabinet', [
                self::m('revenue_band', 'Annual fee income', "Chiffre d'affaires annuel", 'business', 'turnover_band', true),
                self::m('headcount_band', 'Headcount', 'Effectif', 'business', 'employee_band', true),
                self::m('contract_exposure_band', 'Largest single contract', 'Plus gros contrat', 'professional', 'contract_exposure_band', false),
                self::m('territory', 'Territory', 'Territoire', 'public_liability', 'territory', true),
            ]],
            ['history', 'Claims history', 'Sinistralité', [
                self::m('claims_history', 'Claims in the last 3 years', 'Sinistres sur 3 ans', 'common', 'claims_history_band', true),
                self::mm('claims_causes', 'Causes of the claims', 'Causes des sinistres', 'claims', 'cause_of_loss', true, ['parent' => 'LIABILITY', 'visible_if' => ['claims_history' => ['ONE', 'TWO_THREE', 'FOUR_PLUS']]]),
            ]],
            ['cover', 'Cover', 'Garanties', [
                self::m('limit', 'Limit of liability', 'Plafond de garantie', 'common', 'liability_limit', true),
                self::m('deductible', 'Deductible', 'Franchise', 'common', 'deductible', true),
            ]],
        ], ['profession', 'revenue_band', 'limit']);
    }

    private static function cargo(): array
    {
        $sea = ['visible_if' => ['transport_mode' => ['SEA', 'MULTIMODAL', 'INLAND_WATER']]];

        return self::schema([
            ['goods', 'Goods', 'Marchandises', [
                self::m('cargo_category', 'Cargo type', 'Nature des marchandises', 'cargo', 'cargo_category', true),
                self::f('commodity_description', 'Commodity description', 'Désignation des marchandises', 'text', false, ['max_length' => 255]),
                self::m('packaging', 'Packaging', 'Conditionnement', 'cargo', 'packaging', true),
            ]],
            ['transport', 'Transport', 'Transport', [
                self::m('transport_mode', 'Transport mode', 'Mode de transport', 'cargo', 'transport_mode', true),
                self::m('container_type', 'Container', 'Conteneur', 'cargo', 'container_type', false, $sea),
                self::m('voyage_type', 'Voyage type', 'Type de transport', 'cargo', 'voyage_type', true),
                self::m('origin_country', 'Origin country', "Pays d'origine", 'geography', 'country', true),
                self::m('destination_country', 'Destination country', 'Pays de destination', 'geography', 'country', true),
                self::m('port_of_loading', 'Port of loading', 'Port de chargement', 'cargo', 'port', false, $sea),
                self::m('port_of_discharge', 'Port of discharge', 'Port de déchargement', 'cargo', 'port', false, $sea),
                self::m('carrier', 'Carrier / vessel', 'Transporteur / navire', 'cargo', 'shipping_line', false),
                self::m('incoterm', 'Incoterm', 'Incoterm', 'cargo', 'incoterm', true),
            ]],
            ['value', 'Value', 'Valeur', [
                self::f('cargo_value_minor', 'Insured value', 'Valeur assurée', 'money', true, ['min' => 1]),
                self::m('currency', 'Currency', 'Devise', 'currencies', 'currency', true),
                self::f('departure_date', 'Departure date', 'Date de départ', 'date', true),
            ]],
            ['cover', 'Cover', 'Garanties', [
                self::m('cargo_cover', 'Cover', 'Garantie', 'cargo', 'cargo_cover', true),
            ]],
        ], ['cargo_category', 'transport_mode', 'cargo_value_minor']);
    }

    private static function construction(): array
    {
        return self::schema([
            ['project', 'Project', 'Projet', [
                self::m('project_type', 'Project type', 'Type de projet', 'construction', 'project_type', true),
                self::m('structure_type', 'Structure', "Type d'ouvrage", 'construction', 'structure_type', false),
                self::m('project_phase', 'Current phase', 'Phase actuelle', 'construction', 'project_phase', true),
                self::m('region', 'Region', 'Région', 'geography', 'cameroon_region', true),
                self::m('department', 'Department', 'Département', 'geography', 'cameroon_department', false, ['parent_field' => 'region']),
                self::m('city', 'City / town', 'Ville', 'geography', 'city', true, ['parent_field' => 'department']),
                self::f('site_address', 'Site landmark', 'Repère du chantier', 'text', false, ['max_length' => 255]),
            ]],
            ['contractor', 'Contractor', 'Entreprise', [
                self::f('contractor_name', 'Contractor', 'Entreprise de travaux', 'text', true, ['institution_ref' => true]),
                self::m('contractor_role', 'Contractor role', "Rôle de l'entreprise", 'construction', 'contractor_role', true),
                self::m('contractor_size', 'Contractor size', "Taille de l'entreprise", 'construction', 'contractor_size', false),
                self::mm('site_machinery', 'Site machinery', 'Engins de chantier', 'construction', 'site_machinery', false),
            ]],
            ['values', 'Values and duration', 'Montants et durée', [
                self::f('contract_value_minor', 'Contract value (FCFA)', 'Montant du marché (FCFA)', 'money', true, ['min' => 1]),
                self::m('duration_band', 'Duration', 'Durée', 'construction', 'duration_band', true),
                self::f('start_date', 'Start date', 'Date de début', 'date', true, ['min' => 'today']),
            ]],
            ['cover', 'Cover', 'Garanties', [
                self::mm('covers', 'Covers', 'Garanties', 'construction', 'engineering_cover', true),
                self::m('deductible', 'Deductible', 'Franchise', 'common', 'deductible', false),
            ]],
        ], ['project_type', 'contract_value_minor']);
    }

    private static function equipment(): array
    {
        return self::schema([
            ['equipment', 'Equipment', 'Équipement', [
                self::m('equipment_category', 'Category', 'Catégorie', 'equipment', 'category', true),
                self::m('manufacturer', 'Manufacturer', 'Fabricant', 'equipment', 'manufacturer', true, ['parent_field' => 'equipment_category']),
                self::m('model', 'Model', 'Modèle', 'equipment', 'model', false, ['parent_field' => 'manufacturer']),
                self::f('serial_number', 'Serial number', 'Numéro de série', 'text', false, ['pattern' => '^[A-Za-z0-9\-/ ]{3,40}$']),
                self::f('year', 'Year of manufacture', 'Année de fabrication', 'number', false, ['min' => 1960, 'max' => (int) date('Y') + 1]),
                self::m('age_band', 'Age', 'Âge', 'equipment', 'age_band', false),
            ]],
            ['usage', 'Capacity and usage', 'Capacité et utilisation', [
                self::f('capacity', 'Capacity', 'Capacité', 'number', false, ['min' => 0]),
                self::m('capacity_unit', 'Capacity unit', 'Unité', 'equipment', 'capacity_unit', false, ['visible_if' => ['capacity' => ['not_empty' => true]]]),
                self::m('usage_type', 'Usage', 'Utilisation', 'equipment', 'usage_type', true),
            ]],
            ['value', 'Value', 'Valeur', [
                self::f('equipment_value_minor', 'Replacement value (FCFA)', 'Valeur à neuf (FCFA)', 'money', true, ['min' => 1]),
                self::m('deductible', 'Deductible', 'Franchise', 'common', 'deductible', false),
            ]],
        ], ['equipment_category', 'equipment_value_minor']);
    }

    private static function agriculture(): array
    {
        return self::schema([
            ['crop', 'Crop', 'Culture', [
                self::m('crop', 'Crop', 'Culture', 'agriculture', 'crop', true),
                self::m('variety_type', 'Variety', 'Variété', 'agriculture', 'variety_type', false),
                self::m('farming_system', 'Farming system', 'Système de culture', 'agriculture', 'farming_system', true),
                self::m('farm_type', 'Farm type', "Type d'exploitation", 'agriculture', 'farm_type', false),
            ]],
            ['farm', 'Farm', 'Exploitation', [
                self::f('area_hectares', 'Area (hectares)', 'Superficie (hectares)', 'number', true, ['min' => 0.01, 'max' => 1000000]),
                self::m('region', 'Region', 'Région', 'geography', 'cameroon_region', true),
                self::m('department', 'Department', 'Département', 'geography', 'cameroon_department', false, ['parent_field' => 'region']),
                self::m('irrigation_type', 'Irrigation', 'Irrigation', 'agriculture', 'irrigation_type', true),
            ]],
            ['season', 'Season and yield', 'Campagne et rendement', [
                self::m('season', 'Season', 'Campagne', 'agriculture', 'season', true),
                self::m('planting_month', 'Planting month', 'Mois de plantation', 'common', 'month', false),
                self::m('harvest_month', 'Harvest month', 'Mois de récolte', 'common', 'month', false),
                self::f('expected_yield', 'Expected yield', 'Rendement attendu', 'number', false, ['min' => 0]),
                self::m('yield_unit', 'Yield unit', 'Unité', 'agriculture', 'yield_unit', false, ['visible_if' => ['expected_yield' => ['not_empty' => true]]]),
            ]],
            ['exposure', 'Exposure and value', 'Exposition et valeur', [
                self::mm('weather_exposure', 'Weather exposure', 'Exposition climatique', 'agriculture', 'weather_exposure', false),
                self::f('sum_insured_minor', 'Sum insured (FCFA)', 'Somme assurée (FCFA)', 'money', true, ['min' => 1]),
            ]],
        ], ['crop', 'area_hectares', 'sum_insured_minor']);
    }

    private static function livestock(): array
    {
        return self::schema([
            ['animals', 'Animals', 'Animaux', [
                self::m('animal_type', 'Animal', 'Animal', 'livestock', 'animal_type', true),
                self::m('breed', 'Breed', 'Race', 'livestock', 'breed', false, ['parent_field' => 'animal_type']),
                self::f('head_count', 'Number of animals', "Nombre d'animaux", 'number', true, ['min' => 1, 'max' => 10000000]),
                self::m('age_band', 'Age group', "Classe d'âge", 'livestock', 'age_band', true),
                self::m('production_purpose', 'Purpose', 'Finalité', 'livestock', 'production_purpose', true),
            ]],
            ['husbandry', 'Husbandry and health', 'Élevage et santé', [
                self::m('housing_system', 'Husbandry', "Mode d'élevage", 'livestock', 'housing_system', true),
                self::m('vaccination_status', 'Vaccination', 'Vaccination', 'livestock', 'vaccination_status', true),
                self::m('mortality_band', 'Mortality (last 12 months)', 'Mortalité (12 derniers mois)', 'livestock', 'mortality_band', true),
                self::m('region', 'Region', 'Région', 'geography', 'cameroon_region', true),
            ]],
            ['value', 'Value', 'Valeur', [
                self::f('value_per_head_minor', 'Value per animal (FCFA)', 'Valeur par tête (FCFA)', 'money', true, ['min' => 1]),
            ]],
        ], ['animal_type', 'head_count', 'value_per_head_minor']);
    }

    private static function aquaculture(): array
    {
        return self::schema([
            ['operation', 'Operation', 'Activité', [
                self::m('operation_type', 'Operation', 'Activité', 'fisheries', 'operation_type', true),
                self::m('species', 'Species', 'Espèce', 'fisheries', 'species', true),
                self::m('production_system', 'Production system', 'Système de production', 'fisheries', 'production_system', true, ['visible_if' => ['operation_type' => ['POND_FARMING', 'CAGE_FARMING', 'HATCHERY']]]),
                self::m('vessel_type', 'Vessel', 'Embarcation', 'marine', 'vessel_type', false, ['visible_if' => ['operation_type' => ['MARINE_FISHING', 'INLAND_FISHING']]]),
            ]],
            ['stock', 'Stock and site', 'Stock et site', [
                self::f('stock_count', 'Number of fish / fry', 'Nombre de poissons / alevins', 'number', false, ['min' => 0]),
                self::f('unit_count', 'Number of ponds / cages', "Nombre d'étangs / cages", 'number', false, ['min' => 0]),
                self::m('water_source', 'Water source', "Source d'eau", 'fisheries', 'water_source', true),
                self::mm('disease_controls', 'Disease controls', 'Mesures sanitaires', 'fisheries', 'disease_control', false),
                self::m('region', 'Region', 'Région', 'geography', 'cameroon_region', true),
            ]],
            ['value', 'Value', 'Valeur', [
                self::f('sum_insured_minor', 'Sum insured (FCFA)', 'Somme assurée (FCFA)', 'money', true, ['min' => 1]),
            ]],
        ], ['operation_type', 'species', 'sum_insured_minor']);
    }

    // ------------------------------------------------------------------ helpers

    private static function schema(array $steps, array $required): array
    {
        $fields = [];
        foreach ($steps as [$key, $en, $fr, $stepFields]) {
            foreach ($stepFields as $f) {
                $fields[] = ['step' => $key] + $f;
            }
        }

        return [
            'version' => self::VERSION,
            'steps' => array_map(fn ($s) => ['key' => $s[0], 'label' => $s[1], 'label_en' => $s[1], 'label_fr' => $s[2]], $steps),
            'fields' => $fields,
            'required' => $required,
        ];
    }

    private static function f(string $key, string $en, string $fr, string $type, bool $required, array $extra = []): array
    {
        return ['key' => $key, 'label' => $en, 'label_en' => $en, 'label_fr' => $fr, 'type' => $type, 'required' => $required] + $extra;
    }

    private static function m(string $key, string $en, string $fr, string $domain, string $list, bool $required, array $extra = []): array
    {
        return self::f($key, $en, $fr, 'select_master', $required, $extra + ['source' => ['domain' => $domain, 'list' => $list], 'other_allowed' => true]);
    }

    private static function mm(string $key, string $en, string $fr, string $domain, string $list, bool $required, array $extra = []): array
    {
        return self::f($key, $en, $fr, 'multi_select_master', $required, $extra + ['source' => ['domain' => $domain, 'list' => $list], 'other_allowed' => true]);
    }
}
