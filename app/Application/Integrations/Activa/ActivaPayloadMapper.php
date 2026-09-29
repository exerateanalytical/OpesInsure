<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Models\CarrierApiConnection;
use App\Models\Party;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Quote;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Our records → Activa request bodies (shapes from docs/integrations/activa/operations_2026-09-29.json).
 *
 * Sources, in order of precedence (later wins):
 *   1. what we know: policy dates / amounts (terms_snapshot, XAF has no minor unit), party identity + primary contacts,
 *      quote risk facts (vehicle, travel, insured persons), product and tenant names;
 *   2. connection settings (non-secret): intermediary codes, per-family contract defaults, payment defaults;
 *   3. risk_facts.activa.* on the quote: per-transaction values captured by the product questions.
 * A value Activa requires that none of these supplies stops the sync with MAPPING_REQUIRED — nothing is guessed.
 */
final class ActivaPayloadMapper
{
    public const FAMILIES = ['TRAVEL', 'AUTO', 'MRH', 'SANTE', 'IA'];

    /** Activa product family of a policy (config activa.lines by quote line code, or risk_facts.activa.family). */
    public function family(Policy $policy): ?string
    {
        $quote = $this->quote($policy);
        $explicit = strtoupper((string) data_get($quote?->risk_facts, 'activa.family', ''));
        if (in_array($explicit, self::FAMILIES, true)) {
            return $explicit;
        }
        $line = strtoupper((string) ($quote?->line_code ?? ''));

        return config("activa.lines.{$line}");
    }

    public function quote(Policy $policy): ?Quote
    {
        return $policy->proposal?->offer?->quote;
    }

    // ---- Travel ---------------------------------------------------------------------------------------------------

    /** Body of POST /travel/quotes_requests. */
    public function travelQuote(Policy|Quote $subject, CarrierApiConnection $c): array
    {
        $quote = $subject instanceof Policy ? $this->quote($subject) : $subject;
        $f = (array) ($quote?->risk_facts ?? []);
        $t = (array) ($f['travel'] ?? $f);
        $start = $t['start_date'] ?? $t['departure_date'] ?? ($subject instanceof Policy ? $subject->coverage_starts_at?->toDateString() : null);
        $end = $t['end_date'] ?? $t['return_date'] ?? ($subject instanceof Policy ? $subject->coverage_ends_at?->toDateString() : null);
        $area = $t['destination_area'] ?? $t['destination_zone'] ?? $t['zone'] ?? null;
        $types = [
            'adult' => (int) ($t['travelers']['adult'] ?? $t['adults'] ?? 0),
            'children' => (int) ($t['travelers']['children'] ?? $t['children'] ?? 0),
            'senior' => (int) ($t['travelers']['senior'] ?? $t['seniors'] ?? 0),
        ];
        $people = $this->travellers($subject instanceof Policy ? $subject->party : $quote?->party, $t);
        if (array_sum($types) === 0) {
            foreach ($people as $p) {
                $age = $p['birth_date'] ? Carbon::parse($p['birth_date'])->age : null;
                $types[$age === null ? 'adult' : ($age < 18 ? 'children' : ($age >= 65 ? 'senior' : 'adult'))]++;
            }
            $types['adult'] += array_sum($types) === 0 ? 1 : 0;
        }
        $ages = array_filter(array_map(fn ($p) => $p['birth_date'] ? Carbon::parse($p['birth_date'])->age : null, $people), fn ($a) => $a !== null);
        $oldest = $t['oldest_traveler_age'] ?? ($ages ? max($ages) : null);
        $count = array_sum($types);

        $body = [
            'context' => [
                'currency' => $c->setting('travel.currency', config('activa.travel.currency')),
                'country' => $c->setting('travel.country', config('activa.travel.country')),
                'language' => $c->setting('travel.language', config('activa.travel.language')),
            ],
            'product_criteria' => ['category' => $t['category'] ?? $t['plan'] ?? $c->setting('travel.category', config('activa.travel.category'))],
            'travel' => [
                'destination_area' => $area,
                'start_date' => $start ? Carbon::parse($start)->toDateString() : null,
                'end_date' => $end ? Carbon::parse($end)->toDateString() : null,
                'travelers' => ['composition' => $t['composition'] ?? ($count <= 1 ? 'single' : 'group'), 'types' => $types, 'oldest_traveler_age' => $oldest !== null ? (int) $oldest : null],
            ],
        ];
        $body = array_replace_recursive($body, (array) data_get($f, 'activa.travel_quote', []));
        $this->require($body, ['travel.destination_area', 'travel.start_date', 'travel.end_date', 'travel.travelers.oldest_traveler_age'], ActivaServices::TRAVEL, 'getTravelQuote');

        return $body;
    }

