<?php

declare(strict_types=1);

namespace App\Application\Catalogue\Governance;

use App\Application\Approvals\ApprovalService;
use App\Application\Audit\AuditWriter;
use App\Application\Catalogue\CatalogueService;
use App\Application\Catalogue\Governance\Models\ProductGovernance;
use App\Application\Catalogue\ProductModelService;
use App\Application\Catalogue\ProductVersionSnapshot;
use App\Application\Events\OutboxWriter;
use App\Models\ApprovalRequest;
use App\Models\InsuranceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRD-007 — PRE §74 product governance workflow as a review SUB-STATUS on top of the PRE §8
 * version lifecycle (TRACEABILITY §3: "Version status = PRE §8; review steps = governance sub-status").
 * The version lifecycle is still moved only by CatalogueService / ProductModelService:
 *
 *   stage               version status   how it is entered
 *   DRAFT               DRAFT            new version
 *   CONFIGURATION       DRAFT            maker starts configuring
 *   TECHNICAL_REVIEW    REVIEW           CatalogueService::submit (requires zero completeness blockers)
 *   COMPLIANCE_REVIEW   REVIEW           checker (≠ maker)
 *   BUSINESS_APPROVAL   REVIEW           checker (≠ maker, ≠ technical reviewer); opens the product.publish approval request
 *   READY               APPROVED         ApprovalService decision → ProductModelService::approve (snapshot frozen)
 *   PUBLISHED           PUBLISHED        CatalogueService::publish (now, or scheduled: future-dated publication)
 *   REJECTED            REJECTED         any review stage → ProductModelService::reject
 */
final class ProductGovernanceService
{
    public const STAGES = ['DRAFT', 'CONFIGURATION', 'TECHNICAL_REVIEW', 'COMPLIANCE_REVIEW', 'BUSINESS_APPROVAL', 'READY', 'PUBLISHED', 'REJECTED'];

    public const NEXT = ['DRAFT' => 'CONFIGURATION', 'CONFIGURATION' => 'TECHNICAL_REVIEW', 'TECHNICAL_REVIEW' => 'COMPLIANCE_REVIEW',
        'COMPLIANCE_REVIEW' => 'BUSINESS_APPROVAL', 'BUSINESS_APPROVAL' => 'READY', 'READY' => 'PUBLISHED'];

    public const REVIEW_STAGES = ['TECHNICAL_REVIEW', 'COMPLIANCE_REVIEW', 'BUSINESS_APPROVAL'];

    /** Stage a version is in when it has no governance row yet (legacy / direct API lifecycle). */
    private const DERIVED = ['DRAFT' => 'DRAFT', 'IN_REVIEW' => 'TECHNICAL_REVIEW', 'APPROVED' => 'READY', 'ACTIVE' => 'PUBLISHED',
        'SUSPENDED' => 'PUBLISHED', 'RETIRED' => 'PUBLISHED', 'REJECTED' => 'REJECTED'];

