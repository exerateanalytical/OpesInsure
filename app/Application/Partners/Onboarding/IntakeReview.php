<?php

declare(strict_types=1);

namespace App\Application\Partners\Onboarding;

use App\Application\Audit\AuditWriter;
use App\Application\Partners\BulkOnboarding\BrokerOnboardingService;
use App\Models\OrganisationClaim;
use App\Models\PartnerApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maker-checker review shared by partner applications and organisation claims (/admin, platform tenant,
 * tenant.manage + identity.invite — the same rule as bulk broker onboarding):
 *
 *   SUBMITTED ──startReview──> UNDER_REVIEW ──recommend (reviewer)──> decision by ANOTHER admin: APPROVED | REJECTED
 *        └───────requestInfo──> INFO_REQUESTED ──applicant responds──> SUBMITTED
 *
 * The reviewer (maker) can never take the final decision on the same record.
 */
final class IntakeReview
{
    public const OPEN = ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'];

    public function __construct(private readonly BrokerOnboardingService $platform, private readonly AuditWriter $audit, private readonly PublicIntake $intake) {}

    public function allows(?User $user): bool
    {
        return $this->platform->allows($user);
    }

    public function authorize(?User $user): User
    {
        abort_unless($user !== null && $this->allows($user), 403, __('security.platform_only'));

        return $user;
    }

    public function startReview(Model $record, User $reviewer): Model
    {
        $this->authorize($reviewer);
        $this->expect($record, ['SUBMITTED', 'DISPUTED']);
        $this->transition($record, 'UNDER_REVIEW', ['reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'recommendation' => null], 'review_started');

        return $record;
    }

    public function requestInfo(Model $record, User $reviewer, string $message): Model
    {
        $this->authorize($reviewer);
        $this->expect($record, ['SUBMITTED', 'UNDER_REVIEW']);
        $this->transition($record, 'INFO_REQUESTED', ['info_request' => $message, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'recommendation' => null], 'info_requested');
        $this->notify($record, 'info_requested', ['message' => $message]);

        return $record;
    }

    /** The reviewer's (maker's) proposal; the decision itself needs a second admin. */
    public function recommend(Model $record, User $reviewer, string $recommendation, ?string $note): Model
    {
        $this->authorize($reviewer);
        $this->expect($record, ['UNDER_REVIEW']);
        if (! in_array($recommendation, ['APPROVE', 'REJECT'], true)) {
            throw ValidationException::withMessages(['recommendation' => 'APPROVE or REJECT.']);
        }
        $record->forceFill(['reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'recommendation' => $recommendation, 'review_note' => $note])->save();
        $this->audit->record($this->prefix($record).'.recommended', $this->prefix($record), $record->getKey(), ['recommendation' => $recommendation]);

        return $record;
    }

    /** The checker must differ from the reviewer, and a recommendation must exist. */
    public function assertChecker(Model $record, User $checker): void
    {
        $this->authorize($checker);
        $this->expect($record, ['UNDER_REVIEW']);
        if ($record->recommendation === null) {
            throw ValidationException::withMessages(['recommendation' => __('partner_apply.errors.no_recommendation')]);
        }
        if ($record->reviewed_by === $checker->id) {
            throw ValidationException::withMessages(['decided_by' => __('partner_apply.errors.maker_checker')]);
        }
    }

    public function reject(Model $record, User $checker, string $reason): Model
    {
        // A dispute is closed by any authorised admin once looked at; everything else needs the second pair of eyes.
        if ($record instanceof OrganisationClaim && $record->is_dispute) {
            $this->authorize($checker);
            $this->expect($record, ['DISPUTED', 'UNDER_REVIEW']);
        } else {
            $this->assertChecker($record, $checker);
        }
        $this->transition($record, 'REJECTED', ['decided_by' => $checker->id, 'decided_at' => now(), 'decision_note' => $reason], 'rejected');
        $this->notify($record, 'rejected', ['message' => $reason]);

        return $record;
    }

    public function markApproved(Model $record, User $checker, array $result, ?string $note): void
    {
        $this->transition($record, 'APPROVED', ['decided_by' => $checker->id, 'decided_at' => now(), 'decision_note' => $note, 'result' => $result], 'approved', $result);
        $this->notify($record, 'approved', []);
    }

    /** Applicant answer to an information request (status link): back to the review queue. */
    public function respond(Model $record, string $response, array $documents): Model
    {
        $this->expect($record, ['INFO_REQUESTED']);
        $this->transition($record, 'SUBMITTED', [
            'applicant_response' => trim(((string) $record->applicant_response)."\n\n[".now()->toDateTimeString().'] '.$response),
            'documents' => [...($record->documents ?? []), ...$documents], 'recommendation' => null,
        ], 'info_provided');

        return $record;
    }

    public function transition(Model $record, string $to, array $fields, string $event, array $meta = []): void
    {
        $from = $record->status;
        DB::transaction(function () use ($record, $to, $fields, $event, $meta, $from) {
            $record->forceFill(['status' => $to] + $fields)->save();
            $this->audit->record($this->prefix($record).'.'.$event, $this->prefix($record), $record->getKey(), ['from' => $from, 'to' => $to] + $meta);
        });
    }

    /** Applicant email in the applicant's language (lang group partner_apply / org_claim). */
    public function notify(Model $record, string $event, array $params): void
    {
        $group = $record instanceof PartnerApplication ? 'partner_apply' : 'org_claim';
        $email = $record instanceof PartnerApplication ? $record->applicant_email : $record->claimant_email;
        $locale = in_array($record->locale, ['en', 'fr'], true) ? $record->locale : 'fr';
        $params += ['reference' => $record->reference, 'name' => $record instanceof PartnerApplication ? $record->legal_name : $record->institution_name];
        $this->intake->mail($email, __($group.'.mail.'.$event.'.subject', $params, $locale), __($group.'.mail.'.$event.'.body', $params, $locale));
    }

    private function expect(Model $record, array $statuses): void
    {
        if (! in_array($record->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => __('partner_apply.errors.wrong_status', ['status' => $record->status])]);
        }
    }

    private function prefix(Model $record): string
    {
        return $record instanceof PartnerApplication ? 'partner_application' : 'organisation_claim';
    }
}
