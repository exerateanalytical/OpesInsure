<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Batch 10-7 REQ-ACC-002: manual journal lifecycle (DRAFT→VALIDATED→APPROVED→POSTED→REVERSED) with maker-checker,
// and DB-level debit=credit enforcement for POSTED/REVERSED journals.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $t) {
            $t->string('journal_type', 16)->default('AUTOMATIC');
            $t->string('description', 500)->nullable();
            $t->string('reason_code', 64)->nullable();
            $t->date('journal_date')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('validated_by')->nullable()->constrained('users');
            $t->timestampTz('validated_at')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->foreignUuid('posted_by')->nullable()->constrained('users');
            $t->foreignUuid('reversed_by')->nullable()->constrained('users');
            $t->timestampTz('reversed_at')->nullable();
        });
        DB::statement('ALTER TABLE journals ALTER COLUMN posted_at DROP NOT NULL');
        DB::statement('ALTER TABLE journals DROP CONSTRAINT IF EXISTS journals_status_allowed');
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_status_allowed CHECK (status IN ('DRAFT','VALIDATED','APPROVED','POSTED','REVERSED'))");
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_type_allowed CHECK (journal_type IN ('AUTOMATIC','MANUAL'))");
        DB::statement('ALTER TABLE journals ADD CONSTRAINT journals_maker_checker CHECK (approved_by IS NULL OR created_by IS NULL OR approved_by <> created_by)');
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_posted_has_date CHECK (status NOT IN ('POSTED','REVERSED') OR posted_at IS NOT NULL)");
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_manual_approved_before_post CHECK (journal_type <> 'MANUAL' OR status NOT IN ('APPROVED','POSTED','REVERSED') OR approved_by IS NOT NULL)");

        // Deferred (checked at COMMIT) so a journal header and its lines can be written in any order within one transaction.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION journals_assert_balanced() RETURNS trigger AS $$
DECLARE jid uuid; st text; d bigint; c bigint;
BEGIN
    IF TG_TABLE_NAME = 'journals' THEN jid := NEW.id; ELSIF TG_OP = 'DELETE' THEN jid := OLD.journal_id; ELSE jid := NEW.journal_id; END IF;
    SELECT status INTO st FROM journals WHERE id = jid;
    IF st IN ('POSTED','REVERSED') THEN
        SELECT COALESCE(SUM(debit_minor),0), COALESCE(SUM(credit_minor),0) INTO d, c FROM journal_lines WHERE journal_id = jid;
        IF d <> c OR d = 0 THEN
            RAISE EXCEPTION 'Journal % is not balanced (debit %, credit %)', jid, d, c USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE CONSTRAINT TRIGGER journals_balanced_on_journal AFTER INSERT OR UPDATE OF status ON journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journals_assert_balanced();
CREATE CONSTRAINT TRIGGER journals_balanced_on_line AFTER INSERT OR UPDATE OR DELETE ON journal_lines
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journals_assert_balanced();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journals_balanced_on_line ON journal_lines; DROP TRIGGER IF EXISTS journals_balanced_on_journal ON journals; DROP FUNCTION IF EXISTS journals_assert_balanced();');
        foreach (['journals_manual_approved_before_post', 'journals_posted_has_date', 'journals_maker_checker', 'journals_type_allowed', 'journals_status_allowed'] as $c) {
            DB::statement("ALTER TABLE journals DROP CONSTRAINT IF EXISTS {$c}");
        }
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_status_allowed CHECK (status IN ('DRAFT','POSTED','REVERSED'))");
        Schema::table('journals', function (Blueprint $t) {
            foreach (['created_by', 'validated_by', 'approved_by', 'posted_by', 'reversed_by'] as $c) {
                $t->dropConstrainedForeignId($c);
            }
            $t->dropColumn(['journal_type', 'description', 'reason_code', 'journal_date', 'validated_at', 'approved_at', 'reversed_at']);
        });
    }
};
