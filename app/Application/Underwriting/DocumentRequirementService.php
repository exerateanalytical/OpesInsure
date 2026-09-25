<?php

declare(strict_types=1);

namespace App\Application\Underwriting;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Rules\RuleEngine;
use App\Models\DocumentRequirementVersion;
use App\Models\InsuranceProduct;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DocumentRequirementService
{
    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox, private RuleEngine $rules) {}

    public function create(array $d, User $actor): DocumentRequirementVersion
    {
        return DB::transaction(function () use ($d, $actor) {
            $v = (DocumentRequirementVersion::where(['line_code' => $d['line_code'], 'code' => $d['code']])->lockForUpdate()->pluck('version')->max() ?? 0) + 1;
            $r = DocumentRequirementVersion::create([...$d, 'version' => $v, 'status' => 'DRAFT', 'created_by' => $actor->id]);
            $this->audit->record('document.requirement.created', 'document_requirement', $r->id, ['version' => $v]);

            return $r;
        });
    }

    public function approve(DocumentRequirementVersion $r, User $actor): DocumentRequirementVersion
    {
        if ($r->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => __('wave3.requirement_not_draft')]);
        }
        if ($r->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave3.maker_checker')]);
        }

        return DB::transaction(function () use ($r, $actor) {
            DocumentRequirementVersion::where(['line_code' => $r->line_code, 'code' => $r->code, 'status' => 'APPROVED'])->update(['status' => 'RETIRED']);
            $r->update(['status' => 'APPROVED', 'approved_by' => $actor->id]);
            $this->audit->record('document.requirement.approved', 'document_requirement', $r->id, ['version' => $r->version]);
            $this->outbox->record('document.requirement.approved', 'document_requirement', $r->id, ['requirement_id' => $r->id]);

            return $r->refresh();
        });
    }

    /**
     * Requirements in force for a risk. Each requirement's own `rules.conditions` ({fact, equals}, AND) decide first
     * (legacy behaviour); DOCUMENTS-domain rule sets from the rules engine then REQUIRE extra requirement codes or
     * WAIVE them (waive wins). With no DOCUMENTS rule set in force the result is exactly the legacy one.
     *
     * @return Collection<int, DocumentRequirementVersion>
     */
    public function applicable(string $line, array $facts, ?InsuranceProduct $product = null, ?\DateTimeInterface $at = null): Collection
    {
        $day = ($at ? \Carbon\CarbonImmutable::instance($at) : now())->toDateString();
        $inForce = DocumentRequirementVersion::where(['line_code' => $line, 'status' => 'APPROVED'])->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))->get();

        $docs = $this->rules->documents($line, $product, $facts, $at);
        if (! $docs['applies']) {
            return $inForce->filter(fn (DocumentRequirementVersion $r) => self::conditionsMet($r, $facts))->values();
        }

        return $inForce->filter(fn (DocumentRequirementVersion $r) => ! in_array($r->code, $docs['waive'], true)
            && (in_array($r->code, $docs['require'], true) || self::conditionsMet($r, $facts)))->values();
    }

    private static function conditionsMet(DocumentRequirementVersion $r, array $facts): bool
    {
        foreach ($r->rules['conditions'] ?? [] as $c) {
            if (data_get($facts, $c['fact']) !== ($c['equals'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
