<?php

declare(strict_types=1);

namespace App\Filament\Shared\Components;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * REQ-UI-002 shared detail infolist for configuration / reference-data
 * resources. Built from the record itself so every resource gets the same
 * sections without per-resource copy-paste:
 *
 *   Details     every business attribute (JSON rendered readably)
 *   Status      status / lifecycle badges incl. data statuses
 *               (VERIFIED, PLATFORM_NORMALIZED, UNVERIFIED, PENDING_SOURCE,
 *               CONFIG_REQUIRED, DEMO_ONLY, RETIRED) and provenance/source
 *   Related     belongs-to parents and has-many children (first rows)
 *   Audit       who/when metadata + the audit_log timeline (RecordShell)
 *
 * Secrets are never rendered: model $hidden attributes are skipped and any
 * attribute whose name looks like a credential is masked.
 */
final class RecordInfolist
{
    private const SECRET = '/(secret|password|token|credential|api_?key|private|security_code|pin_hash|webhook_key|signing_key)/i';

    private const STATUS = '/(^|_)(status|state|data_status|verification|lifecycle|stage|decision|outcome)$/i';

    private const PROVENANCE = '/(provenance|source|evidence|reference|citation|origin|confidence|verified|imported|seed)/i';

    private const AUDIT = '/^(created_at|updated_at|deleted_at|approved_at|reviewed_at|submitted_at|verified_at|rejected_at|retired_at|revoked_at|admin_modified_at|decided_at|created_by|updated_by|approved_by|reviewed_by|submitted_by|verified_by|decided_by|rejected_by|actor_id|requested_by|maker_id|checker_id)$/';

    /** Canonical data statuses and their tone. */
    public const DATA_STATUS_COLORS = [
        'VERIFIED' => 'success',
        'PLATFORM_NORMALIZED' => 'info',
        'UNVERIFIED' => 'warning',
        'PENDING_SOURCE' => 'warning',
        'CONFIG_REQUIRED' => 'danger',
        'DEMO_ONLY' => 'gray',
        'RETIRED' => 'gray',
    ];

    /** @return array<int, Component> */
    public static function for(Model $record, ?string $subjectType = null): array
    {
        $details = $status = $provenance = $audit = [];

        foreach (array_keys($record->getAttributes()) as $key) {
            if (in_array($key, $record->getHidden(), true) || $key === $record->getKeyName()) {
                continue;
            }
            if (preg_match(self::AUDIT, $key)) {
                $audit[] = self::entry($record, $key);
            } elseif (preg_match(self::STATUS, $key) || $key === 'active' || $key === 'is_active') {
                $status[] = self::entry($record, $key);
            } elseif (preg_match(self::PROVENANCE, $key) && ! preg_match(self::SECRET, $key)) {
                $provenance[] = self::entry($record, $key);
            } else {
                $details[] = self::entry($record, $key);
            }
        }

        $tabs = [
            Tabs\Tab::make('Details')->schema([
                Section::make(Str::headline(class_basename($record)))->columns(2)->schema([
                    TextEntry::make($record->getKeyName())->label('ID')->copyable(),
                    ...$details,
                ]),
            ]),
            Tabs\Tab::make('Status & provenance')->schema([
                Section::make('Status')->columns(3)->schema($status ?: [TextEntry::make('_no_status')->hiddenLabel()->state('No status attributes on this record.')]),
                Section::make('Provenance / source')->columns(2)->schema($provenance ?: [TextEntry::make('_no_source')->hiddenLabel()->state('No source attributes on this record.')]),
            ]),
        ];

        $related = self::related($record);
        if ($related !== []) {
            $tabs[] = Tabs\Tab::make('Related')->schema([Section::make('Related records')->columns(2)->schema($related)]);
        }

        $tabs[] = Tabs\Tab::make('Audit & history')->schema([
            Section::make('Audit metadata')->columns(3)->schema($audit ?: [TextEntry::make('_no_audit')->hiddenLabel()->state('No audit columns on this record.')]),
            // audit_log.subject_id is a uuid; records keyed by a code have no timeline.
            Section::make('Timeline')->schema([Str::isUuid((string) $record->getKey())
                ? RecordShell::timeline($subjectType ?? Str::snake(class_basename($record)))
                : TextEntry::make('_no_timeline')->hiddenLabel()->state('This record is keyed by code; changes are tracked in its change log.')]),
        ]);

        return [Tabs::make('record')->tabs($tabs)->columnSpanFull()->persistTabInQueryString()];
    }

