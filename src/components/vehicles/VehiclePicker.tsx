import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from "react-native";
import { Car, Check, ChevronRight, CircleAlert, RefreshCw } from "lucide-react-native";
import { Button, TextField } from "@/components/ui";
import { PickerField, Pill } from "@/components/purchase/PurchaseUi";
import { VehiclesApi } from "@/api/client";
import { useTranslation } from "@/i18n";
import { useSession } from "@/store/session";
import {
  applyVariant,
  emptyManualEntry,
  generationYears,
  normalizeGenerations,
  normalizeVariants,
  variantSummary,
  VehicleGeneration,
  VehicleVariant,
  filterByQuery,
  manualReviewPayload,
  ManualVehicleEntry,
  modelYears,
  normalizeMakes,
  normalizeModels,
  normalizeReference,
  selectionFromManual,
  selectionLabel,
  validateManualEntry,
  VehicleMake,
  VehicleModel,
  VehicleReference,
  VehicleSelection,
} from "@/lib/vehicles";
import { colors, radius, space, type } from "@/theme/tokens";

let referenceCache: { payload: unknown } | null = null;

/** Vehicle reference enums (EN/FR labels, model-year range), fetched once per app session. */
export function useVehicleReference() {
  const language = useSession((s) => s.language);
  const [payload, setPayload] = useState<unknown>(referenceCache?.payload ?? null);
  const [error, setError] = useState(false);
  const load = useCallback(() => {
    setError(false);
    VehiclesApi.reference()
      .then((p) => {
        referenceCache = { payload: p };
        setPayload(p);
      })
      .catch(() => setError(true));
  }, []);
  useEffect(() => {
    if (!referenceCache) load();
  }, [load]);
  const reference = useMemo<VehicleReference | null>(() => (payload ? normalizeReference(payload, language === "fr" ? "fr" : "en") : null), [payload, language]);
  return { reference, error, retry: load };
}

const toOptions = (rows: { code: string; label: string }[] | undefined) => (rows ?? []).map((r) => ({ value: r.code, label: r.label }));

type Mode = "make" | "model" | "generation" | "year" | "variant" | "done" | "manual";

/**
 * Make → model picker backed by the vehicle master: searchable make
 * autocomplete (server alias matching, common Cameroon makes first, optional
 * "Chinese makes" chip), model list for the make with search, and a
 * "Can't find your vehicle?" manual form that queues a master-data review
 * and continues with the typed text as the snapshot.
 */
