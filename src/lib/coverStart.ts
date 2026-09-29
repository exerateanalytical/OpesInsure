/**
 * Cover start date choice (PUT proposals/{p}/cover-terms, SPECIFIED_DATE): calendar days in
 * Africa/Douala, the server's cover-terms timezone. Pure: node-tested (tests/cover-start.test.mjs).
 */
const TZ = "Africa/Douala";

/** Today's calendar date in Douala as YYYY-MM-DD. */
export function doualaToday(now = Date.now()): string {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: TZ, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(new Date(now));
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}`;
}

/** YYYY-MM-DD plus n calendar days (UTC arithmetic on a date-only value; no DST in Douala). */
export function addDays(iso: string, n: number): string {
  const [y, m, d] = iso.split("-").map(Number);
  const t = Date.UTC(y!, (m ?? 1) - 1, d ?? 1) + n * 86_400_000;
  return new Date(t).toISOString().slice(0, 10);
}

/** Keeps a chosen start within [today, today + maxAdvanceDays] (the server refuses anything else). */
export function clampStart(iso: string, maxAdvanceDays: number, now = Date.now()): string {
  const min = doualaToday(now);
  const max = addDays(min, Math.max(0, maxAdvanceDays));
  return iso < min ? min : iso > max ? max : iso;
}

/** Whether the product lets the customer pick a start date. */
export const canChooseStart = (rules: string[] | null | undefined) => (rules ?? []).includes("SPECIFIED_DATE");
