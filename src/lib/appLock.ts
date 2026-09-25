/**
 * Pure timing rules for the app lock, idle timeout and runtime polling.
 * No imports: unit-tested directly under node (tests/app-lock.test.mjs).
 */
export const LOCK_DEFAULTS = {
  /** Background time before biometrics are asked again (camera, KYC and
   * mobile-money app switches are usually shorter than this). */
  relockGraceSeconds: 60,
  /** Background or no-touch time after which the session is ended. */
  idleTimeoutSeconds: 15 * 60,
  /** Minimum gap between runtime bootstrap checks on foreground. */
  runtimeCheckSeconds: 5 * 60,
} as const;

const positive = (value: unknown, fallback: number) => {
  const n = Number(value);
  return Number.isFinite(n) && n > 0 ? n : fallback;
};

export type LockPolicy = { relockGraceMs: number; idleTimeoutMs: number };

/** Server value (runtime bootstrap) wins, then the build env, then defaults. */
export function resolveLockPolicy(
  server?: { relock_grace_seconds?: number | null; session_idle_timeout_seconds?: number | null } | null,
  env?: { relockGraceSeconds?: string; idleTimeoutSeconds?: string },
): LockPolicy {
  const grace = positive(
    server?.relock_grace_seconds,
    positive(env?.relockGraceSeconds, LOCK_DEFAULTS.relockGraceSeconds),
  );
  const idle = positive(
    server?.session_idle_timeout_seconds,
    positive(env?.idleTimeoutSeconds, LOCK_DEFAULTS.idleTimeoutSeconds),
  );
  return { relockGraceMs: grace * 1000, idleTimeoutMs: Math.max(idle, grace) * 1000 };
}

/** Re-lock only after the grace period, and never while an app-initiated
 * picker / camera / payment hand-off is in progress. */
export function shouldRelock(backgroundedAt: number | null, now: number, graceMs: number, suspended = false) {
  if (suspended || backgroundedAt === null) return false;
  return now - backgroundedAt >= graceMs;
}

export function isIdleExpired(lastActiveAt: number | null, now: number, idleMs: number) {
  if (lastActiveAt === null) return false;
  return now - lastActiveAt >= idleMs;
}

/** Exponential backoff for network re-checks while offline: 5 s → 5 min. */
export function backoffDelay(failures: number, baseMs = 5000, maxMs = 5 * 60_000) {
  return Math.min(maxMs, baseMs * 2 ** Math.max(0, Math.min(failures, 16)));
}

/** Run the runtime bootstrap check on foreground only when this old. */
export function runtimeCheckDue(lastCheckedAt: number | null, now: number, gapMs = LOCK_DEFAULTS.runtimeCheckSeconds * 1000) {
  return lastCheckedAt === null || now - lastCheckedAt >= gapMs;
}

// --- Lock suspension (camera, picker, MoMo hand-off) -------------------------
let suspensions = 0;
export const lockSuspended = () => suspensions > 0;
/** Wraps an app-initiated flow that sends the app to the background, so the
 * user comes back to the same screen without a biometric prompt. */
export async function withoutRelock<T>(task: () => Promise<T>): Promise<T> {
  suspensions += 1;
  try {
    return await task();
  } finally {
    // Leave the flag on briefly: the AppState "active" event can land after
    // the picker promise resolves.
    setTimeout(() => {
      suspensions = Math.max(0, suspensions - 1);
    }, 1500);
  }
}
