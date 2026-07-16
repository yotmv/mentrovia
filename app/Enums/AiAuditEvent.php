<?php

namespace App\Enums;

enum AiAuditEvent: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Prevented = 'prevented';
    case CredentialSaved = 'credential_saved';
    case CredentialRotated = 'credential_rotated';
    case CredentialRevoked = 'credential_revoked';
    case ControlsChanged = 'controls_changed';
    case RoutingChanged = 'routing_changed';
    case PreflightStarted = 'preflight_started';
    case PreflightSucceeded = 'preflight_succeeded';
    case PreflightFailed = 'preflight_failed';
    case PreflightPrevented = 'preflight_prevented';
    case AuditExported = 'audit_exported';

    public function outcome(): string
    {
        return match ($this) {
            self::Started, self::PreflightStarted => 'started',
            self::Succeeded, self::PreflightSucceeded => 'succeeded',
            self::Failed, self::PreflightFailed => 'failed',
            self::Prevented, self::PreflightPrevented => 'prevented',
            default => 'recorded',
        };
    }
}
