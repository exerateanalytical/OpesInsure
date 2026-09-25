<?php

declare(strict_types=1);

namespace App\Application\Regulatory\Rules;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent B1 — REQ-RPT-002 regulatory change engine.
 *
 * regulatory_rules: DRAFT → REVIEWED (reviewer ≠ author, impact analysis captured) → APPROVED (approver ≠ author and
 * ≠ reviewer, impact re-computed and must be acknowledged by hash) → EFFECTIVE (on/after effective_from; the previous
 * EFFECTIVE version of the same code becomes SUPERSEDED). Rule content is configured data; nothing is seeded.
 */
final class RegulatoryRuleService
{
    public const STATUSES = ['DRAFT', 'REVIEWED', 'APPROVED', 'EFFECTIVE', 'SUPERSEDED'];

    public function __construct(private readonly RegulatoryImpactAnalyzer $impact, private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function draft(array $d, User $actor): object
    {
        $jurisdiction = strtoupper($d['jurisdiction'] ?? 'CM');
        if (isset($d['reference_set_code']) && ! DB::table('regulatory_reference_sets')->where(['jurisdiction' => $jurisdiction, 'code' => $d['reference_set_code']])->exists()) {
            throw ValidationException::withMessages(['reference_set_code' => 'Unknown regulatory reference set.']);
        }
        $version = (int) (DB::table('regulatory_rules')->where(['jurisdiction' => $jurisdiction, 'code' => $d['code']])->max('version') ?? 0) + 1;
        $content = $d['content'] ?? [];
        $scope = $d['scope'] ?? [];
        if (isset($d['reference_set_code'])) {
            $scope['reference_set_code'] = $d['reference_set_code'];
        }
        $id = (string) Str::uuid();
        DB::table('regulatory_rules')->insert([
            'id' => $id, 'jurisdiction' => $jurisdiction, 'code' => $d['code'], 'version' => $version, 'title' => $d['title'], 'rule_type' => $d['rule_type'],
            'status' => 'DRAFT', 'legal_reference' => $d['legal_reference'] ?? null, 'reference_set_code' => $d['reference_set_code'] ?? null,
            'scope' => json_encode($scope, JSON_THROW_ON_ERROR), 'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'content_hash' => hash('sha256', json_encode([$scope, $content], JSON_THROW_ON_ERROR)),
            'effective_from' => $d['effective_from'], 'effective_until' => $d['effective_until'] ?? null, 'created_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('regulatory.rule.drafted', 'regulatory_rule', $id, ['code' => $d['code'], 'version' => $version]);

        return $this->find($id);
    }

    public function find(string $id): object
    {
        $r = DB::table('regulatory_rules')->where('id', $id)->first();
        abort_unless($r !== null, 404);
        foreach (['scope', 'content', 'impact'] as $k) {
            $r->{$k} = $r->{$k} === null ? null : json_decode($r->{$k}, true);
        }

        return $r;
    }

    public function analyse(object $rule): array
    {
        return $this->impact->analyse((array) $rule->scope, $rule->jurisdiction, $rule->code, $rule->id);
    }

    public function review(string $id, User $actor, ?string $notes = null): object
    {
        $r = $this->find($id);
        $this->guard($r, 'DRAFT', $actor, [$r->created_by]);
        $impact = $this->analyse($r);
        DB::table('regulatory_rules')->where('id', $id)->where('status', 'DRAFT')->update([
            'status' => 'REVIEWED', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now(),
            'impact' => json_encode($impact, JSON_THROW_ON_ERROR), 'impact_hash' => $this->hash($impact),
        ]);
        $this->audit->record('regulatory.rule.reviewed', 'regulatory_rule', $id, ['notes' => $notes, 'impact' => $impact['summary']]);
        $this->outbox->record('regulatory.rule.reviewed', 'regulatory_rule', $id, ['code' => $r->code, 'version' => $r->version, 'impact' => $impact['summary']]);

        return $this->find($id);
    }

    /** The approver confirms the impact they saw (impact_hash); if the impact moved since review, approval is refused. */
    public function approve(string $id, User $actor, string $impactHash): object
    {
        $r = $this->find($id);
        $this->guard($r, 'REVIEWED', $actor, [$r->created_by, $r->reviewed_by]);
        $impact = $this->analyse($r);
        $current = $this->hash($impact);
        if (! hash_equals($current, $impactHash)) {
            DB::table('regulatory_rules')->where('id', $id)->update(['impact' => json_encode($impact, JSON_THROW_ON_ERROR), 'impact_hash' => $current, 'updated_at' => now()]);
            throw ValidationException::withMessages(['impact_hash' => 'The impact analysis changed since it was reviewed; re-read the impact and confirm its current hash.']);
        }
        DB::table('regulatory_rules')->where('id', $id)->where('status', 'REVIEWED')->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
        $this->audit->record('regulatory.rule.approved', 'regulatory_rule', $id, ['impact_hash' => $current]);
        $this->outbox->record('regulatory.rule.approved', 'regulatory_rule', $id, ['code' => $r->code, 'version' => $r->version, 'effective_from' => $r->effective_from]);

        return $this->find($id);
    }

    /** APPROVED → EFFECTIVE once effective_from is reached; the previous EFFECTIVE version of the code is SUPERSEDED. */
    public function activate(string $id, User $actor, ?CarbonImmutable $asOf = null): object
    {
        $r = $this->find($id);
        $this->guard($r, 'APPROVED', $actor, []);
        $asOf ??= CarbonImmutable::now();
        if (CarbonImmutable::parse($r->effective_from)->startOfDay() > $asOf) {
            throw ValidationException::withMessages(['effective_from' => 'The rule is not effective yet.']);
        }
        DB::transaction(function () use ($r, $actor) {
            $prev = DB::table('regulatory_rules')->where(['jurisdiction' => $r->jurisdiction, 'code' => $r->code, 'status' => 'EFFECTIVE'])->lockForUpdate()->first();
            if ($prev !== null) {
                DB::table('regulatory_rules')->where('id', $prev->id)->update(['status' => 'SUPERSEDED', 'superseded_at' => now(),
                    'effective_until' => $prev->effective_until ?? CarbonImmutable::parse($r->effective_from)->subDay()->toDateString(), 'updated_at' => now()]);
                $this->outbox->record('regulatory.rule.superseded', 'regulatory_rule', $prev->id, ['code' => $r->code, 'version' => $prev->version, 'superseded_by' => $r->id]);
            }
            DB::table('regulatory_rules')->where('id', $r->id)->update(['status' => 'EFFECTIVE', 'effective_at' => now(), 'supersedes_id' => $prev?->id, 'updated_at' => now()]);
            $this->audit->record('regulatory.rule.effective', 'regulatory_rule', $r->id, ['supersedes_id' => $prev?->id, 'actor_id' => $actor->id]);
            $this->outbox->record('regulatory.rule.effective', 'regulatory_rule', $r->id, ['code' => $r->code, 'version' => $r->version, 'supersedes_id' => $prev?->id]);
        });

        return $this->find($id);
    }

    /** Impact of a regulatory reference set change: rules and report definitions that reference its code. */
    public function referenceSetImpact(string $setId): array
    {
        $set = DB::table('regulatory_reference_sets')->where('id', $setId)->first();
        abort_unless($set !== null, 404);

        return ['reference_set' => ['id' => $set->id, 'code' => $set->code, 'version' => $set->version, 'status' => $set->status]]
            + $this->impact->analyse(['reference_set_code' => $set->code], $set->jurisdiction);
    }

    private function guard(object $r, string $from, User $actor, array $forbidden): void
    {
        if ($r->status !== $from) {
            throw ValidationException::withMessages(['status' => "Rule must be {$from} (is {$r->status})."]);
        }
        if (in_array($actor->id, array_filter($forbidden), true)) {
            throw ValidationException::withMessages(['status' => __('wave9.maker_checker')]);
        }
    }

    private function hash(array $impact): string
    {
        return hash('sha256', json_encode($impact, JSON_THROW_ON_ERROR));
    }
}
