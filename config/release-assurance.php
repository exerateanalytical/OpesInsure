<?php
return [
    'performance'=>['api_p95_ms'=>(int) env('RELEASE_API_P95_MS',500),'error_rate_percent'=>(float) env('RELEASE_ERROR_RATE',1.0)],
    'recovery'=>['rto_minutes'=>(int) env('RELEASE_RTO_MINUTES',240),'rpo_minutes'=>(int) env('RELEASE_RPO_MINUTES',60)],
    'accessibility'=>['standard'=>'WCAG 2.2 AA'],
    'security'=>['block_severities'=>['CRITICAL','HIGH'],'dependency_audit'=>true,'secret_scan'=>true,'sast'=>true],
];
