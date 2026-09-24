<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

/**
 * EN/FR display labels for the canonical enumeration codes that the master
 * file ships without labels (body types carry their own). Codes come only
 * from database/data/cameroon_vehicle_master_2026.json; this class adds
 * translations, never new codes. Seeded into vehicle_reference_values, where
 * admins can edit them without a deploy.
 */
final class VehicleReferenceLabels
{
    /** json enum key => reference group */
    public const GROUPS = [
        'body_types' => 'body_type',
        'usage_types' => 'usage',
        'ownership_types' => 'ownership',
        'powertrains' => 'powertrain',
        'hybrid_subtypes' => 'hybrid_subtype',
        'transmissions' => 'transmission',
        'drive_types' => 'drive_type',
        'vehicle_classes' => 'vehicle_class',
        'conditions' => 'condition',
        'value_types' => 'value_type',
        'provenance' => 'provenance',
        'cameroon_status' => 'cameroon_status',
    ];

    /** @var array<string, array<string, array{0: string, 1: string}>> */
    public const LABELS = [
        'usage' => [
            'PRIVATE_PERSONAL' => ['Private – personal', 'Privé – personnel'],
            'PRIVATE_FAMILY' => ['Private – family', 'Privé – familial'],
            'COMMERCIAL' => ['Commercial', 'Commercial'],
            'COMPANY' => ['Company vehicle', 'Véhicule de société'],
            'FLEET' => ['Fleet', 'Flotte'],
            'TAXI' => ['Taxi', 'Taxi'],
            'RIDE_HAILING' => ['Ride-hailing (VTC)', 'VTC'],
            'CAR_RENTAL' => ['Car rental', 'Location de véhicules'],
            'DRIVING_SCHOOL' => ['Driving school', 'Auto-école'],
            'PUBLIC_TRANSPORT' => ['Public transport', 'Transport public'],
            'INTERCITY_TRANSPORT' => ['Intercity transport', 'Transport interurbain'],
            'GOODS_TRANSPORT' => ['Goods transport', 'Transport de marchandises'],
            'DELIVERY' => ['Delivery', 'Livraison'],
            'COURIER' => ['Courier', 'Messagerie / coursier'],
            'CONSTRUCTION' => ['Construction', 'BTP'],
            'MINING' => ['Mining', 'Mines'],
            'AGRICULTURE' => ['Agriculture', 'Agriculture'],
            'GOVERNMENT' => ['Government', 'Administration publique'],
            'DIPLOMATIC' => ['Diplomatic', 'Diplomatique'],
            'NGO' => ['NGO', 'ONG'],
            'EMERGENCY' => ['Emergency services', "Services d'urgence"],
            'AMBULANCE' => ['Ambulance', 'Ambulance'],
            'SECURITY' => ['Security services', 'Sociétés de sécurité'],
            'POLICE' => ['Police', 'Police'],
            'SCHOOL_TRANSPORT' => ['School transport', 'Transport scolaire'],
            'STAFF_TRANSPORT' => ['Staff transport', 'Transport du personnel'],
            'HOTEL_SHUTTLE' => ['Hotel shuttle', "Navette d'hôtel"],
            'TOURISM' => ['Tourism', 'Tourisme'],
        ],
        'ownership' => [
            'INDIVIDUAL' => ['Individual', 'Particulier'],
            'COMPANY' => ['Company', 'Société'],
            'GOVERNMENT' => ['Government', 'État / administration'],
            'NGO' => ['NGO', 'ONG'],
            'DIPLOMATIC' => ['Diplomatic mission', 'Mission diplomatique'],
            'LEASED' => ['Leased', 'En leasing'],
            'FINANCED' => ['Financed (loan)', 'Financé (crédit)'],
            'RENTAL_COMPANY' => ['Rental company', 'Société de location'],
        ],
        'powertrain' => [
            'PETROL' => ['Petrol', 'Essence'],
            'DIESEL' => ['Diesel', 'Diesel'],
            'LPG' => ['LPG', 'GPL'],
            'CNG' => ['CNG', 'GNV'],
            'HYBRID' => ['Hybrid (HEV)', 'Hybride (HEV)'],
            'MILD_HYBRID' => ['Mild hybrid (MHEV)', 'Hybride léger (MHEV)'],
            'PHEV' => ['Plug-in hybrid (PHEV)', 'Hybride rechargeable (PHEV)'],
            'BEV' => ['Electric (BEV)', 'Électrique (BEV)'],
            'HYDROGEN' => ['Hydrogen', 'Hydrogène'],
            'OTHER' => ['Other', 'Autre'],
        ],
        'hybrid_subtype' => [
            'HEV' => ['Full hybrid (HEV)', 'Hybride complet (HEV)'],
            'MHEV' => ['Mild hybrid (MHEV)', 'Hybride léger (MHEV)'],
            'PHEV' => ['Plug-in hybrid (PHEV)', 'Hybride rechargeable (PHEV)'],
        ],
        'transmission' => [
            'MANUAL' => ['Manual', 'Manuelle'],
            'AUTOMATIC' => ['Automatic', 'Automatique'],
            'CVT' => ['CVT', 'CVT (variation continue)'],
            'DCT' => ['Dual-clutch (DCT)', 'Double embrayage (DCT)'],
            'AMT' => ['Automated manual (AMT)', 'Manuelle robotisée (AMT)'],
            'SINGLE_SPEED_EV' => ['Single-speed (EV)', 'Rapport unique (VE)'],
            'OTHER' => ['Other', 'Autre'],
        ],
        'drive_type' => [
            'FWD' => ['Front-wheel drive', 'Traction avant'],
            'RWD' => ['Rear-wheel drive', 'Propulsion'],
            'AWD' => ['All-wheel drive', 'Transmission intégrale'],
            '4WD' => ['Four-wheel drive (4x4)', 'Quatre roues motrices (4x4)'],
            'PART_TIME_4WD' => ['Part-time 4WD', '4x4 enclenchable'],
            'OTHER' => ['Other', 'Autre'],
        ],
        'vehicle_class' => [
            'PRIVATE_PASSENGER' => ['Private passenger car', 'Véhicule particulier'],
            'COMMERCIAL_PASSENGER' => ['Commercial passenger vehicle', 'Transport commercial de personnes'],
            'TAXI' => ['Taxi', 'Taxi'],
            'RIDE_HAILING' => ['Ride-hailing (VTC)', 'VTC'],
            'RENTAL' => ['Rental vehicle', 'Véhicule de location'],
            'LIGHT_COMMERCIAL' => ['Light commercial vehicle', 'Véhicule utilitaire léger'],
            'PICKUP_COMMERCIAL' => ['Commercial pickup', 'Pick-up utilitaire'],
            'MINIBUS_COMMERCIAL' => ['Commercial minibus', 'Minibus commercial'],
            'BUS' => ['Bus / coach', 'Autobus / autocar'],
            'GOODS_VEHICLE_LIGHT' => ['Light goods vehicle', 'Poids léger marchandises'],
            'GOODS_VEHICLE_MEDIUM' => ['Medium goods vehicle', 'Poids moyen marchandises'],
            'GOODS_VEHICLE_HEAVY' => ['Heavy goods vehicle', 'Poids lourd marchandises'],
            'TRACTOR_UNIT' => ['Tractor unit', 'Tracteur routier'],
            'TRAILER' => ['Trailer', 'Remorque'],
            'SPECIAL_VEHICLE' => ['Special vehicle', 'Véhicule spécial'],
            'GOVERNMENT_VEHICLE' => ['Government vehicle', 'Véhicule administratif'],
            'DIPLOMATIC_VEHICLE' => ['Diplomatic vehicle', 'Véhicule diplomatique'],
            'EMERGENCY_VEHICLE' => ['Emergency vehicle', "Véhicule d'urgence"],
            'FLEET_VEHICLE' => ['Fleet vehicle', 'Véhicule de flotte'],
        ],
        'condition' => [
            'NEW' => ['New', 'Neuf'],
            'USED' => ['Used', 'Occasion'],
            'RECONDITIONED' => ['Reconditioned', 'Reconditionné'],
            'SALVAGE_REBUILT' => ['Salvage / rebuilt', 'Épave reconstruite'],
        ],
        'value_type' => [
            'PURCHASE_PRICE' => ['Purchase price', "Prix d'achat"],
            'DECLARED_VALUE' => ['Declared value', 'Valeur déclarée'],
            'MARKET_VALUE' => ['Market value', 'Valeur vénale'],
            'ASSESSED_VALUE' => ['Assessed value', "Valeur d'expertise"],
            'SUM_INSURED' => ['Sum insured', 'Capital assuré'],
        ],
        'provenance' => [
            'MANUFACTURER' => ['Manufacturer', 'Constructeur'],
            'CAMEROON_DISTRIBUTOR' => ['Cameroon distributor', 'Distributeur au Cameroun'],
            'REGULATORY' => ['Regulatory', 'Réglementaire'],
            'INDUSTRY_DATABASE' => ['Industry database', 'Base de données sectorielle'],
            'MANUAL_VERIFIED' => ['Manually verified', 'Vérifié manuellement'],
            'CUSTOMER_SUBMITTED' => ['Customer submitted', 'Soumis par le client'],
        ],
        'cameroon_status' => [
            'OFFICIALLY_DISTRIBUTED' => ['Officially distributed', 'Distribué officiellement'],
            'KNOWN_MARKET_PRESENCE' => ['Known market presence', 'Présence connue sur le marché'],
            'IMPORTED' => ['Imported', 'Importé'],
            'HISTORICAL' => ['Historical', 'Historique'],
            'UNVERIFIED' => ['Unverified', 'Non vérifié'],
        ],
    ];

    /** @return array{0: string, 1: string} */
    public static function for(string $group, string $code): array
    {
        $fallback = ucfirst(strtolower(str_replace('_', ' ', $code)));

        return self::LABELS[$group][$code] ?? [$fallback, $fallback];
    }
}
