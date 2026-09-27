/**
 * Pure helpers for Security & Sessions and Login Activity (agent spec v2
 * screens 03/04). Shared by the customer and agent layouts: grouping,
 * collapsing and the locked session-status vocabulary live here once.
 */
import type { DeviceSession, LoginActivity, NotificationPreferences } from "@/api/client";

/** Locked session vocabulary (AGENT_UI_SPEC_V2 §10). */
export type SessionStatus = "Current" | "Recognized" | "New Device" | "Suspicious" | "Expired" | "Signed Out";

export const isFailedEvent = (row: LoginActivity) => (row.outcome ? row.outcome.toUpperCase() !== "SUCCESS" : false);

/** Anomaly flags other than NEW_DEVICE (which has its own status). */
export const riskFlags = (row: LoginActivity) => (row.anomaly_flags ?? []).filter((flag) => flag !== "NEW_DEVICE");

export function loginStatus(row: LoginActivity): SessionStatus {
  const event = (row.event_type ?? "").toUpperCase();
  if (riskFlags(row).length > 0 || isFailedEvent(row)) return "Suspicious";
  if (row.new_device) return "New Device";
  if (event === "LOGOUT" || event === "SIGN_OUT_EVERYWHERE") return "Signed Out";
  return "Recognized";
}

/** YYYY-MM-DD of an instant in the given IANA zone. */
export function dayKey(iso: string, timeZone: string): string {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(new Date(iso));
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}`;
}

export function timeOf(iso: string, timeZone: string, language: string): string {
  return new Intl.DateTimeFormat(language === "fr" ? "fr-CM" : "en-GB", { timeZone, hour: "2-digit", minute: "2-digit", hour12: false }).format(new Date(iso));
}

export type LoginCluster = { key: string; latest: LoginActivity; rows: LoginActivity[] };
export type LoginDay = { day: string; relative: "today" | "yesterday" | null; clusters: LoginCluster[] };

const deviceOf = (row: LoginActivity) => row.device_id ?? row.device_name ?? row.platform ?? "unknown";

/**
 * Groups rows by local day (newest first) and collapses consecutive
 * successful sign-ins from the same device on the same day. Risky events and
 * non-sign-in events always stay on their own line so nothing is hidden.
 */
export function groupLoginActivity(rows: LoginActivity[], timeZone: string, now: Date = new Date()): LoginDay[] {
  const sorted = [...rows].sort((a, b) => Date.parse(b.occurred_at) - Date.parse(a.occurred_at));
  const today = dayKey(now.toISOString(), timeZone);
  const yesterday = dayKey(new Date(now.getTime() - 86_400_000).toISOString(), timeZone);
  const days: LoginDay[] = [];
  for (const row of sorted) {
    const day = Number.isFinite(Date.parse(row.occurred_at)) ? dayKey(row.occurred_at, timeZone) : "unknown";
    let bucket = days[days.length - 1];
    if (!bucket || bucket.day !== day) {
      bucket = { day, relative: day === today ? "today" : day === yesterday ? "yesterday" : null, clusters: [] };
      days.push(bucket);
    }
    const plain = loginStatus(row) === "Recognized" && ((row.event_type ?? "LOGIN").toUpperCase() === "LOGIN");
    const last = bucket.clusters[bucket.clusters.length - 1];
    if (plain && last && deviceOf(last.latest) === deviceOf(row) && loginStatus(last.latest) === "Recognized" && (last.latest.event_type ?? "LOGIN").toUpperCase() === "LOGIN") {
      last.rows.push(row);
    } else {
      bucket.clusters.push({ key: row.id, latest: row, rows: [row] });
    }
  }
  return days;
}

/** Distinct devices seen in the activity, for the device filter. */
export function activityDevices(rows: LoginActivity[]): { id: string; label: string }[] {
  const seen = new Map<string, string>();
  for (const row of rows) {
    const id = deviceOf(row);
    if (!seen.has(id)) seen.set(id, row.device_name ?? row.platform ?? id);
  }
  return [...seen].map(([id, label]) => ({ id, label }));
}

export const matchesDevice = (row: LoginActivity, device: string | null) => !device || deviceOf(row) === device;

export const sortSessions = (devices: DeviceSession[]) =>
  [...devices].sort((a, b) => Number(b.current) - Number(a.current) || Date.parse(b.last_seen_at) - Date.parse(a.last_seen_at));

/**
 * Agent Notification Settings (spec screen 02) mapped onto the backend's
 * preference keys. `key: null` = the backend has no setting yet (shown
 * disabled, "Not available yet"). Rows that share a key move together.
 */
export type NotifRow = { id: string; key: keyof NotificationPreferences | null };
export const AGENT_NOTIF_SECTIONS: { id: string; shared?: boolean; rows: NotifRow[] }[] = [
  { id: "channels", rows: [{ id: "push", key: "push" }, { id: "sms", key: "sms" }, { id: "email", key: "email" }, { id: "whatsapp", key: null }] },
  {
    id: "sales",
    rows: [
      { id: "newLead", key: null },
      { id: "quoteUpdates", key: null },
      { id: "policyIssued", key: null },
      { id: "renewalReminders", key: "renewals" },
      { id: "policyCancellation", key: null },
    ],
  },
  {
    id: "claims",
    shared: true,
    rows: [
      { id: "claimSubmitted", key: "claims" },
      { id: "claimStatus", key: "claims" },
      { id: "claimInfo", key: "claims" },
      { id: "claimDecision", key: "claims" },
    ],
  },
  {
    id: "earnings",
    shared: true,
    rows: [
      { id: "commissionAccrued", key: "payments" },
      { id: "commissionAvailable", key: "payments" },
      { id: "commissionPaid", key: "payments" },
      { id: "withdrawalUpdates", key: "payments" },
    ],
  },
];
export const AGENT_NOTIF_REQUIRED = ["newLogin", "passwordChanged", "newDevice", "suspicious"] as const;
