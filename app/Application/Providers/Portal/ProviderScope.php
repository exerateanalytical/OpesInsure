<?php

declare(strict_types=1);

namespace App\Application\Providers\Portal;

use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * REQ-PRV-003 — provider portal scoping (reusable by any provider-facing feature).
 *
 * A user acts for a provider when the user's party IS the provider's party, or the user's party has an ACTIVE,
 * in-date EMPLOYED_BY relationship to it (the Batch 11 C9 rule in ExpertAssignmentService::providerIdsFor, without
 * the ADJUSTER/EXPERT category filter). TERMINATED providers are never in scope.
 *
 * As middleware it rejects users who act for no provider (403 NOT_PROVIDER_USER) and resolves the active provider:
 * the X-Provider-ID header or ?provider_id= when the user acts for several (must be one of theirs — otherwise 404,
 * existence is not leaked), else the single provider. The result is stored on the request (attribute
 * `provider_scope`) and read back with ProviderScope::of($request).
 */
final class ProviderScope
{
    public const ATTRIBUTE = 'provider_scope';

    /** @param list<string> $providerIds */
    public function __construct(
        public readonly ?string $providerId = null,
        public readonly array $providerIds = [],
        public readonly ?string $partyId = null,
        public readonly ?string $partnerId = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $ids = $user instanceof User ? self::providerIdsFor($user) : [];
        if ($ids === []) {
            throw new ApiProblemException('NOT_PROVIDER_USER', 403, 'This account does not act for any provider.');
        }
        $wanted = $request->header('X-Provider-ID') ?: $request->query('provider_id');
        if ($wanted !== null && $wanted !== '') {
            if (! in_array($wanted, $ids, true)) {
                throw new ApiProblemException('PROVIDER_NOT_FOUND', 404, 'Provider not found.');
            }
            $active = (string) $wanted;
        } elseif (count($ids) === 1) {
            $active = $ids[0];
        } else {
            throw new ApiProblemException('PROVIDER_SELECTION_REQUIRED', 409, 'This account acts for several providers: send X-Provider-ID.', [], ['provider_ids' => $ids]);
        }
        $p = DB::table('provider_profiles')->where('id', $active)->first(['party_id', 'partner_id']);
        $request->attributes->set(self::ATTRIBUTE, new self($active, $ids, $p->party_id, $p->partner_id));

        return $next($request);
    }

    public static function of(Request $request): self
    {
        $s = $request->attributes->get(self::ATTRIBUTE);
        if (! $s instanceof self) {
            throw new ApiProblemException('NOT_PROVIDER_USER', 403, 'This account does not act for any provider.');
        }

        return $s;
    }

    /** Provider profiles (any category, not TERMINATED) the user acts for. @return list<string> */
    public static function providerIdsFor(User $user): array
    {
        if (! $user->party_id) {
            return [];
        }
        $employers = DB::table('party_relationships')->where('from_party_id', $user->party_id)->where('type', 'EMPLOYED_BY')->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString()))->pluck('to_party_id')->all();

        return DB::table('provider_profiles')->whereIn('party_id', array_merge([$user->party_id], $employers))
            ->where('credentialing_status', '<>', 'TERMINATED')->orderBy('id')->pluck('id')->map(fn ($v) => (string) $v)->all();
    }

    public static function actsFor(User $user, string $providerId): bool
    {
        return in_array($providerId, self::providerIdsFor($user), true);
    }
}
