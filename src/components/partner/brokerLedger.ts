import { apiPage } from "@/api/client";
import { BrokerWorkspaceApi } from "@/api/partner";
import type { CommissionRow } from "./commissionFilters";

/** GET /mobile/broker/commission-accruals row (the stored accrual, scoped to the caller's partner). */
type StoredAccrual = {
  id: string;
  policy_id?: string | null;
  amount_minor: number;
  paid_minor?: number | null;
  vested_minor?: number | null;
  clawed_back_minor?: number | null;
  currency: string;
  status: string;
  rule_version?: string | null;
  source_type?: string | null;
  source_id?: string | null;
  earned_at?: string | null;
  vests_at?: string | null;
  available_at?: string | null;
  payable_at?: string | null;
  paid_at?: string | null;
  created_at?: string | null;
};

const MAX_PAGES = 10;
async function storedAccruals(): Promise<StoredAccrual[]> {
  const out: StoredAccrual[] = [];
  for (let page = 1; page <= MAX_PAGES; page++) {
    const r = await apiPage<StoredAccrual>("/mobile/broker/commission-accruals", page);
    out.push(...r.items);
    if (!r.info.hasMore) break;
  }
  return out;
}

/**
 * Broker earnings rows: partner/broker/commissions (policy, customer) joined
 * with the book policies (insurer, product, premium, issue date) and the
 * stored accruals (accrual date, availability, payment date, clawbacks). Every
 * figure is the server's; the join only fills attribution fields.
 */
async function load(): Promise<CommissionRow[]> {
  const [rows, stored] = await Promise.all([BrokerWorkspaceApi.commissionLedger(), storedAccruals().catch(() => [] as StoredAccrual[])]);
  const byId = new Map(stored.map((a) => [a.id, a]));
  return rows.map((r) => {
    const a = byId.get(r.id);
    if (!a) return r;
    return {
      ...r,
      paid_minor: r.paid_minor ?? a.paid_minor ?? 0,
      clawed_back_minor: r.clawed_back_minor ?? a.clawed_back_minor ?? 0,
      vested_minor: r.vested_minor ?? a.vested_minor ?? null,
      rule_version: r.rule_version ?? a.rule_version ?? null,
      source_type: r.source_type ?? a.source_type ?? null,
      source_id: r.source_id ?? a.source_id ?? null,
      accrued_at: r.accrued_at ?? a.earned_at ?? null,
      available_at: r.available_at ?? a.available_at ?? a.vests_at ?? null,
      payable_at: r.payable_at ?? a.payable_at ?? null,
      paid_at: r.paid_at ?? a.paid_at ?? null,
    };
  });
}

// One fetch shared by the earnings list, its detail and the per-sale badges on
// policy / proposal / production rows opened right after it.
const TTL_MS = 30_000;
let cache: { at: number; p: Promise<CommissionRow[]> } | null = null;
export function loadBrokerLedger(fresh = false): Promise<CommissionRow[]> {
  if (!fresh && cache && Date.now() - cache.at < TTL_MS) return cache.p;
  const p = load();
  cache = { at: Date.now(), p };
  p.catch(() => {
    if (cache?.p === p) cache = null;
  });
  return p;
}
