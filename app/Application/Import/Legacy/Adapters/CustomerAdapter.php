<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy\Adapters;

use App\Application\Customers\Matching\PartyMatcher;
use App\Application\Customers\PartyService;
use App\Application\Import\Legacy\LegacyAdapter;
use App\Application\Import\Legacy\LegacyContext;
use App\Models\Party;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-IMP-002 customers / parties. Golden record: a legacy customer is always created as its own party and then
 * scanned by PartyMatcher; probable duplicates become entity_match_candidates (+ a DATA_STEWARD case) and are only
 * ever merged by a human through PartyMergeService (maker-checker). The migration never merges and never
 * overwrites an existing party. A phone / e-mail already held by another party is not copied (the contact is
 * unique) and is reported as a golden-record hit instead.
 */
final class CustomerAdapter implements LegacyAdapter
{
    public const TYPES = ['INDIVIDUAL', 'ORGANIZATION'];

    public function __construct(private readonly PartyService $parties, private readonly PartyMatcher $matcher) {}

    public function key(): string
    {
        return 'legacy.customers';
    }

    public function label(): string
    {
        return 'Legacy customers / parties';
    }

    public function recordType(): string
    {
        return 'legacy.customer';
    }

    public function fields(): array
    {
        return ['legacy_id' => true, 'type' => true, 'display_name' => true, 'date_of_birth' => false, 'registration_number' => false,
            'phone' => false, 'email' => false, 'identifier_type' => false, 'identifier_value' => false];
    }

    public function validate(array $row, LegacyContext $ctx): array
    {
        $e = [];
        if (! in_array(strtoupper((string) $row['type']), self::TYPES, true)) {
            $e[] = ['rule' => 'CUSTOMER_TYPE', 'field' => 'type', 'message' => 'Type must be INDIVIDUAL or ORGANIZATION.'];
        }
        if (mb_strlen((string) $row['display_name']) > 255) {
            $e[] = ['rule' => 'NAME_LENGTH', 'field' => 'display_name', 'message' => 'Name is longer than 255 characters.'];
        }
        if ($row['date_of_birth'] !== null && (! ($d = LegacyContext::date($row['date_of_birth'])) || $d->isFuture())) {
            $e[] = ['rule' => 'DATE_OF_BIRTH', 'field' => 'date_of_birth', 'message' => 'Date of birth is not a valid past date.'];
        }
        if ($row['phone'] !== null && ! preg_match('/^\+[1-9]\d{7,14}$/', (string) $row['phone'])) {
            $e[] = ['rule' => 'PHONE_E164', 'field' => 'phone', 'message' => 'Phone must be in E.164 form (+237...).'];
        }
        if ($row['email'] !== null && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $e[] = ['rule' => 'EMAIL', 'field' => 'email', 'message' => 'E-mail is not valid.'];
        }
        if (($row['identifier_type'] === null) !== ($row['identifier_value'] === null)) {
            $e[] = ['rule' => 'IDENTIFIER_PAIR', 'field' => 'identifier_value', 'message' => 'Identifier type and value go together.'];
        }

        return $e;
    }

    public function sourceAmount(array $row): int
    {
        return 0;
    }

    public function migrate(array $row, LegacyContext $ctx): array
    {
        $type = strtoupper((string) $row['type']);
        $dob = LegacyContext::date($row['date_of_birth']);
        $hits = [];
        $phone = $row['phone'];
        if ($phone && DB::table('party_contacts')->where(['type' => 'PHONE', 'normalized_value' => $phone])->exists()) {
            $hits[] = 'PHONE';
            $phone = null;
        }
        $email = $row['email'] ? mb_strtolower((string) $row['email']) : null;
        if ($email && DB::table('party_contacts')->where(['type' => 'EMAIL', 'normalized_value' => $email])->exists()) {
            $hits[] = 'EMAIL';
            $email = null;
        }
        $party = $this->parties->create(['type' => $type, 'display_name' => $row['display_name'], 'date_of_birth' => $dob?->toDateString(),
            'registration_number' => $row['registration_number'], 'phone_e164' => $phone, 'email' => $email,
            'identifier_type' => $row['identifier_type'], 'identifier_value' => $row['identifier_value']]);
        DB::table('tenant_customers')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $ctx->tenantId, 'party_id' => $party->id,
            'customer_number' => mb_substr('LEG-'.$row['legacy_id'], 0, 64), 'status' => 'ACTIVE', 'private_metadata' => json_encode(['legacy_id' => $row['legacy_id'], 'source' => $ctx->source->name]),
            'created_at' => now(), 'updated_at' => now()]);
        $candidates = $this->matcher->scan($party, $ctx->tenantId, $ctx->actor);

        return ['id' => $party->id, 'notes' => array_filter([
            'contact_hits' => $hits,
            'match_candidates' => array_map(fn ($c) => ['candidate_id' => $c->id, 'score' => (float) $c->score, 'band' => $c->band], $candidates),
        ])];
    }

    public function measure(string $id): ?int
    {
        return Party::whereKey($id)->exists() ? 0 : null;
    }
}