    /** Body of POST /travel/policies. */
    public function travelPolicy(Policy $policy, string $quoteCode, CarrierApiConnection $c): array
    {
        $f = (array) ($this->quote($policy)?->risk_facts ?? []);
        $t = (array) ($f['travel'] ?? $f);
        $holder = $this->person($policy->party);
        $people = $this->travellers($policy->party, $t);
        $holderTravels = (bool) ($t['holder_is_beneficiary'] ?? $people === []);
        if ($people === []) {
            $people = [$holder];
        }
        $beneficiaries = array_map(fn (array $p) => array_filter([
            'title' => $p['title'], 'first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'email' => $p['email'], 'phone_number' => $p['phone'],
            'birth_date' => $p['birth_date'], 'passport_number' => $p['passport_number'],
            'destination_country' => $p['destination_country'] ?? ($t['destination_country'] ?? null),
            'residence_country' => $p['residence_country'] ?? ($t['residence_country'] ?? 'CAMEROUN'),
            'nationality' => $p['nationality'] ?? ($t['nationality'] ?? null),
        ], fn ($v) => $v !== null && $v !== ''), $people);

        $body = [
            'subscription_country' => $c->setting('travel.subscription_country', config('activa.travel.subscription_country')),
            'language_code' => $c->setting('travel.language', config('activa.travel.language')),
            'quote_code' => $quoteCode,
            'agent_scope' => $c->setting('travel.agent_scope'),
            'policy_holder' => [array_filter([
                'title' => $holder['title'], 'first_name' => $holder['first_name'], 'last_name' => $holder['last_name'], 'birth_date' => $holder['birth_date'],
                'email' => $holder['email'], 'phone_number' => $holder['phone'], 'is_policy_beneficiary' => $holderTravels ? 1 : 0,
            ], fn ($v) => $v !== null && $v !== '')],
            'beneficiaries' => array_values($beneficiaries),
            'consents' => array_values((array) ($t['consents'] ?? [])),
            'payment' => ['type' => 'MANAGED_BY_PARTNER'],
            'addons' => array_values((array) ($t['addons'] ?? [])),
        ];
        $body = array_filter(array_replace_recursive($body, (array) data_get($f, 'activa.travel_policy', [])), fn ($v) => $v !== null);
        $this->require($body, ['quote_code', 'policy_holder.0.last_name', 'policy_holder.0.birth_date', 'beneficiaries.0.last_name'], ActivaServices::TRAVEL, 'createTravelPolicy');

        return $body;
    }

    /** Body of PATCH /travel/policies/{id}: dates and people as we now hold them. */
    public function travelUpdate(Policy $policy): array
    {
        $holder = $this->person($policy->party);

        return array_filter([
            'start_date' => $policy->coverage_starts_at?->toDateString(),
            'end_date' => $policy->coverage_ends_at?->toDateString(),
            'policy_holder' => array_filter(['title' => $holder['title'], 'first_name' => $holder['first_name'], 'last_name' => $holder['last_name'], 'email' => $holder['email'], 'phone_number' => $holder['phone']]),
        ]);
    }

    // ---- SouscriptionCMR --------------------------------------------------------------------------------------------

