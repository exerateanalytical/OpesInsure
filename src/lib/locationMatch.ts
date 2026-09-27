/**
 * Device location → Cameroon geography master data (pure; no React / RN
 * imports so tests load it with Node type stripping).
 *
 * The reverse geocoder returns free names ("Littoral", "Région du Littoral",
 * "North-West Region", "Douala", "Wouri"). They are matched against the same
 * master lists the forms use (geography.cameroon_region → cameroon_department
 * → city) by bilingual label, code and aliases, accent/case-insensitively.
 */
import { labelOf, normalizeText, type Lang, type MasterValue } from "./masterFields.ts";

export type GeocodedPlace = {
  city?: string | null;
  district?: string | null;
  subregion?: string | null;
  region?: string | null;
  country?: string | null;
  isoCountryCode?: string | null;
};
export type DeviceFix = { latitude: number; longitude: number; accuracy?: number | null; place: GeocodedPlace | null };
export type MatchedPlace = {
  region?: MasterValue;
  department?: MasterValue;
  city?: MasterValue;
  /** Best display text for the town even when it is not in the city list. */
  townText?: string;
  regionText?: string;
};

const NOISE = new Set(["region", "province", "de", "du", "des", "la", "le", "l", "the", "of", "departement", "department", "division"]);

/** "Région du Nord-Ouest" → "nord ouest"; "North West Region" → "north west". */
export function placeKey(s: string | null | undefined): string {
  return normalizeText(s)
    .split(" ")
    .filter((w) => w && !NOISE.has(w))
    .map((w) => SYNONYM[w] ?? w)
    .join(" ");
}
/** English geocoder words → the French forms used in region codes ("Far North" = "Extrême-Nord"). */
const SYNONYM: Record<string, string> = { north: "nord", south: "sud", west: "ouest", east: "est", far: "extreme", adamawa: "adamaoua", northwest: "nordouest", southwest: "sudouest" };
const squash = (s: string) => s.replace(/ /g, "");

function keysOf(v: MasterValue): string[] {
  return [v.label?.en, v.label?.fr, v.code.replace(/_/g, " "), ...(v.aliases ?? [])].map(placeKey).filter(Boolean);
}

export function findPlace(values: MasterValue[], name: string | null | undefined): MasterValue | undefined {
  const k = placeKey(name);
  if (!k) return undefined;
  const pool = values.filter((v) => !v.is_other && v.code !== "OTHER");
  return (
    pool.find((v) => keysOf(v).includes(k)) ??
    pool.find((v) => keysOf(v).some((x) => squash(x) === squash(k)))
  );
}

/** Maps a geocoded place onto region / department / city master values. */
export function matchPlace(
  place: GeocodedPlace | null,
  lists: { regions: MasterValue[]; departments?: MasterValue[]; cities?: MasterValue[] },
): MatchedPlace {
  if (!place) return {};
  const out: MatchedPlace = {};
  out.region = findPlace(lists.regions, place.region);
  const departments = lists.departments ?? [];
  const cities = lists.cities ?? [];
  const inRegion = (d?: MasterValue) => !out.region || !d?.parent || d.parent === out.region.code;
  for (const name of [place.city, place.district, place.subregion]) {
    const c = findPlace(cities, name);
    const d = c?.parent ? departments.find((x) => x.code === c.parent) : undefined;
    if (c && (inRegion(d) || !departments.length)) {
      out.city = c;
      out.department = d;
      break;
    }
  }
  if (!out.department) {
    const d = findPlace(departments, place.subregion) ?? findPlace(departments, place.district);
    if (d && inRegion(d)) out.department = d;
  }
  if (!out.region && out.department?.parent) out.region = lists.regions.find((r) => r.code === out.department!.parent);
  out.townText = (place.city || place.district || place.subregion || "").trim() || undefined;
  out.regionText = (place.region || "").trim() || undefined;
  return out;
}

/** "Douala, Littoral" for the Detected hint. */
export function placeSummary(m: MatchedPlace, lang: Lang): string {
  const town = m.city ? labelOf(m.city, lang) : m.townText;
  const region = m.region ? labelOf(m.region, lang) : m.regionText;
  return [town, region].filter(Boolean).join(", ");
}

/** "4.05110, 9.76790" — kept in free text when the API has no coordinate fields. */
export function formatCoords(fix: Pick<DeviceFix, "latitude" | "longitude">): string {
  return `${fix.latitude.toFixed(5)}, ${fix.longitude.toFixed(5)}`;
}

/**
 * Keys of a region → department → city group in a form ("" for region/city,
 * "incident_" for incident_region/…). A group is found from the region field
 * on the cameroon_region list.
 */
export function locationGroups(fields: { key: string; master?: { list: string } }[]) {
  return fields
    .filter((f) => f.master?.list === "cameroon_region" && f.key.endsWith("region"))
    .map((f) => {
      const prefix = f.key.slice(0, -"region".length);
      const has = (k: string) => fields.some((x) => x.key === k);
      return {
        region: f.key,
        department: has(`${prefix}department`) ? `${prefix}department` : undefined,
        city: has(`${prefix}city`) ? `${prefix}city` : undefined,
      };
    });
}
