import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, Switch, Text, View } from "react-native";
import { LocateFixed, MapPin, X } from "lucide-react-native";
import { useTranslation } from "@/i18n";
import { loadList } from "@/lib/masterData";
import { LocationPrefs, locationSupported, readDeviceLocation, type LocationCopy } from "@/lib/deviceLocation";
import { formatCoords, locationGroups, matchPlace, placeSummary, type DeviceFix, type MatchedPlace } from "@/lib/locationMatch";
import { labelOf, type MasterValue } from "@/lib/masterFields";
import { colors, radius, space, type } from "@/theme/tokens";

export type AutoLocationStatus = "off" | "idle" | "loading" | "ready" | "unavailable";

/**
 * Reads the device position for one form. Runs automatically when the global
 * switch (Settings → Privacy) is on and the form is not opted out; "Use my
 * location" retries interactively. Everything stays on the device except what
 * the form itself submits.
 */
export function useAutoLocation(form: string) {
  const { t } = useTranslation();
  const [status, setStatus] = useState<AutoLocationStatus>("idle");
  const [fix, setFix] = useState<DeviceFix | null>(null);
  const [optedOut, setOptedOutState] = useState(false);
  const [globalOn, setGlobalOn] = useState(true);
  const copy: LocationCopy = useMemo(
    () => ({ title: t("locExplainTitle"), body: t("locExplainBody"), allow: t("locAllow"), notNow: t("locNotNow") }),
    [t],
  );
  const alive = useRef(true);
  useEffect(() => () => void (alive.current = false), []);

  const detect = useCallback(
    async (interactive: boolean) => {
      if (!locationSupported()) return setStatus("unavailable");
      setStatus("loading");
      const f = await readDeviceLocation(copy, interactive);
      if (!alive.current) return;
      setFix(f);
      setStatus(f ? "ready" : interactive ? "unavailable" : "idle");
    },
    [copy],
  );

  useEffect(() => {
    let live = true;
    void (async () => {
      const [on, out] = await Promise.all([LocationPrefs.enabled(), LocationPrefs.formOptedOut(form)]);
      if (!live) return;
      setGlobalOn(on);
      setOptedOutState(out);
      if (!on || out) return setStatus("off");
      if (locationSupported()) void detect(false);
    })();
    return () => {
      live = false;
    };
  }, [form, detect]);

  const setOptedOut = useCallback(
    async (out: boolean) => {
      setOptedOutState(out);
      await LocationPrefs.setFormOptedOut(form, out);
      if (out) {
        setFix(null);
        setStatus("off");
      } else if (globalOn) void detect(true);
    },
    [form, globalOn, detect],
  );

  return {
    status,
    fix,
    optedOut,
    globalOn,
    supported: locationSupported(),
    /** "Use my location": asks again when needed (also works with the global switch off — the user asked). */
    useNow: () => void detect(true),
    setOptedOut,
    clear: () => {
      setFix(null);
      setStatus("idle");
    },
  };
}
export type AutoLocation = ReturnType<typeof useAutoLocation>;

