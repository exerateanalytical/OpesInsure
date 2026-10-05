<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use App\Application\Documents\Scanning\DocumentScanQueue;
use App\Application\Partners\Onboarding\IntakeReview;
use App\Application\Partners\Onboarding\PublicIntake;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * Review actions shared by the partner-application and organisation-claim admin screens (maker-checker through
 * IntakeReview). Only CLEAN documents can be downloaded; held or infected files are listed but never served.
 */
final class IntakeReviewActions
{
    private static function tr(string $key, array $r = []): string
    {
        return __('partner_apply.admin.'.$key, $r);
    }

    private static function review(): IntakeReview
    {
        return app(IntakeReview::class);
    }

    private static function done(mixed $result): void
    {
        if ($result !== null) {
            Notification::make()->success()->title(self::tr('notify.done'))->send();
        }
    }

    /** "Decider" = an authorised admin who is not the reviewer, on a record with a recommendation. */
    public static function canDecide(Model $r): bool
    {
        return $r->status === 'UNDER_REVIEW' && $r->recommendation !== null && auth()->id() !== null && $r->reviewed_by !== auth()->id();
    }

    /** @param callable(Model): array<string, ?string> $details label => value */
    public static function common(callable $details): array
    {
        return [
            Actions\Action::make('details')->label(self::tr('actions.view'))->icon('lucide-eye')->color('gray')
                ->modalSubmitAction(false)->modalWidth('4xl')->modalContent(fn (Model $r) => self::detailsHtml($r, $details($r))),
            Actions\Action::make('download')->label(self::tr('actions.download'))->icon('lucide-download')->color('gray')
                ->visible(fn (Model $r) => ! empty($r->documents))
                ->schema([Forms\Components\Select::make('document_id')->label(self::tr('fields.document'))->required()
                    ->options(fn (Model $r) => self::cleanDocuments($r))])
                ->action(function (Model $r, array $data) {
                    abort_unless(array_key_exists($data['document_id'], self::cleanDocuments($r)), 403);
                    $doc = DB::table('documents')->where('id', $data['document_id'])->first(['storage_key', 'mime_type']);
                    app(\App\Application\Audit\AuditWriter::class)->record('document.downloaded', 'document', $data['document_id'], ['context' => $r->getTable(), 'record' => $r->getKey()]);

                    return Storage::disk('local')->download((string) $doc->storage_key);
                }),
            Actions\Action::make('startReview')->label(self::tr('actions.start_review'))->icon('lucide-search-check')->color('gray')
                ->visible(fn (Model $r) => in_array($r->status, ['SUBMITTED', 'DISPUTED'], true))
                ->action(fn (Model $r) => self::done(ServiceValidation::run(fn () => self::review()->startReview($r, auth()->user())))),
            Actions\Action::make('requestInfo')->label(self::tr('actions.request_info'))->icon('lucide-message-circle-question')->color('warning')
                ->visible(fn (Model $r) => in_array($r->status, ['SUBMITTED', 'UNDER_REVIEW'], true) && ! ($r->is_dispute ?? false))
                ->schema([Forms\Components\Textarea::make('message')->label(self::tr('fields.message'))->required()->minLength(5)->maxLength(2000)])
                ->action(fn (Model $r, array $data) => self::done(ServiceValidation::run(fn () => self::review()->requestInfo($r, auth()->user(), $data['message'])))),
            Actions\Action::make('recommend')->label(self::tr('actions.recommend'))->icon('lucide-clipboard-check')->color('primary')
                ->visible(fn (Model $r) => $r->status === 'UNDER_REVIEW' && ! ($r->is_dispute ?? false))
                ->schema([
                    Forms\Components\Select::make('recommendation')->label(self::tr('fields.recommendation'))->required()->options(self::tr('recommendations')),
                    Forms\Components\Textarea::make('note')->label(self::tr('fields.note'))->maxLength(2000),
                ])
                ->action(fn (Model $r, array $data) => self::done(ServiceValidation::run(fn () => self::review()->recommend($r, auth()->user(), $data['recommendation'], $data['note'] ?? null)))),
            Actions\Action::make('reject')->label(self::tr('actions.reject'))->icon('lucide-x')->color('danger')
                ->visible(fn (Model $r) => self::canDecide($r) || (($r->is_dispute ?? false) && in_array($r->status, ['DISPUTED', 'UNDER_REVIEW'], true)))
                ->schema([Forms\Components\Textarea::make('reason')->label(self::tr('fields.reason'))->required()->minLength(3)->maxLength(2000)])
                ->action(fn (Model $r, array $data) => self::done(ServiceValidation::run(fn () => self::review()->reject($r, auth()->user(), $data['reason'])))),
        ];
    }

    /** @return array<string, string> CLEAN documents only */
    public static function cleanDocuments(Model $r): array
    {
        $statuses = app(PublicIntake::class)->scanStatuses($r->documents ?? []);

        return collect($r->documents ?? [])->filter(fn ($d) => ($statuses[$d['document_id']] ?? null) === DocumentScanQueue::CLEAN)
            ->mapWithKeys(fn ($d) => [$d['document_id'] => $d['kind'].' — '.($d['name'] ?? '')])->all();
    }

    private static function detailsHtml(Model $r, array $details): HtmlString
    {
        $statuses = app(PublicIntake::class)->scanStatuses($r->documents ?? []);
        $docs = collect($r->documents ?? [])->map(fn ($d) => e($d['kind'].' — '.($d['name'] ?? '').' ['.($statuses[$d['document_id']] ?? '?').']'))->implode('<br>');
        $rows = collect($details + [self::tr('details.documents') => new HtmlString($docs ?: '—')])
            ->map(fn ($v, $k) => '<tr style="border-top:1px solid rgba(127,127,127,.25)"><th style="text-align:left;padding:6px 8px;vertical-align:top;width:30%">'.e($k)
                .'</th><td style="padding:6px 8px;white-space:pre-line">'.($v instanceof HtmlString ? $v->toHtml() : e((string) ($v ?? '—'))).'</td></tr>')->implode('');

        return new HtmlString('<table style="width:100%;font-size:13px;border-collapse:collapse">'.$rows.'</table>');
    }
}
