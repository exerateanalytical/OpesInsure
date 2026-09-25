<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

/**
 * Owner decision item 23: reconstructed checklists are labelled truthfully. The owner's original wording is not in
 * the repository, so these are OpesInsure reconstructions, never presented as the official list.
 */
final class ReconstructedChecklists
{
    /** CIMA-ready checklist (CimaReadinessChecklist), reconstructed from the CIMA dictionary; draft until the owner reconciles it. */
    public const CIMA_READINESS = 'OPESINSURE_CIMA_READINESS_V1_DRAFT';

    /** Definition of Done (PRE §101, 38 items), reconstructed from the specs. */
    public const DOD = 'OPESINSURE_DOD_V1_RECONSTRUCTED';
}