/** "Detected: Douala, Littoral" + Use my location / Clear / Don't use my location. */
export function LocationHint({ loc, summary, onClear }: { loc: AutoLocation; summary?: string; onClear?: () => void }) {
  const { t } = useTranslation();
  if (!loc.supported) return null;
  const text = loc.status === "loading" ? t("locDetecting") : loc.status === "unavailable" ? t("locUnavailable") : summary ? t("locDetected", { place: summary }) : null;
  return (
    <View style={s.box}>
      {text ? (
        <View style={s.row} accessibilityLiveRegion="polite">
          {loc.status === "loading" ? <ActivityIndicator size="small" color={colors.blue600} /> : <MapPin size={16} color={colors.blue600} />}
          <Text style={s.text}>{text}</Text>
          {summary && loc.status === "ready" ? (
            <Pressable accessibilityRole="button" accessibilityLabel={t("locClear")} hitSlop={12} style={s.clear} onPress={() => { onClear?.(); loc.clear(); }}>
              <X size={16} color={colors.neutral600} />
              <Text style={s.link}>{t("locClear")}</Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}
      <View style={s.row}>
        {!loc.optedOut && loc.status !== "loading" ? (
          <Pressable accessibilityRole="button" style={s.action} onPress={loc.useNow}>
            <LocateFixed size={16} color={colors.blue600} />
            <Text style={s.link}>{t("locUseMine")}</Text>
          </Pressable>
        ) : null}
        <Pressable
          accessibilityRole="checkbox"
          accessibilityState={{ checked: loc.optedOut }}
          style={s.action}
          onPress={() => { if (!loc.optedOut) onClear?.(); void loc.setOptedOut(!loc.optedOut); }}
        >
          <Text style={s.muted}>{loc.optedOut ? t("locOptIn") : t("locOptOut")}</Text>
        </Pressable>
      </View>
    </View>
  );
}

type Field = { key: string; master?: { domain: string; list: string } };

async function geographyLists() {
  const [r, d, c] = await Promise.all([
    loadList("geography", "cameroon_region").catch(() => null),
    loadList("geography", "cameroon_department").catch(() => null),
    loadList("geography", "city").catch(() => null),
  ]);
  return { regions: r?.list.values ?? [], departments: d?.list.values ?? [], cities: c?.list.values ?? [] };
}

/**
 * Autofill for server forms with region → department → city master pickers
 * (claim_fnol, customer_profile, quote risk questions, …). Fills only empty
 * fields and never overrides a different pick; Clear removes only what it
 * filled and the user has not changed since.
 */
export function FormLocationAutofill({
  form,
  fields,
  values,
  setValues,
  onLabels,
  onFix,
}: {
  form: string;
  /** Current position (null when cleared / opted out), e.g. for claim latitude/longitude. */
  onFix?: (fix: DeviceFix | null) => void;
  fields: Field[];
  values: Record<string, string>;
  setValues: (fn: (x: Record<string, string>) => Record<string, string>) => void;
  onLabels?: (labels: Record<string, string>) => void;
}) {
  const { language } = useTranslation();
  const lang = language === "fr" ? "fr" : "en";
  const loc = useAutoLocation(form);
  // Stable across renders (screens pass a fresh field array each render).
  const fieldKey = fields.map((f) => `${f.key}:${f.master?.list ?? ""}`).join("|");
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const groups = useMemo(() => locationGroups(fields), [fieldKey]);
  const [match, setMatch] = useState<MatchedPlace | null>(null);
  const filled = useRef<Record<string, string>>({});
  const valuesRef = useRef(values);
  valuesRef.current = values;

  useEffect(() => {
    onFix?.(loc.fix);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loc.fix]);

  useEffect(() => {
    if (!loc.fix || !groups.length) return setMatch(null);
    let live = true;
    void (async () => {
      const m = matchPlace(loc.fix!.place, await geographyLists());
      if (!live) return;
      setMatch(m);
      const next: Record<string, string> = {};
      const labels: Record<string, string> = {};
      const v = valuesRef.current;
      const put = (key: string | undefined, val: MasterValue | undefined) => {
        if (!key || !val || (v[key] ?? "") !== "") return;
        next[key] = val.code;
        labels[key] = labelOf(val, lang);
      };
      for (const g of groups) {
        // A different region/department already picked by the user wins: leave the group alone.
        if (v[g.region] && m.region && v[g.region] !== m.region.code) continue;
        put(g.region, m.region);
        if (g.department && v[g.department] && m.department && v[g.department] !== m.department.code) continue;
        put(g.department, m.department);
        if (g.city && m.city && (!g.department || (v[g.department] || next[g.department]) === m.city.parent)) put(g.city, m.city);
      }
      if (Object.keys(next).length) {
        filled.current = { ...filled.current, ...next };
        setValues((x) => {
          const out = { ...x };
          for (const [k, val] of Object.entries(next)) if (!out[k]) out[k] = val;
          return out;
        });
        onLabels?.(labels);
      }
    })();
    return () => {
      live = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loc.fix, groups]);

  if (!groups.length) return null;
  const summary = match ? placeSummary(match, lang) || (loc.fix ? formatCoords(loc.fix) : "") : "";
  const clearFilled = () => {
    const mine = filled.current;
    filled.current = {};
    setMatch(null);
    setValues((x) => {
      const out = { ...x };
      for (const [k, val] of Object.entries(mine)) if (out[k] === val) out[k] = "";
      return out;
    });
  };
  return <LocationHint loc={loc} summary={summary} onClear={clearFilled} />;
}

const s = StyleSheet.create({
  box: { gap: space.x1, paddingVertical: space.x1 },
  row: { flexDirection: "row", alignItems: "center", flexWrap: "wrap", gap: space.x2 },
  text: { ...type.meta, color: colors.navy950, flexShrink: 1 },
  action: { minHeight: 44, flexDirection: "row", alignItems: "center", gap: space.x1, paddingRight: space.x3 },
  clear: { minHeight: 44, flexDirection: "row", alignItems: "center", gap: 2, paddingHorizontal: space.x2, borderRadius: radius.pill },
  link: { ...type.label, color: colors.blue600 },
  muted: { ...type.meta, color: colors.neutral600, textDecorationLine: "underline" },
  setting: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 56 },
  grow: { flex: 1, gap: 2 },
  title: { ...type.label, color: colors.navy950 },
  body: { ...type.meta, color: colors.neutral600 },
});

/**
 * Claim incident details (latitude/longitude): fills the position only when
 * none is stored yet; Clear removes it again.
 */
export function IncidentCoordinates({
  latitude,
  longitude,
  onChange,
}: {
  latitude?: number | null;
  longitude?: number | null;
  onChange: (coords: { latitude: number | null; longitude: number | null }) => void;
}) {
  const { language, t } = useTranslation();
  const lang = language === "fr" ? "fr" : "en";
  const loc = useAutoLocation("claim_incident");
  const empty = latitude == null || longitude == null;
  useEffect(() => {
    if (!loc.fix || !empty) return;
    const lat = Number(loc.fix.latitude.toFixed(6));
    const lng = Number(loc.fix.longitude.toFixed(6));
    onChange({ latitude: lat, longitude: lng });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loc.fix]);
  const stored = !empty ? formatCoords({ latitude: latitude!, longitude: longitude! }) : "";
  const place = loc.fix ? placeSummary(matchPlace(loc.fix.place, { regions: [] }), lang) : "";
  const summary = stored ? [place, t("locGps", { coords: stored })].filter(Boolean).join(" · ") : "";
  const clear = () => {
    onChange({ latitude: null, longitude: null });
  };
  return <LocationHint loc={{ ...loc, status: stored && loc.status !== "loading" ? "ready" : loc.status }} summary={summary} onClear={clear} />;
}

/** Settings → Privacy: global "prefill town and region from my location" switch (device-local, default on). */
export function LocationAutofillSetting() {
  const { t } = useTranslation();
  const [on, setOn] = useState(true);
  useEffect(() => {
    let live = true;
    void LocationPrefs.enabled().then((v) => live && setOn(v));
    return () => {
      live = false;
    };
  }, []);
  return (
    <View style={s.setting}>
      <MapPin size={22} color={colors.blue600} />
      <View style={s.grow}>
        <Text style={s.title}>{t("locSettingTitle")}</Text>
        <Text style={s.body}>{t("locSettingBody")}</Text>
      </View>
      <Switch
        accessibilityLabel={t("locSettingTitle")}
        value={on}
        onValueChange={(v) => {
          setOn(v);
          void LocationPrefs.setEnabled(v);
        }}
        trackColor={{ true: colors.blue600, false: colors.neutral300 }}
        thumbColor={colors.white}
      />
    </View>
  );
}
