/**
 * Pure paging maths for the welcome slides (app/welcome.tsx), unit tested in
 * tests/navigation-continuity.test.mjs. Every result is clamped to a real
 * page, so rapid taps, a half-finished fling or a width change can never
 * leave the pager on a page that does not exist.
 */
export const clampPage = (index: number, count: number) =>
  count <= 0 ? 0 : Math.min(Math.max(Number.isFinite(index) ? Math.round(index) : 0, 0), count - 1);

export const nextPage = (current: number, count: number) => clampPage(current + 1, count);
export const previousPage = (current: number, count: number) => clampPage(current - 1, count);

/** Page under a horizontal scroll offset; 0 while the pager is unmeasured. */
export const pageFromOffset = (offsetX: number, pageWidth: number, count: number) =>
  pageWidth > 0 ? clampPage(offsetX / pageWidth, count) : 0;

/** Minimum gap between two primary-button actions, so a double tap on "Next"
 * on the second-last slide cannot also fire "Get started". */
export const PRIMARY_TAP_COOLDOWN_MS = 400;
export const tapAllowed = (lastTapAt: number, now: number, cooldownMs = PRIMARY_TAP_COOLDOWN_MS) =>
  now - lastTapAt >= cooldownMs;
