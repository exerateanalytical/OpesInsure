/**
 * Client checks for PUT /me/password, mirroring the server rules
 * (current required; new 8–128 characters, confirmed, different from the current one).
 * Pure: node-tested (tests/customer-account-gaps.test.mjs).
 */
export type PasswordProblem = {
  field: "current" | "next" | "confirm";
  copy: "requiredToContinue" | "passwordMin" | "pwTooLong" | "pwSameAsCurrent" | "passwordMismatch";
};

export function passwordChangeProblem(current: string, next: string, confirm: string): PasswordProblem | null {
  if (!current) return { field: "current", copy: "requiredToContinue" };
  if (next.length < 8) return { field: "next", copy: "passwordMin" };
  if (next.length > 128) return { field: "next", copy: "pwTooLong" };
  if (next === current) return { field: "next", copy: "pwSameAsCurrent" };
  if (confirm !== next) return { field: "confirm", copy: "passwordMismatch" };
  return null;
}
