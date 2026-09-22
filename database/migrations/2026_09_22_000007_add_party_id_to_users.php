<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single most-cited gap across the last several mobile-backend audits:
 * nothing links a User (login identity) to the Party (insurance-domain
 * policyholder) or, transitively via Partner.party_id, to a Partner
 * (broker/agent/carrier org) they might also be. PartyResolver's
 * phone-number heuristic was always a stopgap for this — this migration
 * adds the real FK it was standing in for. Nullable and optional on both
 * sides: plenty of Users (internal staff) have no Party, and plenty of
 * Parties (third parties, non-app customers) have no User.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->foreignUuid('party_id')->nullable()->after('phone_e164')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('party_id');
        });
    }
};