    public function __construct(
        private readonly CatalogueService $catalogue,
        private readonly ProductModelService $versions,
        private readonly ProductCompletenessService $completeness,
        private readonly ApprovalService $approvals,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** Current governance row (unsaved and derived from the version status when none exists yet). */
    public function state(InsuranceProduct $v): ProductGovernance
    {
        return ProductGovernance::find($v->id)
            ?? new ProductGovernance(['insurance_product_id' => $v->id, 'stage' => self::DERIVED[$v->status] ?? 'DRAFT', 'target_market' => [], 'prohibited_market' => []]);
    }

    /** Governance attributes (owner, target / prohibited market, review date) — not product configuration, editable in any status. */
    public function updateAttributes(InsuranceProduct $v, array $data, User $actor): ProductGovernance
    {
        $g = $this->row($v);
        $data = array_intersect_key($data, array_flip(['owner_user_id', 'target_market', 'prohibited_market', 'next_review_date']));
        $old = $g->only(array_keys($data));
        $g->update($data);
        $this->audit->recordChange('catalogue.governance.attributes_updated', 'insurance_product', $v->id, $old, $g->only(array_keys($data)), 'Governance attributes updated');

        return $g->refresh();
    }

    /** Moves the version one governance stage forward (see class doc). */
    public function advance(InsuranceProduct $v, User $actor, string $notes = ''): ProductGovernance
    {
        return DB::transaction(fn () => $this->advanceNow($v, $actor, $notes));
    }

    private function advanceNow(InsuranceProduct $v, User $actor, string $notes = ''): ProductGovernance
    {
        $g = $this->row($v);
        $from = $g->stage;
        $to = self::NEXT[$from] ?? throw ValidationException::withMessages(['stage' => "No governance step follows {$from}."]);
        if ($to === 'PUBLISHED') {
            return $this->publish($v, $actor, $notes);
        }
        $this->assertStageMatchesStatus($v, $g);
        $approvalId = null;

        switch ($to) {
            case 'CONFIGURATION':
                break;
            case 'TECHNICAL_REVIEW':
                $blockers = $this->completeness->blockers($v);
                if ($blockers !== []) {
                    throw ValidationException::withMessages(['completeness' => $blockers]);
                }
                $this->catalogue->submit($v, $actor, $notes ?: 'Submitted for technical review');
                break;
            case 'COMPLIANCE_REVIEW':
            case 'BUSINESS_APPROVAL':
                $this->assertChecker($v, $actor, $to === 'BUSINESS_APPROVAL' ? 'TECHNICAL_REVIEW' : null);
                if ($to === 'BUSINESS_APPROVAL') {
                    $approvalId = $this->approvalFor($v)->id;
                }
                break;
            case 'READY':
                $req = $this->approvalFor($v);
                if (! $this->approvals->isApproved($req)) {
                    $req = $this->approvals->recordDecision($req, $actor, 'APPROVED', $notes ?: null);
                }
                $approvalId = $req->id;
                if (! $this->approvals->isApproved($req)) {
                    $this->event($v, $from, $from, 'LEVEL_APPROVED', $notes, $actor, $req->id);

                    return $g->refresh(); // multi-level matrix: waits for the next checker
                }
                $this->versions->approve($v->refresh(), $actor, $notes ?: 'Business approval');
                break;
        }

        return $this->moveStage($v, $g, $to, 'ADVANCED', $notes, $actor, $approvalId);
    }

    /** Any review stage → REJECTED (terminal; rework on a new draft version). */
    public function reject(InsuranceProduct $v, User $actor, string $reason): ProductGovernance
    {
        return DB::transaction(fn () => $this->rejectNow($v, $actor, $reason));
    }

    private function rejectNow(InsuranceProduct $v, User $actor, string $reason): ProductGovernance
    {
        $g = $this->row($v);
        if (! in_array($g->stage, [...self::REVIEW_STAGES, 'READY'], true)) {
            throw ValidationException::withMessages(['stage' => "A version in {$g->stage} cannot be rejected."]);
        }
        $req = ApprovalRequest::where('source_table', 'insurance_products')->where('source_id', $v->id)->where('action_code', 'product.publish')->first();
        if ($req && $req->status === 'PENDING') {
            $this->approvals->recordDecision($req, $actor, 'REJECTED', $reason);
        }
        $this->versions->reject($v, $actor, $reason);

        return $this->moveStage($v, $g, 'REJECTED', 'REJECTED', $reason, $actor, $req?->id);
    }

    /**
     * READY → PUBLISHED through CatalogueService::publish (all existing gates) after re-checking the
     * completeness blockers. A future $at schedules the publication (future-dated publication, PRE §79);
     * publishDue() executes it.
     */
    public function publish(InsuranceProduct $v, User $actor, string $reason = '', ?string $at = null): ProductGovernance
    {
        return DB::transaction(fn () => $this->publishNow($v, $actor, $reason, $at));
    }

    private function publishNow(InsuranceProduct $v, User $actor, string $reason = '', ?string $at = null): ProductGovernance
    {
        $g = $this->row($v);
        if ($g->stage !== 'READY') {
            throw ValidationException::withMessages(['stage' => "Only a READY version can be published (current stage {$g->stage})."]);
        }
        $blockers = $this->completeness->blockers($v);
        if ($blockers !== []) {
            throw ValidationException::withMessages(['completeness' => $blockers]);
        }
        if ($at !== null && now()->lt($when = \Carbon\CarbonImmutable::parse($at))) {
            if ($v->created_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('wave2.maker_checker')]);
            }
            $g->update(['scheduled_publish_at' => $when, 'scheduled_by' => $actor->id]);
            $this->event($v, 'READY', 'READY', 'SCHEDULED', trim($reason.' (publish at '.$when->toIso8601String().')'), $actor, null);
            $this->audit->record('catalogue.governance.publication_scheduled', 'insurance_product', $v->id, ['at' => $when->toIso8601String()], $reason ?: null);

            return $g->refresh();
        }
        $this->catalogue->publish($v, $actor, $reason ?: 'Published after governance review');
        $g->update(['scheduled_publish_at' => null]);

