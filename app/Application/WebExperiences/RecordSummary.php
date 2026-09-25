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

    public function toArray(): array
    {
        return [
            'entity' => $this->entity, 'identifier' => $this->identifier, 'title' => $this->title,
            'status' => $this->status, 'tone' => $this->tone, 'metadata' => $this->metadata,
            'tabs' => $this->tabs, 'stale_since' => $this->staleSince,
        ];
    }
}