    private static function entry(Model $record, string $key): Component
    {
        $label = Str::headline($key);
        $value = $record->getAttribute($key);

        if (preg_match(self::SECRET, $key)) {
            return TextEntry::make($key)->label($label)
                ->state(fn () => $value === null || $value === '' ? null : '•••••••• (masked)')
                ->placeholder('—')->color('gray');
        }

        if (is_bool($value)) {
            return IconEntry::make($key)->label($label)->boolean();
        }

        if (is_array($value) || is_object($value) && ! $value instanceof \DateTimeInterface && ! $value instanceof \BackedEnum) {
            return TextEntry::make($key)->label($label)->columnSpanFull()
                ->state(fn () => self::json($value))
                ->fontFamily('mono')->placeholder('—')
                ->extraAttributes(['style' => 'white-space:pre-wrap']);
        }

        $entry = TextEntry::make($key)->label($label)->placeholder('—');

        if ($value instanceof \DateTimeInterface) {
            return $entry->dateTime();
        }

        if (preg_match(self::STATUS, $key) || preg_match('/(^|_)(type|kind|provenance|level|priority)$/', $key)) {
            return $entry->badge()->color(fn ($state) => self::color($state));
        }

        if (is_string($value) && mb_strlen($value) > 120) {
            $entry->columnSpanFull();
        }

        return $entry;
    }

    public static function color(mixed $state): string
    {
        $code = strtoupper((string) ($state instanceof \BackedEnum ? $state->value : $state));

        return self::DATA_STATUS_COLORS[$code] ?? match (\App\Application\WebExperiences\RecordSummary::toneFor($code)) {
            'success' => 'success', 'warning' => 'warning', 'danger' => 'danger', default => 'gray',
        };
    }

    private static function json(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        $data = json_decode(json_encode($value), true);
        array_walk_recursive($data, function (&$v, $k) {
            if (is_string($k) && preg_match(self::SECRET, $k) && ! in_array($v, [null, ''], true)) {
                $v = '•••••••• (masked)';
            }
        });

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<int, Component> */
    private static function related(Model $record): array
    {
        $entries = [];
        foreach ((new \ReflectionClass($record))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $record::class || $method->getNumberOfParameters() > 0 || $method->isStatic()) {
                continue;
            }
            $type = $method->getReturnType();
            if (! $type instanceof ReflectionNamedType || ! is_a($type->getName(), Relation::class, true)) {
                continue;
            }
            $name = $method->getName();
            try {
                $relation = $record->{$name}();
                if ($relation instanceof BelongsTo) {
                    $parent = $relation->first();
                    $entries[] = TextEntry::make('_rel_'.$name)->label(Str::headline($name))->placeholder('—')
                        ->state($parent ? self::title($parent) : null);
                } elseif ($relation instanceof HasMany) {
                    $count = (clone $relation)->count();
                    $rows = $relation->limit(10)->get()->map(fn (Model $m) => self::title($m))->all();
                    $entries[] = TextEntry::make('_rel_'.$name)->label(Str::headline($name)." ({$count})")
                        ->state($rows ?: null)->placeholder('None')->listWithLineBreaks()->limitList(10)->columnSpanFull();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $entries;
    }

    private static function title(Model $m): string
    {
        foreach (['display_name', 'name', 'label_en', 'label', 'code', 'title', 'alias', 'serial_number', 'batch_number', 'number', 'email', 'full_name'] as $attr) {
            $v = $m->getAttribute($attr);
            if (is_scalar($v) && $v !== '') {
                return (string) $v;
            }
            if (is_array($v) && isset($v['en'])) {
                return (string) $v['en'];
            }
        }

        return (string) $m->getKey();
    }
}