        return $this->moveStage($v, $g, 'PUBLISHED', 'PUBLISHED', $reason, $actor, $g->approval_request_id);
    }

    /** Publishes every READY version whose scheduled time has come (scheduler / command). @return list<string> published ids */
    public function publishDue(): array
    {
        $done = [];
        foreach (ProductGovernance::where('stage', 'READY')->whereNotNull('scheduled_publish_at')->where('scheduled_publish_at', '<=', now())->get() as $g) {
            $v = InsuranceProduct::find($g->insurance_product_id);
            $actor = User::find($g->scheduled_by);
            if (! $v || ! $actor) {
                continue;
            }
            try {
                $this->publish($v, $actor, 'Scheduled publication');
                $done[] = $v->id;
            } catch (ValidationException $e) {
                $this->audit->record('catalogue.governance.scheduled_publication_failed', 'insurance_product', $v->id, ['errors' => $e->errors()]);
            }
        }

        return $done;
    }

    /** Configuration diff against the base version (PRE §78), from the snapshot builder. */
    public function diff(InsuranceProduct $v, ?InsuranceProduct $against = null): array
    {
        $against ??= $v->base_version_id ? InsuranceProduct::find($v->base_version_id) : null;
        $snap = app(ProductVersionSnapshot::class);
        $a = $against ? self::flatten($against->snapshot ?? $snap->build($against)) : [];
        $b = self::flatten($v->snapshot ?? $snap->build($v));
        $ignore = fn (string $k) => preg_match('/(^|\.)(id|version|version_id|insurance_product_id|effective_from|built_at|snapshot_hash)$/', $k) === 1;
        $changes = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $k) {
            if ($ignore($k) || (($a[$k] ?? null) === ($b[$k] ?? null))) {
                continue;
            }
            $changes[] = ['path' => $k, 'change' => ! array_key_exists($k, $a) ? 'ADDED' : (! array_key_exists($k, $b) ? 'REMOVED' : 'CHANGED'), 'from' => $a[$k] ?? null, 'to' => $b[$k] ?? null];
        }
        usort($changes, fn ($x, $y) => strcmp($x['path'], $y['path']));

        return ['version_id' => $v->id, 'against_version_id' => $against?->id, 'changes' => $changes];
    }

    /** @return list<array> stage history */
    public function history(InsuranceProduct $v): array
    {
        return DB::table('product_governance_events')->where('insurance_product_id', $v->id)->orderBy('seq')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function row(InsuranceProduct $v): ProductGovernance
    {
        $g = ProductGovernance::find($v->id);
        if ($g) {
            return $g;
        }
        $g = $this->state($v);
        $g->save();

        return $g->refresh();
    }

    private function moveStage(InsuranceProduct $v, ProductGovernance $g, string $to, string $decision, string $notes, User $actor, ?string $approvalId): ProductGovernance
    {
        $from = $g->stage;
        $g->update(['stage' => $to] + ($approvalId ? ['approval_request_id' => $approvalId] : []));
        $this->event($v, $from, $to, $decision, $notes, $actor, $approvalId);
        $this->audit->record('catalogue.governance.'.strtolower($decision), 'insurance_product', $v->id, ['from' => $from, 'to' => $to], $notes ?: null);
        $this->outbox->record('catalogue.product_version.governance_stage_changed', 'insurance_product', $v->id, ['product_id' => $v->id, 'from' => $from, 'to' => $to]);

        return $g->refresh();
    }

    private function event(InsuranceProduct $v, ?string $from, string $to, string $decision, string $notes, User $actor, ?string $approvalId): void
    {
        DB::table('product_governance_events')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $v->id, 'from_stage' => $from, 'to_stage' => $to,
            'decision' => $decision, 'notes' => $notes ?: null, 'actor_id' => $actor->id, 'approval_request_id' => $approvalId, 'occurred_at' => now()]);
    }

    /** Reviewer ≠ maker; business approval also ≠ the technical reviewer (four eyes across the review chain). */
    private function assertChecker(InsuranceProduct $v, User $actor, ?string $distinctFromStage): void
    {
        if ($v->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave2.maker_checker')]);
        }
        if ($distinctFromStage !== null) {
            $previous = DB::table('product_governance_events')->where('insurance_product_id', $v->id)->where('from_stage', $distinctFromStage)
                ->where('decision', 'ADVANCED')->orderByDesc('seq')->value('actor_id');
            if ($previous === $actor->id) {
                throw ValidationException::withMessages(['actor' => 'Segregation of duties: the technical reviewer cannot also complete the compliance review.']);
            }
        }
    }

    private function approvalFor(InsuranceProduct $v): ApprovalRequest
    {
        return $this->approvals->forSource('insurance_products', $v->id, fn () => [$v->created_by ?? throw ValidationException::withMessages(['maker' => 'Version has no maker.']), [
            'action_code' => 'product.publish', 'subject_type' => 'insurance_product', 'subject_id' => $v->id, 'product_id' => $v->id,
            'reason' => 'Business approval of product version '.$v->code.' v'.$v->version, 'excluded_user_ids' => [$v->created_by],
        ]]);
    }

    private function assertStageMatchesStatus(InsuranceProduct $v, ProductGovernance $g): void
    {
        $expected = ['DRAFT' => ['DRAFT'], 'CONFIGURATION' => ['DRAFT'], 'TECHNICAL_REVIEW' => ['IN_REVIEW'], 'COMPLIANCE_REVIEW' => ['IN_REVIEW'],
            'BUSINESS_APPROVAL' => ['IN_REVIEW'], 'READY' => ['APPROVED']][$g->stage] ?? [];
        if (! in_array($v->status, $expected, true)) {
            throw ValidationException::withMessages(['stage' => "Governance stage {$g->stage} does not match version status {$v->status}; the version was moved outside the governance workflow."]);
        }
    }

    /** @return array<string,mixed> */
    private static function flatten(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $val) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($val) && $val !== []) {
                $out += self::flatten($val, $key);
            } else {
                $out[$key] = $val;
            }
        }

        return $out;
    }
}
