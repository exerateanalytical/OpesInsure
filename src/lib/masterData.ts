/**
 * Institutional master data catalogues with an offline cache.
 *
 * GET /master-data/versions returns {domains: {code: {version}}}. Each domain
 * (GET /master-data/{domain}) is cached in AsyncStorage with its
 * catalog_version and refreshed only when the server version changes, so
 * pickers work offline and stay in sync. The "vehicle" domain is served live
 * by the vehicle master (never cached here).
 */
import AsyncStorage from "@react-native-async-storage/async-storage";
import { api } from "@/api/client";
import type { MasterValue } from "@/lib/masterFields";

export type MasterList = {
  code: string;
  label: { en: string; fr: string };
  parent_list?: string | null;
  selection?: string;
  allow_other?: boolean;
  values: MasterValue[];
};
export type MasterDomain = { code: string; catalog_version: number; lists: MasterList[] };
type Versions = Record<string, { version: number }>;

const KEY = (d: string) => `opesinsure.masterdata.domain.${d}`;
const VERSIONS_KEY = "opesinsure.masterdata.versions";
const VERSIONS_TTL_MS = 10 * 60 * 1000;

const memory = new Map<string, MasterDomain>();
const inflight = new Map<string, Promise<MasterDomain | null>>();
let versions: { at: number; data: Versions } | null = null;

async function serverVersions(): Promise<Versions | null> {
  if (versions && Date.now() - versions.at < VERSIONS_TTL_MS) return versions.data;
  try {
    const res = await api<{ domains: Versions }>("/master-data/versions", { anonymous: true, timeoutMs: 6000, networkRetries: 0 });
    versions = { at: Date.now(), data: res.domains ?? {} };
    AsyncStorage.setItem(VERSIONS_KEY, JSON.stringify(versions.data)).catch(() => undefined);
    return versions.data;
  } catch {
    return null; // offline: use whatever is cached
  }
}

async function cached(domain: string): Promise<MasterDomain | null> {
  if (memory.has(domain)) return memory.get(domain)!;
  try {
    const raw = await AsyncStorage.getItem(KEY(domain));
    if (!raw) return null;
    const d = JSON.parse(raw) as MasterDomain;
    memory.set(domain, d);
    return d;
  } catch {
    return null;
  }
}

/** A domain catalogue: cached copy when current, otherwise fetched (and cached). */
export function loadDomain(domain: string): Promise<MasterDomain | null> {
  const running = inflight.get(domain);
  if (running) return running;
  const p = (async () => {
    const local = await cached(domain);
    const remote = await serverVersions();
    const want = remote?.[domain]?.version;
    if (local && (want === undefined || want === local.catalog_version)) return local;
    try {
      const fresh = await api<MasterDomain>(`/master-data/${encodeURIComponent(domain)}`, { anonymous: true, timeoutMs: 12000 });
      const slim: MasterDomain = {
        code: fresh.code,
        catalog_version: fresh.catalog_version,
        lists: fresh.lists.map((l) => ({
          code: l.code, label: l.label, parent_list: l.parent_list, selection: l.selection, allow_other: l.allow_other,
          values: l.values.map((v) => ({ code: v.code, label: v.label, parent: v.parent, aliases: v.aliases, attributes: v.attributes, is_other: v.is_other, common: v.common })),
        })),
      };
      memory.set(domain, slim);
      AsyncStorage.setItem(KEY(domain), JSON.stringify(slim)).catch(() => undefined);
      return slim;
    } catch (e) {
      if (local) return local; // stale but usable offline
      throw e;
    }
  })().finally(() => inflight.delete(domain));
  inflight.set(domain, p);
  return p;
}

/** One list, resolving "list" or "domain.list" parent references. */
export async function loadList(domain: string, list: string): Promise<{ list: MasterList; parents?: MasterList } | null> {
  if (domain === "vehicle") return loadVehicleList(list);
  const d = await loadDomain(domain);
  const l = d?.lists.find((x) => x.code === list);
  if (!l) return null;
  let parents: MasterList | undefined;
  if (l.parent_list) {
    const [pd, pl] = l.parent_list.includes(".") ? l.parent_list.split(".", 2) : [domain, l.parent_list];
    const pdom = pd === domain ? d : await loadDomain(pd!);
    parents = pdom?.lists.find((x) => x.code === pl);
  }
  return { list: l, parents };
}

/** Vehicle master lists (makes, reference groups) are always live. */
async function loadVehicleList(list: string): Promise<{ list: MasterList } | null> {
  const res = await api<{ values: MasterValue[]; allow_other?: boolean }>(`/master-data/vehicle/${encodeURIComponent(list)}?limit=500`, { anonymous: true, timeoutMs: 8000 });
  return { list: { code: list, label: { en: list, fr: list }, allow_other: true, values: res.values ?? [] } };
}

export async function searchVehicleModels(make: string, q: string): Promise<MasterValue[]> {
  const res = await api<{ values: MasterValue[] }>(`/master-data/vehicle/models?parent=${encodeURIComponent(make)}&q=${encodeURIComponent(q)}`, { anonymous: true, timeoutMs: 8000 });
  return res.values ?? [];
}

/**
 * "Other / Not listed": files the typed value in the review queue. Never
 * blocks the quote — failures are swallowed (the server also files it when
 * the quote is submitted with `{key}_other`).
 */
export async function suggestValue(input: { domain: string; list: string; text: string; parent?: string; line_code?: string; field_key?: string }) {
  try {
    return await api<{ status: string; value?: MasterValue | null }>("/master-data/suggestions", {
      method: "POST",
      body: JSON.stringify({ ...input, screen: "quote.risk" }),
      idempotent: true,
      timeoutMs: 8000,
    });
  } catch {
    return null;
  }
}

/** Drops every cached catalogue (e.g. on language change it is not needed — labels are bilingual). */
export async function clearMasterDataCache() {
  memory.clear();
  versions = null;
  const keys = (await AsyncStorage.getAllKeys()).filter((k) => k.startsWith("opesinsure.masterdata."));
  await AsyncStorage.multiRemove(keys);
}