    /** Body of POST SouscriptionCMR/NewContract*CMR (and, with $previous, RenouvellementCMR/Renew*CMR). */
    public function contract(Policy $policy, string $family, CarrierApiConnection $c, ?array $previous = null): array
    {
        $quote = $this->quote($policy);
        $f = (array) ($quote?->risk_facts ?? []);
        $terms = (array) ($policy->terms_snapshot ?? []);
        $person = $this->person($policy->party);
        $inter = (array) $c->setting('intermediary', []);
        $codecate = data_get($f, 'activa.codecate') ?? $c->setting("categories.{$family}") ?? config("activa.categories.{$family}");
        $premium = (int) ($terms['premium_minor'] ?? $policy->premium_minor ?? 0);
        $tax = (int) ($terms['tax_minor'] ?? 0);
        $fee = (int) ($terms['fee_minor'] ?? 0);
        $total = (int) ($terms['total_minor'] ?? $policy->premium_minor ?? ($premium + $tax + $fee));

        $body = [
            'refeinte' => $policy->policy_number,
            'codecate' => $codecate !== null && $codecate !== '' ? (int) $codecate : null,
            'dateeffe' => $this->dt($policy->coverage_starts_at),
            'dateeche' => $this->dt($policy->coverage_ends_at),
            'datesous' => $this->dt($policy->issued_at ?? now()),
            'dateemis' => $this->dt($policy->issued_at ?? now()),
            'datecomp' => $this->dt($policy->issued_at ?? now()),
            'creeLe' => $this->dt(now()), 'creepar' => 'OPESINSURE',
            'primtota' => $total, 'primnett' => $premium, 'taxeprim' => $tax, 'accequit' => $fee,
            'codedevi' => $policy->currency ?: 'XAF',
            'productName' => $policy->proposal?->offer?->product?->name,
            'intermediaryName' => $policy->tenant?->trade_name ?: $policy->tenant?->legal_name,
            'code_demandeur' => $inter['code_demandeur'] ?? null,
            'code_acces' => $inter['code_acces'] ?? null,
            'code_intermediaire' => $inter['code_intermediaire'] ?? null,
            'code_compagnie' => $inter['code_compagnie'] ?? null,
            'code_intermediaire_orass' => $inter['code_intermediaire_orass'] ?? null,
            'bureau' => $inter['bureau'] ?? null,
            'point_de_vente' => $inter['point_de_vente'] ?? null,
            'type_intermediaire' => $inter['type_intermediaire'] ?? null,
            'codeappo' => isset($inter['codeappo']) ? (int) $inter['codeappo'] : null,
            'readytoInsert' => true,
            'vassure' => array_filter([
                'raissoci' => $person['last_name'] ?: $person['full_name'], 'prenassu' => $person['first_name'], 'teleassu' => $person['phone'], 'telporas' => $person['phone'],
                'mailassu' => $person['email'], 'sexeassu' => $person['gender'], 'numpieid' => $person['id_number'], 'codtyppi' => $person['id_type'],
                'adreassu' => $person['address'], 'creePar' => 'OPESINSURE', 'creeLe' => $this->dt(now()), 'refeassu' => $policy->party_id,
            ], fn ($v) => $v !== null && $v !== ''),
            'detailproduction' => $this->risks($policy, $family, $f, $person),
            'encaissement' => [],
        ];
        if ($family === 'SANTE' || $family === 'IA') {
            $body['risquefamille'] = $this->family_members($f);
        }
        if ($previous !== null) {
            // RenouvellementCMR carries no intermediary routing block (only code_demandeur): the contract already has it.
            unset($body['code_acces'], $body['code_intermediaire'], $body['code_compagnie'], $body['code_intermediaire_orass'], $body['bureau'], $body['point_de_vente'], $body['type_intermediaire']);
            $body['idctr'] = $previous['idctr'] ?? null;
            $body['affaireNouvelleID'] = $previous['idctr'] ?? null;
            $body['numepolice'] = $previous['policy_number'] ?? null;
            $body['policectr'] = $previous['policy_number'] ?? null;
        }
        $body = array_replace_recursive($body, (array) $c->setting("contract_defaults.{$family}", []), (array) data_get($f, 'activa.contract', []));
        $body = $this->prune($body);

        $required = ['codecate', 'dateeffe', 'dateeche', 'primtota', 'vassure.raissoci'];
        if ($family === 'AUTO') {
            $required[] = 'detailproduction.0.numeimma';
        }
        if ($previous !== null) {
            $required[] = 'idctr';
        }
        $this->require($body, $required, ActivaServices::SUBSCRIPTION, $previous ? 'RenewContract' : 'NewContract');

        return $body;
    }