export function VehiclePicker({
  value,
  onChange,
  error,
  riskAssetId,
}: {
  value: VehicleSelection | null;
  onChange: (selection: VehicleSelection | null) => void;
  error?: string;
  riskAssetId?: string | null;
}) {
  const { t } = useTranslation();
  const { reference } = useVehicleReference();
  const [mode, setMode] = useState<Mode>(value?.make ? (value.model ? "done" : "model") : "make");
  const [make, setMake] = useState<VehicleMake | null>(value?.make_code ? { code: value.make_code, name: value.make, aliases: [] } : null);

  // --- makes ---------------------------------------------------------------
  const [query, setQuery] = useState("");
  const [chinese, setChinese] = useState(false);
  const [makes, setMakes] = useState<VehicleMake[]>([]);
  const [makesState, setMakesState] = useState<"loading" | "error" | "ready">("loading");
  const requestId = useRef(0);

  const loadMakes = useCallback(() => {
    const id = ++requestId.current;
    setMakesState("loading");
    VehiclesApi.makes(query, { chinese, limit: query.trim() ? 20 : 26 })
      .then((p) => {
        if (id !== requestId.current) return;
        setMakes(normalizeMakes(p));
        setMakesState("ready");
      })
      .catch(() => id === requestId.current && setMakesState("error"));
  }, [query, chinese]);

  useEffect(() => {
    if (mode !== "make") return;
    const timer = setTimeout(loadMakes, query.trim() ? 250 : 0);
    return () => clearTimeout(timer);
  }, [mode, loadMakes, query]);

  // --- models --------------------------------------------------------------
  const [models, setModels] = useState<VehicleModel[]>([]);
  const [modelsState, setModelsState] = useState<"loading" | "error" | "ready">("loading");
  const [modelQuery, setModelQuery] = useState("");
  const loadModels = useCallback(() => {
    if (!make?.code) return;
    setModelsState("loading");
    VehiclesApi.models(make.code)
      .then((p) => {
        setModels(normalizeModels(p));
        setModelsState("ready");
      })
      .catch(() => setModelsState("error"));
  }, [make?.code]);
  useEffect(() => {
    if (mode === "model") loadModels();
  }, [mode, loadModels]);
  const visibleModels = useMemo(() => filterByQuery(models, modelQuery), [models, modelQuery]);

  // --- manual --------------------------------------------------------------
  const [entry, setEntry] = useState<ManualVehicleEntry>(emptyManualEntry());
  const [entryErrors, setEntryErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const years = useMemo(() => modelYears(reference?.model_years).map((y) => ({ value: y, label: y })), [reference]);

  const openManual = () => {
    setEntry({ ...emptyManualEntry(), make: make?.name ?? query.trim(), model: mode === "model" ? modelQuery.trim() : "", year: value?.year ?? "" });
    setEntryErrors({});
    setMode("manual");
  };

  const submitManual = async () => {
    const range = reference?.model_years;
    const errs = validateManualEntry(entry, range);
    const messages: Record<string, string> = {};
    for (const [k, key] of Object.entries(errs)) messages[k] = t(key, { min: range?.min ?? 1950, max: range?.max ?? new Date().getFullYear() + 1 });
    setEntryErrors(messages);
    if (Object.keys(messages).length) return;
    setSubmitting(true);
    let reviewId: string | undefined;
    try {
      const res = await VehiclesApi.submitReview(manualReviewPayload(entry, riskAssetId));
      reviewId = res?.id;
      setNotice(t("vehicleManualQueued", { name: `${entry.make} ${entry.model}` }));
    } catch {
      setNotice(t("vehicleManualNotSent"));
    } finally {
      setSubmitting(false);
    }
    onChange(selectionFromManual(entry, reviewId));
    setMode("done");
  };

  const pickMake = (m: VehicleMake) => {
    setMake(m);
    setModels([]);
    setModelQuery("");
    setNotice(null);
    onChange({ make_code: m.code, make: m.name, model: "", year: value?.year });
    setMode("model");
  };

  // --- generation → year → engine variant (CUST-007) -----------------------
  // Each step only appears when the master has data; an empty list or a
  // failed call skips straight to the next step / done.
  const [generations, setGenerations] = useState<VehicleGeneration[]>([]);
  const [generation, setGeneration] = useState<VehicleGeneration | null>(null);
  const [variants, setVariants] = useState<VehicleVariant[]>([]);
  const [stepLoading, setStepLoading] = useState(false);

  const pickModel = (m: VehicleModel) => {
    if (!make) return;
    const next: VehicleSelection = {
      ...(value ?? { make: make.name, model: "" }),
      make_code: make.code,
      make: make.name,
      model_code: m.code,
      model: m.name,
      manual: false,
      review_id: undefined,
      generation_code: undefined,
      generation: undefined,
      variant_code: undefined,
      variant: undefined,
    };
    onChange(next);
    setGeneration(null);
    setStepLoading(true);
    VehiclesApi.generations(m.code)
      .then((p) => {
        const list = normalizeGenerations(p);
        setGenerations(list);
        setMode(list.length ? "generation" : "done");
      })
      .catch(() => setMode("done"))
      .finally(() => setStepLoading(false));
  };

  const pickGeneration = (g: VehicleGeneration) => {
    if (!value) return;
    setGeneration(g);
    onChange({ ...value, generation_code: g.code, generation: g.name, body_type: g.body_type ?? value.body_type, variant_code: undefined, variant: undefined });
    setMode("year");
  };

  const pickYear = (year: string) => {
    if (!value || !generation || !value.model_code) return;
    const withYear = { ...value, year };
    onChange(withYear);
    setStepLoading(true);
    VehiclesApi.variants(value.model_code, generation.code, year)
      .then((p) => {
        const list = normalizeVariants(p);
        setVariants(list);
        setMode(list.length ? "variant" : "done");
      })
      .catch(() => setMode("done"))
      .finally(() => setStepLoading(false));
  };

  const pickVariant = (v: VehicleVariant) => {
    if (!value) return;
    onChange(applyVariant(value, v));
    setMode("done");
  };

  const reset = () => {
    setMake(null);
    setNotice(null);
    onChange(null);
    setMode("make");
  };

  const manualLink = (
    <View style={st.manualRow}>
      <Text style={st.meta}>{t("vehicleCantFind")}</Text>
      <Pressable accessibilityRole="button" onPress={openManual} hitSlop={8}>
        <Text style={st.link}>{t("vehicleAddManually")}</Text>
      </Pressable>
    </View>
  );

  if (stepLoading) return <View style={st.wrap}><Inline label={t("vehicleLoadingModels")} /></View>;

  if ((mode === "generation" || mode === "year" || mode === "variant") && value?.make) {
    const header = (
      <View style={st.selected}>
        <Car size={20} color={colors.blue600} />
        <Text style={[st.rowTitle, st.flex]}>{selectionLabel(value)}</Text>
        <Pressable accessibilityRole="button" onPress={reset} hitSlop={8}>
          <Text style={st.link}>{t("vehicleChange")}</Text>
        </Pressable>
      </View>
    );
    const skip = (
      <Button label={t("vehicleSkipStep")} variant="tertiary" onPress={() => setMode("done")} />
    );
    if (mode === "generation")
      return (
        <View style={st.wrap}>
          <Text style={st.label}>{t("vehicleGeneration")}</Text>
          {header}
          <View style={st.list}>
            {generations.map((g) => (
              <Row
                key={g.code}
                title={g.name}
                subtitle={g.year_from ? `${g.year_from}–${g.year_to ?? t("vehicleGenerationNow")}` : undefined}
                selected={value.generation_code === g.code}
                onPress={() => pickGeneration(g)}
              />
            ))}
          </View>
          {skip}
        </View>
      );
    if (mode === "year" && generation) {
      const genYears = generationYears(generation, reference?.model_years);
      return (
        <View style={st.wrap}>
          <Text style={st.label}>{t("vehicleYear")}</Text>
          {header}
          <View style={st.years}>
            {genYears.map((y) => (
              <Pill key={y} label={y} selected={value.year === y} onPress={() => pickYear(y)} />
            ))}
          </View>
          {skip}
        </View>
      );
    }
    if (mode === "variant")
      return (
        <View style={st.wrap}>
          <Text style={st.label}>{t("vehicleEngineVariant")}</Text>
          {header}
          <Text style={st.meta}>{t("vehicleVariantHint")}</Text>
          <View style={st.list}>
            {variants.map((v) => (
              <Row key={v.code} title={v.name} subtitle={variantSummary(v) || undefined} selected={value.variant_code === v.code} onPress={() => pickVariant(v)} />
            ))}
          </View>
          {skip}
        </View>
      );
  }

  if (mode === "done" && value?.make) {
    return (
      <View style={st.wrap}>
        <Text style={st.label}>{`${t("vehicleMake")} · ${t("vehicleModel")}`}</Text>
        <View style={[st.selected, error ? st.errorBorder : null]}>
          <Car size={20} color={colors.blue600} />
          <View style={st.flex}>
            <Text style={st.rowTitle}>{selectionLabel({ make: value.make, model: value.model, generation: value.generation, variant: value.variant })}</Text>
            {value.variant && (value.power_hp || value.engine_capacity_cc || value.powertrain) ? (
              <Text style={st.meta}>
                {t("vehicleSpecsAutoFilled")}{" "}
                {[value.power_hp ? `${value.power_hp} hp` : null, value.engine_capacity_cc ? `${value.engine_capacity_cc} cc` : null, value.powertrain, value.transmission].filter(Boolean).join(" · ")}
              </Text>
            ) : null}
            {value.manual ? <Text style={st.pending}>{t("vehiclePendingReview")}</Text> : null}
          </View>
          <Pressable accessibilityRole="button" onPress={reset} hitSlop={8}>
            <Text style={st.link}>{t("vehicleChange")}</Text>
          </Pressable>
        </View>
        {notice ? <Text style={st.meta}>{notice}</Text> : null}
        {error ? <Text accessibilityRole="alert" style={st.error}>{error}</Text> : null}
      </View>
    );
  }

  if (mode === "manual") {
    const set = (k: keyof ManualVehicleEntry) => (v: string) => {
      setEntry((e) => ({ ...e, [k]: v }));
      if (entryErrors[k]) setEntryErrors((x) => ({ ...x, [k]: "" }));
    };
    return (
      <View style={st.wrap}>
        <Text style={st.title}>{t("vehicleManualTitle")}</Text>
        <Text style={st.meta}>{t("vehicleManualBody")}</Text>
        <TextField label={t("vehicleMake")} value={entry.make} onChangeText={set("make")} error={entryErrors.make || undefined} autoCapitalize="words" />
        <TextField label={t("vehicleModel")} value={entry.model} onChangeText={set("model")} error={entryErrors.model || undefined} autoCapitalize="words" />
        <PickerField label={t("vehicleYear")} value={entry.year || undefined} options={years} onChange={set("year")} error={entryErrors.year || undefined} />
        <PickerField label={t("vehicleBodyType")} value={entry.body_type || undefined} options={toOptions(reference?.body_types)} onChange={set("body_type")} />
        <PickerField label={t("vehicleFuel")} value={entry.powertrain || undefined} options={toOptions(reference?.powertrains)} onChange={set("powertrain")} />
        <PickerField label={t("vehicleUsage")} value={entry.vehicle_usage || undefined} options={toOptions(reference?.usage_types)} onChange={set("vehicle_usage")} />
        <TextField label={t("vehicleVin")} value={entry.vin} onChangeText={set("vin")} error={entryErrors.vin || undefined} autoCapitalize="characters" />
        <TextField label={t("vehicleRegistration")} value={entry.registration_number} onChangeText={set("registration_number")} error={entryErrors.registration_number || undefined} autoCapitalize="characters" placeholder="LT 000 AA" />
        <TextField label={t("vehicleEngineNumber")} value={entry.engine_number} onChangeText={set("engine_number")} error={entryErrors.engine_number || undefined} autoCapitalize="characters" />
        <Button label={t("vehicleManualSubmit")} loading={submitting} onPress={() => void submitManual()} />
        <Button label={t("vehicleBackToList")} variant="tertiary" disabled={submitting} onPress={() => setMode(make ? "model" : "make")} />
      </View>
    );
  }

  if (mode === "model" && make) {
    return (
      <View style={st.wrap}>
        <Text style={st.label}>{t("vehicleModel")}</Text>
        <View style={st.selected}>
          <Car size={20} color={colors.blue600} />
          <Text style={[st.rowTitle, st.flex]}>{make.name}</Text>
          <Pressable accessibilityRole="button" onPress={reset} hitSlop={8}>
            <Text style={st.link}>{t("vehicleChange")}</Text>
          </Pressable>
        </View>
        <TextField label={t("vehicleSearchModel")} value={modelQuery} onChangeText={setModelQuery} autoCorrect={false} />
        {modelsState === "loading" ? (
          <Inline label={t("vehicleLoadingModels")} />
        ) : modelsState === "error" ? (
          <InlineError label={t("vehicleLoadError")} retry={t("retry")} onRetry={loadModels} />
        ) : visibleModels.length ? (
          <View style={st.list}>
            {visibleModels.map((m) => (
              <Row key={m.code} title={m.name} subtitle={m.aliases.length ? m.aliases.join(" · ") : undefined} selected={value?.model_code === m.code} onPress={() => pickModel(m)} />
            ))}
          </View>
        ) : (
          <Text style={st.meta}>{t("vehicleNoModels")}</Text>
        )}
        {error ? <Text accessibilityRole="alert" style={st.error}>{error}</Text> : null}
        {manualLink}
      </View>
    );
  }

  return (
    <View style={st.wrap}>
      <TextField label={t("vehicleMake")} value={query} onChangeText={setQuery} placeholder={t("vehicleSearchMake")} autoCorrect={false} autoCapitalize="words" error={error} />
      <View style={st.chips}>
        <Pill label={t("vehicleChineseChip")} selected={chinese} onPress={() => setChinese((c) => !c)} />
      </View>
      {!query.trim() && !chinese ? <Text style={st.meta}>{t("vehicleCommonMakes")}</Text> : null}
      {makesState === "loading" && !makes.length ? (
        <Inline label={t("vehicleLoadingMakes")} />
      ) : makesState === "error" ? (
        <InlineError label={t("vehicleLoadError")} retry={t("retry")} onRetry={loadMakes} />
      ) : makes.length ? (
        <View style={st.list}>
          {makes.map((m) => (
            <Row key={m.code} title={m.name} subtitle={m.aliases.length ? m.aliases.join(" · ") : undefined} onPress={() => pickMake(m)} />
          ))}
        </View>
      ) : (
        <Text style={st.meta}>{t("vehicleNoMakes", { query: query.trim() })}</Text>
      )}
      {manualLink}
    </View>
  );
}

function Row({ title, subtitle, selected, onPress }: { title: string; subtitle?: string; selected?: boolean; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" accessibilityState={{ selected: !!selected }} style={[st.row, selected && st.rowOn]} onPress={onPress}>
      <View style={st.flex}>
        <Text style={st.rowTitle}>{title}</Text>
        {subtitle ? <Text style={st.meta}>{subtitle}</Text> : null}
      </View>
      {selected ? <Check size={18} color={colors.blue600} /> : <ChevronRight size={18} color={colors.neutral500} />}
    </Pressable>
  );
}

function Inline({ label }: { label: string }) {
  return (
    <View accessibilityRole="progressbar" accessibilityLabel={label} style={st.inline}>
      <ActivityIndicator color={colors.blue600} />
      <Text style={st.meta}>{label}</Text>
    </View>
  );
}

function InlineError({ label, retry, onRetry }: { label: string; retry: string; onRetry: () => void }) {
  return (
    <View style={st.inline}>
      <CircleAlert size={18} color={colors.dangerText} />
      <Text accessibilityRole="alert" style={[st.meta, st.flex]}>{label}</Text>
      <Pressable accessibilityRole="button" onPress={onRetry} hitSlop={8} style={st.retry}>
        <RefreshCw size={16} color={colors.blue600} />
        <Text style={st.link}>{retry}</Text>
      </Pressable>
    </View>
  );
}

const st = StyleSheet.create({
  wrap: { gap: space.x2 },
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  label: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  pending: { ...type.caption, color: colors.dangerText },
  link: { ...type.label, color: colors.blue600 },
  error: { ...type.meta, color: colors.dangerText },
  chips: { flexDirection: "row", gap: space.x2 },
  years: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  list: { gap: space.x1 },
  row: { minHeight: 48, flexDirection: "row", alignItems: "center", gap: space.x2, paddingHorizontal: space.x3, paddingVertical: space.x2, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control },
  rowOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  rowTitle: { ...type.label, color: colors.navy950 },
  selected: { minHeight: 52, flexDirection: "row", alignItems: "center", gap: space.x3, paddingHorizontal: space.x3, borderWidth: 1, borderColor: colors.blue600, backgroundColor: colors.blue50, borderRadius: radius.control },
  errorBorder: { borderColor: colors.danger },
  manualRow: { flexDirection: "row", flexWrap: "wrap", alignItems: "center", gap: space.x2, paddingTop: space.x1 },
  inline: { flexDirection: "row", alignItems: "center", gap: space.x2, paddingVertical: space.x2 },
  retry: { flexDirection: "row", alignItems: "center", gap: space.x1 },
});
