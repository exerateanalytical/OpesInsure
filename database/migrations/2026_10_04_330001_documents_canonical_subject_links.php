<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | REQ-DUP-021: `documents` is the one canonical document/evidence store
 | (file, hash, scan, type, origin, stage). claim_documents,
 | kyc_submission_documents, risk_asset_documents and proposal_documents are
 | kept, unchanged, as SUBJECT LINK tables (subject id + document id + the
 | link's own role/review status) — never a second copy of document data.
 |
 | Additive only:
 |  - backfill documents.document_stage (and documents.claim_id) from every
 |    existing link, without overwriting what the engine already set;
 |  - an AFTER INSERT trigger on each link table keeps that true for new links
 |    (writers outside the document module need no change);
 |  - view document_subject_links: the single read surface for "documents of a
 |    subject" (App\Application\Documents\SubjectDocuments).
 */
return new class extends Migration
{
    /** link table => [subject type, subject column, role column, status expr, linked_at expr, verified_at expr, link id expr, stage] */
    private const LINKS = [
        'claim_documents' => ['CLAIM', 'claim_id', 'evidence_type', 'l.status', 'l.submitted_at', 'l.verified_at', 'l.id', 'CLAIM'],
        'proposal_documents' => ['PROPOSAL', 'proposal_id', 'requirement_code', 'l.status', 'NULL::timestamptz', 'l.verified_at', 'NULL::uuid', 'UNDERWRITING'],
        'kyc_submission_documents' => ['KYC_SUBMISSION', 'kyc_submission_id', 'purpose', 'NULL::varchar', 'NULL::timestamptz', 'NULL::timestamptz', 'NULL::uuid', 'KYC'],
        'risk_asset_documents' => ['RISK_ASSET', 'risk_asset_id', 'purpose', 'NULL::varchar', 'NULL::timestamptz', 'NULL::timestamptz', 'NULL::uuid', 'RISK_ASSET'],
    ];

    public function up(): void
    {
        $selects = [];
        foreach (self::LINKS as $table => [$type, $col, $role, $status, $linkedAt, $verifiedAt, $linkId, $stage]) {
            // Backfill: canonical stage on the document itself.
            DB::statement("UPDATE documents d SET document_stage = '{$stage}' FROM {$table} l WHERE l.document_id = d.id AND d.document_stage IS NULL");

            $claim = $table === 'claim_documents' ? ', claim_id = COALESCE(claim_id, NEW.claim_id)' : '';
            DB::unprepared("CREATE OR REPLACE FUNCTION {$table}_stamp_document() RETURNS trigger AS \$\$
                BEGIN
                    UPDATE documents SET document_stage = COALESCE(document_stage, '{$stage}'){$claim} WHERE id = NEW.document_id;
                    RETURN NEW;
                END \$\$ LANGUAGE plpgsql");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_stamp_document ON {$table}");
            DB::unprepared("CREATE TRIGGER {$table}_stamp_document AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_stamp_document()");

            $selects[] = "SELECT '{$type}'::varchar AS subject_type, l.{$col} AS subject_id, l.document_id, {$linkId} AS link_id,
                l.{$role}::varchar AS role, {$status}::varchar AS link_status, {$linkedAt} AS linked_at, {$verifiedAt} AS verified_at
                FROM {$table} l";
        }
        DB::statement("UPDATE documents d SET claim_id = l.claim_id FROM claim_documents l WHERE l.document_id = d.id AND d.claim_id IS NULL");

        DB::statement('CREATE OR REPLACE VIEW document_subject_links AS '.implode("\nUNION ALL\n", $selects));
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS document_subject_links');
        foreach (array_keys(self::LINKS) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_stamp_document ON {$table}");
            DB::unprepared("DROP FUNCTION IF EXISTS {$table}_stamp_document()");
        }
    }
};
