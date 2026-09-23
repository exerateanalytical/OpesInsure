<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Collapses party_identifiers.value_encrypted rows that were written twice over.
 *
 * PartyService::addIdentifier() used to call Crypt::encryptString() on a column
 * the PartyIdentifier model already casts as 'encrypted', so those rows hold
 * E(E(plaintext)). Reading them back through the model decrypted one layer and
 * returned ciphertext instead of the identifier. The duplicate encryption has
 * been removed from the service; this repairs rows written before that.
 *
 * Detection is by decryption rather than by date: decrypt one layer, then try
 * to decrypt the result again. If that second decrypt succeeds the value was
 * double-encrypted and the inner ciphertext is the correctly-encrypted form, so
 * it is stored as-is. If it fails, the row was already single-encrypted and is
 * left untouched. That makes this migration idempotent and safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        // Raw queries throughout: going through the model would apply the
        // 'encrypted' cast and fight the very thing being repaired.
        foreach (DB::table('party_identifiers')->select('id', 'value_encrypted')->cursor() as $row) {
            if (blank($row->value_encrypted)) {
                continue;
            }

            try {
                $inner = Crypt::decryptString($row->value_encrypted);
            } catch (\Throwable) {
                // Not decryptable at all — a plaintext row, or encrypted under a
                // different APP_KEY. Either way this migration must not guess.
                $failed++;

                continue;
            }

            try {
                Crypt::decryptString($inner);
            } catch (\Throwable) {
                // Inner layer is plaintext: already correct.
                $skipped++;

                continue;
            }

            DB::table('party_identifiers')->where('id', $row->id)->update(['value_encrypted' => $inner]);
            $repaired++;
        }

        if ($repaired || $failed) {
            echo "party_identifiers: repaired {$repaired}, already correct {$skipped}, undecryptable {$failed}".PHP_EOL;
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. Re-encrypting would restore a defect, and
        // the repaired rows are indistinguishable from rows written correctly
        // after the fix, so there is nothing safe to single out.
    }
};
