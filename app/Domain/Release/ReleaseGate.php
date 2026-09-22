<?php
namespace App\Domain\Release;

enum ReleaseGate: string
{
    case SECURITY = 'SECURITY';
    case PERFORMANCE = 'PERFORMANCE';
    case ACCESSIBILITY = 'ACCESSIBILITY';
    case DISASTER_RECOVERY = 'DISASTER_RECOVERY';
    case UAT = 'UAT';
    case OPENAPI = 'OPENAPI';
    case DATA_MIGRATION = 'DATA_MIGRATION';
}