    /** Body of POST SouscriptionCMR/AttestationCMR/{idctr}. */
    public function attestation(Policy $policy, CarrierApiConnection $c): array
    {
        $f = (array) ($this->quote($policy)?->risk_facts ?? []);
        $body = array_filter([
            'codeinte' => ($v = $c->setting('intermediary.codeinte')) !== null ? (int) $v : null,
            'numeattestation' => ($v = data_get($f, 'activa.numeattestation') ?? $this->stickerNumber($policy)) !== null ? (int) preg_replace('/\D/', '', (string) $v) : null,
            'codtypdocument' => data_get($f, 'activa.codtypdocument') ?? $c->setting('attestation.codtypdocument'),
            'dateeffe' => $this->dt($policy->coverage_starts_at),
        ], fn ($v) => $v !== null && $v !== '');
        $this->require($body, ['codeinte', 'codtypdocument', 'dateeffe'], ActivaServices::SUBSCRIPTION, 'AttestationCMR');

        return $body;
    }

    /** One EncaissementCMR row for a payment we collected. */
    public function payment(PaymentIntentRecord $payment, Policy $policy, array $contract, CarrierApiConnection $c): array
    {
        $f = (array) ($this->quote($policy)?->risk_facts ?? []);
        $family = $this->family($policy);
        $codecate = data_get($f, 'activa.codecate') ?? $c->setting("categories.{$family}") ?? config("activa.categories.{$family}");
        $modes = (array) $c->setting('payment.modes', []);
        $row = [
            'idctr' => $contract['idctr'] ?? null,
            'policectr' => $contract['policy_number'] ?? null,
            'codecate' => $codecate !== null && $codecate !== '' ? (int) $codecate : null,
            'dateenca' => $this->dt($payment->reconciled_at ?? $payment->updated_at ?? now()),
            'datevali' => $this->dt($payment->reconciled_at ?? $payment->updated_at ?? now()),
            'dateffeq' => $this->dt($policy->coverage_starts_at),
            'modepaie' => $modes[strtolower((string) $payment->provider)] ?? $c->setting('payment.default_mode'),
            'refeenca' => $payment->provider_reference ?: $payment->id,
            'numeenca' => $payment->id,
            'primtota' => (int) $payment->amount_minor,
            'montenca' => (int) $payment->amount_minor,
            'codinteq' => ($v = $c->setting('intermediary.codeinte')) !== null ? (int) $v : null,
            'cree_par' => 'OPESINSURE', 'cree__le' => $this->dt(now()),
            'readytoInsert' => true,
        ];
        $row = $this->prune(array_replace($row, (array) $c->setting('payment.defaults', [])));
        $this->require($row, ['idctr', 'codecate', 'modepaie', 'montenca'], ActivaServices::SUBSCRIPTION, 'Encaissement');

        return $row;
    }

    // ---- Tarifiktor ------------------------------------------------------------------------------------------------

