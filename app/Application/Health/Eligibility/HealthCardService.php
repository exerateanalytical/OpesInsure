<?php

declare(strict_types=1);

namespace App\Application\Health\Eligibility;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Interfaces\Http\Errors\ApiProblemException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-HLT-001 — digital health card with a signed QR.
 *
 * Same approach as the public certificate verification (PublicVerificationService): the QR carries a random
 * token of which only the sha256 is stored, and a wrong/missing token gets the same generic answer as an
 * unknown card, so card numbers cannot be enumerated. On top, the QR payload is HMAC-signed with the app key
 * so a tampered payload is refused before any lookup. Reissue revokes the previous card.
 *
 * QR payload: "OIHC1.<base64url(json{c: card_number, v: card_version, t: token})>.<hmac-sha256 hex>".
 *
 * The provider-side scan answers with minimal disclosure: outcome + reason codes, member number, relationship,
 * masked name — never the date of birth, policy number, premium or contact data.
 */
final class HealthCardService
{
    public const PREFIX = 'OIHC1';

    public function __construct(
        private readonly HealthMemberService $members,
        private readonly EligibilityService $eligibility,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @return array{card_number: string, card_version: int, qr_payload: string, issued_at: string} */
    public function issue(string $tenantId, string $memberId, ?string $actorId): array
    {
        $m = $this->members->member($tenantId, $memberId);
        if ($m->status === 'ENDED') {
            throw new ApiProblemException('MEMBER_ENDED', 409, 'An ended member cannot get a card.');
        }
        $token = Str::random(40);

        $version = DB::transaction(function () use ($m, $token, $actorId, $tenantId) {
            DB::table('health_members')->where('id', $m->id)->lockForUpdate()->first();
            DB::table('health_member_cards')->where('health_member_id', $m->id)->where('status', 'ACTIVE')->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]);
            $version = (int) DB::table('health_member_cards')->where('health_member_id', $m->id)->max('card_version') + 1;
            DB::table('health_member_cards')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'health_member_id' => $m->id, 'card_version' => $version,
                'token_hash' => hash('sha256', $token), 'status' => 'ACTIVE', 'issued_at' => now(), 'issued_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('health_card.issued', 'health_member', $m->id, ['card_version' => $version]);
            $this->outbox->record('health_card.issued', 'health_member', $m->id, ['tenant_id' => $tenantId, 'member_id' => $m->id, 'card_version' => $version]);

            return $version;
        });

        return ['card_number' => $m->card_number, 'card_version' => $version, 'qr_payload' => self::sign(['c' => $m->card_number, 'v' => $version, 't' => $token]), 'issued_at' => now()->toIso8601String()];
    }

    public static function sign(array $claims): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return self::PREFIX.'.'.$body.'.'.hash_hmac('sha256', self::PREFIX.'.'.$body, self::key());
    }

    /** Provider-side scan: verify the QR, then run the eligibility check (channel SCAN). */
    public function scan(string $tenantId, string $qr, ?string $providerId, string $serviceCode, ?string $at, ?string $actorId): array
    {
        $card = $this->verify($tenantId, $qr);
        if (! $card) {
            DB::table('health_eligibility_checks')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'member_ref_hash' => hash('sha256', $qr), 'provider_profile_id' => $providerId,
                'service_code' => strtoupper($serviceCode), 'service_date' => now()->toDateString(), 'outcome' => 'CARD_NOT_VERIFIED',
                'reasons' => json_encode([['code' => 'CARD_NOT_VERIFIED', 'outcome' => 'NOT_ELIGIBLE', 'message' => 'Card not verified.']]),
                'channel' => 'SCAN', 'actor_id' => $actorId, 'checked_at' => now(),
            ]);

            throw new ApiProblemException('CARD_NOT_VERIFIED', 404, 'Card not verified.');
        }
        $r = $this->eligibility->check($tenantId, $card->card_number, $providerId, $serviceCode, $at, 'SCAN', $actorId);

        return [
            'check_id' => $r['check_id'], 'outcome' => $r['outcome'], 'eligible' => $r['eligible'], 'service_date' => $r['service_date'],
            'reasons' => array_map(fn ($x) => ['code' => $x['code'], 'outcome' => $x['outcome']], $r['reasons']),
            'member' => ['member_number' => $card->member_number, 'relationship' => $card->relationship, 'name' => self::mask($card->display_name)],
            'benefit_code' => $r['benefit_code'], 'waiting_period_ends_on' => $r['waiting_period_ends_on'],
        ];
    }

    /** Returns the member row for a genuine, ACTIVE card of this tenant; null otherwise (generic). */
    public function verify(string $tenantId, string $qr): ?object
    {
        $parts = explode('.', trim($qr));
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || ! hash_equals(hash_hmac('sha256', $parts[0].'.'.$parts[1], self::key()), $parts[2])) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! is_array($claims) || ! isset($claims['c'], $claims['v'], $claims['t'])) {
            return null;
        }

        $row = DB::table('health_member_cards as c')->join('health_members as m', 'm.id', '=', 'c.health_member_id')
            ->where('m.tenant_id', $tenantId)->where('m.card_number', (string) $claims['c'])->where('c.card_version', (int) $claims['v'])
            ->where('c.status', 'ACTIVE')->select('m.*', 'c.token_hash')->first();

        return $row && hash_equals($row->token_hash, hash('sha256', (string) $claims['t'])) ? $row : null;
    }

    private static function mask(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [$name];
        $first = array_shift($words);

        return trim($first.' '.implode(' ', array_map(fn ($w) => mb_substr($w, 0, 1).'.', $words)));
    }

    private static function key(): string
    {
        return 'health-card|'.(string) config('app.key');
    }
}
