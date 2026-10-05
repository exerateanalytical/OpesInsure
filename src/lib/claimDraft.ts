/**
 * New-claim wizard on server drafts (GET/POST/PATCH /mobile/claims/drafts, POST drafts/{id}/submit).
 * Steps 1–3 only save the draft; nothing is filed with the insurer until step 4 submits it after the
 * declaration. Pure: node-tested (tests/customer-account-gaps.test.mjs).
 */

export type WizardStep = "policy" | "incident" | "evidence" | "review";

/** FNOL fields a draft keeps (MobileClaimDraftService::PAYLOAD_KEYS, minus the draft-only ones). */
const FNOL_KEYS = ["incident_at", "incident_location", "description", "incident_type", "injuries_reported", "police_report_filed", "police_reference", "estimated_loss_minor"] as const;

export type DraftLike = {
  id?: string;
  policy_id?: string | null;
  payload?: {
    incident_at?: string | null;
    description?: string | null;
    incident_type?: string | null;
    incident_location?: string | null;
    client_state?: { form?: Record<string, string> | null; step?: string | null } | Record<string, unknown> | null;
    evidence?: unknown[] | null;
  } | null;
};

/** Wizard state kept on the draft: the form answers as entered, and the step the customer was on. */
export type WizardClientState = { form?: Record<string, string>; step?: WizardStep };

const MAX_FORM_KEYS = 40;

/** The raw form answers, trimmed to what the draft can hold (strings only). */
export function formState(values: Record<string, string> | null | undefined): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(values ?? {})) {
    if (Object.keys(out).length >= MAX_FORM_KEYS) break;
    if (typeof v === "string" && v !== "" && k.length <= 64) out[k] = v.slice(0, 2000);
  }
  return out;
}

export function clientStateOf(draft: DraftLike | null | undefined): WizardClientState {
  const cs = (draft?.payload?.client_state ?? {}) as Record<string, unknown>;
  const form = cs.form && typeof cs.form === "object" ? (cs.form as Record<string, string>) : undefined;
  const step = typeof cs.step === "string" && ["policy", "incident", "evidence", "review"].includes(cs.step) ? (cs.step as WizardStep) : undefined;
  return { ...(form ? { form } : {}), ...(step ? { step } : {}) };
}

const toBool = (v: unknown) => (typeof v === "boolean" ? v : v === "true" || v === "1" || v === 1 ? true : v === "false" || v === "0" || v === 0 ? false : undefined);

/**
 * PATCH body for step 2: the typed FNOL fields from the built form payload, the raw answers (so a resumed
 * draft reopens the form as it was left), the policy when the form carried one, and the device position.
 */
export function incidentDraftInput(
  payload: Record<string, unknown>,
  values: Record<string, string>,
  coords?: { latitude?: number; longitude?: number } | null,
  step: WizardStep = "evidence",
): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const k of FNOL_KEYS) {
    const v = payload[k];
    if (v === undefined || v === null || v === "") continue;
    if (k === "injuries_reported" || k === "police_report_filed") {
      const b = toBool(v);
      if (b !== undefined) out[k] = b;
    } else if (k === "estimated_loss_minor") {
      const n = Number(v);
      if (Number.isFinite(n) && n >= 0) out[k] = Math.round(n);
    } else out[k] = String(v);
  }
  if (typeof payload.policy_id === "string" && payload.policy_id) out.policy_id = payload.policy_id;
  if (coords && typeof coords.latitude === "number" && typeof coords.longitude === "number") {
    out.latitude = coords.latitude;
    out.longitude = coords.longitude;
  }
  out.client_state = { form: formState(values), step } satisfies WizardClientState;
  return out;
}

/** What drafts/{id}/submit needs (same rules as POST /mobile/claims): policy, incident date, a 10+ character description. */
export function draftMissing(draft: DraftLike | null | undefined): WizardStep | null {
  if (!draft?.policy_id) return "policy";
  const p = draft.payload ?? {};
  if (!p.incident_at || String(p.description ?? "").trim().length < 10) return "incident";
  return null;
}

/** Where "Resume" opens a saved draft: the first incomplete step, else the step it was saved on (never past review). */
export function resumeStep(draft: DraftLike | null | undefined): WizardStep {
  const missing = draftMissing(draft);
  if (missing) return missing;
  const saved = clientStateOf(draft).step;
  return saved === "evidence" || saved === "review" ? saved : "evidence";
}

const ROUTES: Record<WizardStep, string> = {
  policy: "/claim/new",
  incident: "/claim/new/incident",
  evidence: "/claim/new/evidence",
  review: "/claim/new/review",
};
/** fromReview: the step was opened by an Edit on the review, so finishing it returns there (no second review on the stack). */
export function wizardRoute(step: WizardStep, draftId: string, policyId?: string | null, fromReview = false): { pathname: string; params: Record<string, string> } {
  return { pathname: ROUTES[step], params: { draftId, ...(policyId ? { policyId } : {}), ...(fromReview ? { from: "review" } : {}) } };
}