    /** Body of POST Tarification/tarifpolice (motor pricing) from the quote's risk facts. */
    public function pricing(Quote $quote, CarrierApiConnection $c): array
    {
        $f = (array) ($quote->risk_facts ?? []);
        $v = $this->vehicles($f)[0] ?? [];
        $start = isset($f['start_date']) ? Carbon::parse($f['start_date']) : now()->startOfDay();
        $months = (int) ($f['duration_months'] ?? 12);
        $codecate = data_get($f, 'activa.codecate') ?? $c->setting('categories.AUTO') ?? config('activa.categories.AUTO');
        $body = [
            'code_categorie' => $codecate !== null && $codecate !== '' ? (int) $codecate : null,
            'dateeffe' => $this->dt($start), 'dateeche' => $this->dt($start->copy()->addMonths($months)->subDay()), 'duree_Contrat' => $months,
            'marque_Vehicule' => $v['make'] ?? null, 'genre_Vehicule' => $v['activa_genre'] ?? null, 'usage_Vehicule' => $v['activa_usage'] ?? $v['usage'] ?? $v['usage_type'] ?? null,
            'carrosserie_Vehicule' => $v['body_type'] ?? null, 'zone_de_Circulation' => $v['activa_zone'] ?? $v['zone'] ?? null, 'type_Energie' => $v['fuel_type'] ?? $v['energy'] ?? null,
            'date_de_mise_en_circulation' => isset($v['first_registration_date']) ? $this->dt(Carbon::parse($v['first_registration_date'])) : null,
            'puissance_Fiscale_Carte_Grise' => isset($v['fiscal_power']) ? (int) $v['fiscal_power'] : null, 'nombre_de_places' => isset($v['seats']) ? (int) $v['seats'] : null,
            'cylindree' => isset($v['engine_capacity']) ? (int) $v['engine_capacity'] : null, 'charge_Utile_Vehicule' => isset($v['payload']) ? (int) $v['payload'] : null,
            'valeur_A_Neuf' => isset($v['new_value']) ? (int) $v['new_value'] : (isset($v['vehicle_value']) ? (int) $v['vehicle_value'] : null),
            'valeur_Venale' => isset($v['market_value']) ? (int) $v['market_value'] : (isset($v['vehicle_value']) ? (int) $v['vehicle_value'] : null),
            'coef_Bonus_Malus' => isset($f['bonus_malus']) ? (float) $f['bonus_malus'] : null,
            'formcouv' => data_get($f, 'activa.formcouv'),
            'garanties' => array_values((array) data_get($f, 'activa.garanties', [])),
        ];
        $body = $this->prune(array_replace_recursive($body, (array) data_get($f, 'activa.pricing', [])));
        $this->require($body, ['code_categorie', 'dateeffe', 'dateeche'], ActivaServices::PRICING, 'tarifpolice');

        return $body;
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    /** @return array{title: ?string, first_name: ?string, last_name: ?string, full_name: string, birth_date: ?string, email: ?string, phone: ?string, gender: ?string, id_number: ?string, id_type: ?string, address: ?string, passport_number: ?string} */
    public function person(?Party $party): array
    {
        $li = (array) ($party?->legal_identity ?? []);
        $pr = (array) ($party?->profile ?? []);
        $full = trim((string) ($party?->display_name ?? ''));
        $first = $li['first_name'] ?? $li['given_names'] ?? $pr['first_name'] ?? null;
        $last = $li['last_name'] ?? $li['surname'] ?? $li['family_name'] ?? $pr['last_name'] ?? null;
        if ($first === null && $last === null && $full !== '') {
            $parts = preg_split('/\s+/', $full) ?: [$full];
            $last = array_shift($parts);
            $first = $parts ? implode(' ', $parts) : null;
        }
        $gender = strtoupper((string) ($li['gender'] ?? $li['sex'] ?? $pr['gender'] ?? ''));
        $contacts = $party ? DB::table('party_contacts')->where('party_id', $party->id)->orderByDesc('is_primary')->get(['type', 'normalized_value']) : collect();
        $phone = $contacts->firstWhere('type', 'PHONE')?->normalized_value;
        $email = $contacts->firstWhere('type', 'EMAIL')?->normalized_value ?? ($pr['email'] ?? null);
        $dob = $li['date_of_birth'] ?? $li['birth_date'] ?? $pr['date_of_birth'] ?? null;

        return [
            'title' => match (true) { in_array($gender, ['M', 'MALE', 'H', 'HOMME'], true) => 'M', in_array($gender, ['F', 'FEMALE', 'FEMME'], true) => 'MME', default => null },
            'first_name' => $first, 'last_name' => $last, 'full_name' => $full,
            'birth_date' => $dob ? Carbon::parse($dob)->toDateString() : null,
            'email' => $email, 'phone' => $phone ? preg_replace('/^\+237/', '', (string) $phone) : null,
            'gender' => $gender !== '' ? substr($gender, 0, 1) : null,
            'id_number' => $li['id_number'] ?? $li['national_id'] ?? $li['registration_number'] ?? null,
            'id_type' => $li['id_type'] ?? null,
            'address' => $li['address'] ?? $pr['address'] ?? $li['city'] ?? null,
            'passport_number' => $li['passport_number'] ?? null,
        ];
    }

    /** Travellers declared on the quote (risk_facts.travellers / beneficiaries). */
    private function travellers(?Party $holderParty, array $t): array
    {
        $list = (array) ($t['travellers'] ?? $t['beneficiaries'] ?? (is_array($t['travelers'] ?? null) && array_is_list($t['travelers']) ? $t['travelers'] : []));
        $out = [];
        foreach ($list as $p) {
            if (! is_array($p)) {
                continue;
            }
            $gender = strtoupper((string) ($p['gender'] ?? $p['title'] ?? ''));
            $out[] = [
                'title' => $p['title'] ?? (str_starts_with($gender, 'F') ? 'MME' : (str_starts_with($gender, 'M') ? 'M' : null)),
                'first_name' => $p['first_name'] ?? null, 'last_name' => $p['last_name'] ?? null, 'email' => $p['email'] ?? null, 'phone' => $p['phone_number'] ?? $p['phone'] ?? null,
                'birth_date' => isset($p['birth_date']) ? Carbon::parse($p['birth_date'])->toDateString() : (isset($p['date_of_birth']) ? Carbon::parse($p['date_of_birth'])->toDateString() : null),
                'passport_number' => $p['passport_number'] ?? null, 'destination_country' => $p['destination_country'] ?? null,
                'residence_country' => $p['residence_country'] ?? null, 'nationality' => $p['nationality'] ?? null,
            ];
        }
        if ($out === [] && $holderParty !== null && ($t['holder_is_beneficiary'] ?? true)) {
            $h = $this->person($holderParty);
            $out[] = ['title' => $h['title'], 'first_name' => $h['first_name'], 'last_name' => $h['last_name'], 'email' => $h['email'], 'phone' => $h['phone'],
                'birth_date' => $h['birth_date'], 'passport_number' => $t['passport_number'] ?? $h['passport_number'], 'destination_country' => null, 'residence_country' => null, 'nationality' => $t['nationality'] ?? null];
        }

        return $out;
    }

    /** detailproduction rows (one per insured risk). */
    private function risks(Policy $policy, string $family, array $f, array $person): array
    {
        $premium = (int) ($policy->terms_snapshot['premium_minor'] ?? $policy->premium_minor ?? 0);
        $common = ['typemouv' => 'N', 'primnett' => $premium, 'montannu' => $premium];

        return match ($family) {
            'AUTO' => array_map(fn (array $v, int $i) => $this->prune($common + [
                'coderisq' => $i + 1,
                'numeimma' => $v['registration_number'] ?? $v['plate'] ?? null,
                'marqvehi' => $v['make'] ?? null, 'typevehi' => $v['model'] ?? null, 'numechas' => $v['vin'] ?? $v['chassis_number'] ?? null,
                'puisvehi' => isset($v['fiscal_power']) ? (int) $v['fiscal_power'] : null, 'nombplac' => isset($v['seats']) ? (int) $v['seats'] : null,
                'cylivehi' => isset($v['engine_capacity']) ? (int) $v['engine_capacity'] : null, 'poidvehi' => isset($v['payload']) ? (int) $v['payload'] : null,
                'valNeuf' => isset($v['new_value']) ? (int) $v['new_value'] : (isset($v['vehicle_value']) ? (int) $v['vehicle_value'] : null),
                'valVenal' => isset($v['market_value']) ? (int) $v['market_value'] : (isset($v['vehicle_value']) ? (int) $v['vehicle_value'] : null),
                'datemec' => isset($v['first_registration_date']) ? $this->dt(Carbon::parse($v['first_registration_date'])) : null,
                'codusaau' => $v['activa_usage'] ?? $v['usage_code'] ?? null, 'codgenau' => $v['activa_genre'] ?? null, 'carrvehi' => $v['body_type'] ?? null,
                'codezone' => $v['activa_zone'] ?? null, 'typemote' => $v['fuel_type'] ?? $v['energy'] ?? null,
            ]), $vs = $this->vehicles($f), array_keys($vs)),
            'MRH' => [$this->prune($common + ['coderisq' => 1, 'adrerisq' => $f['address'] ?? $f['risk_address'] ?? $person['address'],
                'capiassu' => isset($f['sum_insured']) ? (int) $f['sum_insured'] : (isset($f['sum_insured_minor']) ? (int) $f['sum_insured_minor'] : null)])],
            default => [$this->prune($common + ['coderisq' => 1, 'libelleRisq' => $person['full_name'], 'datenais' => $person['birth_date'] ? $person['birth_date'].'T00:00:00' : null,
                'sexerisq' => $person['gender'], 'capiassu' => isset($f['sum_insured']) ? (int) $f['sum_insured'] : null])],
        };
    }

    private function vehicles(array $f): array
    {
        $list = $f['vehicles'] ?? null;
        if (is_array($list) && array_is_list($list) && $list !== []) {
            return array_values(array_filter($list, 'is_array'));
        }

        return [(array) ($f['vehicle'] ?? $f)];
    }

    private function family_members(array $f): array
    {
        $out = [];
        foreach ((array) ($f['members'] ?? $f['insured_persons'] ?? []) as $i => $m) {
            if (is_array($m)) {
                $out[] = $this->prune(['codememb' => $i + 1, 'coderisq' => 1, 'lienpare' => $m['relationship'] ?? null,
                    'nomMemb' => trim(($m['last_name'] ?? '').' '.($m['first_name'] ?? '')) ?: ($m['name'] ?? null),
                    'datenais' => isset($m['birth_date']) ? Carbon::parse($m['birth_date'])->toDateString().'T00:00:00' : null, 'sexememb' => isset($m['gender']) ? strtoupper(substr((string) $m['gender'], 0, 1)) : null]);
            }
        }

        return $out;
    }

    private function stickerNumber(Policy $policy): ?string
    {
        return DB::table('sticker_stock')->where('assigned_policy_id', $policy->id)->value('serial_number');
    }

    private function dt(?CarbonInterface $d): ?string
    {
        return $d?->copy()->setTimezone('Africa/Douala')->format('Y-m-d\TH:i:s');
    }

    /** Drop nulls / empty strings (recursively) so Activa receives only values we actually hold. */
    private function prune(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = array_is_list($v) ? array_values(array_map(fn ($x) => is_array($x) ? $this->prune($x) : $x, $v)) : $this->prune($v);
            } elseif ($v === null || $v === '') {
                unset($a[$k]);
            }
        }

        return $a;
    }

    private function require(array $body, array $paths, string $service, string $operation): void
    {
        $missing = array_values(array_filter($paths, fn ($p) => Arr::get($body, $p) === null || Arr::get($body, $p) === ''));
        if ($missing !== []) {
            throw ActivaException::make(ActivaException::MAPPING_REQUIRED, $service, $operation, null, 'Missing: '.implode(', ', $missing).'.');
        }
    }
}
