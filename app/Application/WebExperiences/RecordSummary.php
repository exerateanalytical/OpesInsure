<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

/**
 * REQ-UI-002 record-summary contract (SSR §23): the header every detail
 * screen shows — canonical identifier, title, status (+ tone), key metadata
 * and the actions the viewer may take. Also serialisable for API/mobile use.
 */
final readonly class RecordSummary
{
    /**
     * @param  array<string, scalar|null>  $metadata  label => value
     * @param  list<string>  $tabs
     */
    public function __construct(
        public string $entity,
        public string $identifier,
        public string $title,
        public string $status,
        public string $tone = 'gray',
        public array $metadata = [],
        public array $tabs = [],
        public ?string $staleSince = null,
    ) {}

    public static function toneFor(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE', 'ISSUED', 'PAID', 'SETTLED', 'APPROVED', 'VALID', 'CLOSED_PAID', 'SUCCEEDED', 'COMPLETED' => 'success',
            'PENDING', 'SUBMITTED', 'QUEUED', 'REFERRED', 'EXPIRING', 'UNDER_REVIEW', 'DRAFT', 'PENDING_PAYMENT', 'AWAITING_DOCUMENTS' => 'warning',
            'CANCELLED', 'EXPIRED', 'REJECTED', 'DECLINED', 'FAILED', 'VOID', 'REVOKED', 'SUSPENDED' => 'danger',
            default => 'info',
        };
    }

    /** Outline status icon per tone (status = icon + label + colour, never colour alone). */
    public static function iconFor(string $tone): string
    {
        return match ($tone) {
            'success' => 'lucide-circle-check',
            'warning' => 'lucide-clock',
            'danger' => 'lucide-circle-x',
            'info' => 'lucide-info',
            default => 'lucide-circle-minus',
        };
    }

    /** Readable label for a stable status code; the code itself remains the API value. */
    public static function labelFor(string $status): string
    {
        return ucfirst(strtolower(str_replace(['_', '-'], ' ', $status)));
    }

    public function toArray(): array
    {
        return [
            'entity' => $this->entity, 'identifier' => $this->identifier, 'title' => $this->title,
            'status' => $this->status, 'tone' => $this->tone, 'metadata' => $this->metadata,
            'tabs' => $this->tabs, 'stale_since' => $this->staleSince,
        ];
    }
}
