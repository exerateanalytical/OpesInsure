import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { Car, CircleAlert, RefreshCw } from "lucide-react-native";
import { Button, Chip, TextField } from "@/components/ui";
import { PickerField } from "@/components/purchase/PurchaseUi";
import { SelectField } from "@/components/forms/SelectField";

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
  suggestionOutcome,
  vehicleSuggestionPayload,
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

/** Every make in the master fits one request; the sheet searches it client-side (name + aliases). */
const MAKES_LIMIT = 200;

type Mode = "form" | "manual";
type LoadState = "idle" | "loading" | "error" | "ready";

/**
 * Make → model → generation → year → engine variant, each a drop-down
 * SelectField (same 52dp shell as every other form field) that opens a
 * searchable bottom sheet. Makes come from the vehicle master (common
 * Cameroon makes first) with a "Chinese makes" filter inside the sheet; the
 * later fields appear only when the master has data for them. "Can't find
 * your vehicle?" opens a manual form that queues a master-data review and
 * continues with the typed text as the snapshot.
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
  const [mode, setMode] = useState<Mode>("form");
  const [make, setMake] = useState<VehicleMake | null>(value?.make_code ? { code: value.make_code, name: value.make, aliases: [] } : null);

  // --- makes ---------------------------------------------------------------
  const [chinese, setChinese] = useState(false);
  const [makes, setMakes] = useState<VehicleMake[]>([]);
  const [makesState, setMakesState] = useState<LoadState>("loading");
  const requestId = useRef(0);

  const loadMakes = useCallback(() => {
    const id = ++requestId.current;
    setMakesState("loading");
    VehiclesApi.makes("", { chinese, limit: MAKES_LIMIT })
      .then((p) => {
        if (id !== requestId.current) return;
        setMakes(normalizeMakes(p));
        setMakesState("ready");
      })
      .catch(() => id === requestId.current && setMakesState("error"));
  }, [chinese]);

  useEffect(() => {
    if (mode === "form") loadMakes();
  }, [mode, loadMakes]);

  // Keep the chosen make listed even when the Chinese filter hides it.
  const makeOptions = useMemo(() => {
    const list = make && !makes.some((m) => m.code === make.code) ? [make, ...makes] : makes;
    return list.map((m) => ({ value: m.code, label: m.name, subtitle: m.aliases.length ? m.aliases.join(" · ") : undefined }));
  }, [makes, make]);

  // --- models --------------------------------------------------------------
  const [models, setModels] = useState<VehicleModel[]>([]);
  const [modelsState, setModelsState] = useState<LoadState>("idle");
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
    if (mode === "form") loadModels();
  }, [mode, loadModels]);

  // --- generation → year → engine variant (CUST-007) -----------------------
  // Each field only appears when the master has data; an empty list or a
  // failed call simply leaves the next field out.
  const [generations, setGenerations] = useState<VehicleGeneration[]>([]);
  const [generationsState, setGenerationsState] = useState<LoadState>("idle");
  const [variants, setVariants] = useState<VehicleVariant[]>([]);
  const [variantsState, setVariantsState] = useState<LoadState>("idle");
  // Hosts may rebuild `value` from flat form values (dropping generation /
  // variant codes), so the picker keeps its own copy of those choices.
  const [generationPick, setGenerationPick] = useState<string | undefined>(value?.generation_code);
  const [variantPick, setVariantPick] = useState<string | undefined>(value?.variant_code);
  const modelCode = value?.model_code;
  const generation = useMemo(() => generations.find((g) => g.code === generationPick) ?? null, [generations, generationPick]);

  useEffect(() => {
    setGenerations([]);
    if (!modelCode) {
      setGenerationsState("idle");
      return;
    }
    let live = true;
    setGenerationsState("loading");
    VehiclesApi.generations(modelCode)
      .then((p) => {
        if (!live) return;
        setGenerations(normalizeGenerations(p));
        setGenerationsState("ready");
      })
      .catch(() => live && setGenerationsState("error"));
    return () => {
      live = false;
    };
  }, [modelCode]);

  const generationCode = generation?.code;
  const year = value?.year;
  useEffect(() => {
    setVariants([]);
    if (!modelCode || !generationCode || !year) {
      setVariantsState("idle");
      return;
    }
    let live = true;
    setVariantsState("loading");
    VehiclesApi.variants(modelCode, generationCode, year)
      .then((p) => {
        if (!live) return;
        setVariants(normalizeVariants(p));
        setVariantsState("ready");
      })
      .catch(() => live && setVariantsState("error"));
    return () => {
      live = false;
    };
  }, [modelCode, generationCode, year]);

  // --- manual --------------------------------------------------------------
  const [entry, setEntry] = useState<ManualVehicleEntry>(emptyManualEntry());
  const [entryErrors, setEntryErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const years = useMemo(() => modelYears(reference?.model_years).map((y) => ({ value: y, label: y })), [reference]);

  const openManual = () => {
    setEntry({ ...emptyManualEntry(), make: make?.name ?? "", model: "", year: value?.year ?? "" });
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
    let outcome: ReturnType<typeof suggestionOutcome> = {};
    try {
      // Canonical intake: POST /master-data/suggestions {domain: "vehicle", list, text, parent, attributes}; data.review.id is kept.
      outcome = suggestionOutcome(await VehiclesApi.suggest(vehicleSuggestionPayload(entry, riskAssetId)));
      setNotice(t("vehicleManualQueued", { name: `${entry.make} ${entry.model}` }));
    } catch {
      setNotice(t("vehicleManualNotSent"));
    } finally {
      setSubmitting(false);
    }
    onChange(selectionFromManual(entry, outcome.reviewId, outcome));
    setMode("form");
  };

  const pickMake = (m: VehicleMake) => {
    if (m.code === make?.code) return;
    setMake(m);
    setGenerationPick(undefined);
    setVariantPick(undefined);
    setModels([]);
    setNotice(null);
    onChange({ make_code: m.code, make: m.name, model: "", year: value?.year });
  };

  const pickModel = (m: VehicleModel) => {
    if (!make) return;
    onChange({
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
    });
    setGenerationPick(undefined);
    setVariantPick(undefined);
  };

  const pickGeneration = (g: VehicleGeneration) => {
    if (!value) return;
    setGenerationPick(g.code);
    setVariantPick(undefined);
    onChange({ ...value, generation_code: g.code, generation: g.name, body_type: g.body_type ?? value.body_type, variant_code: undefined, variant: undefined });
  };

  const pickYear = (y: string) => {
    if (!value) return;
    setVariantPick(undefined);
    onChange({ ...value, year: y, variant_code: undefined, variant: undefined });
  };

  const pickVariant = (v: VehicleVariant) => {
    if (!value) return;
    setVariantPick(v.code);
    onChange(applyVariant(value, v));
  };

  const reset = () => {
    setMake(null);
    setModels([]);
    setModelsState("idle");
    setNotice(null);
    onChange(null);
    setMode("form");
  };

  const manualLink = (
    <View style={st.manualRow}>
      <Text style={st.meta}>{t("vehicleCantFind")}</Text>
      <Pressable accessibilityRole="button" onPress={openManual} hitSlop={8}>
        <Text style={st.link}>{t("vehicleAddManually")}</Text>
      </Pressable>
    </View>
  );

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
        <Button label={t("vehicleBackToList")} variant="tertiary" disabled={submitting} onPress={() => setMode("form")} />
      </View>
    );
  }

  // A manually typed vehicle has no master codes: show it as a summary card.
  if (value?.manual && value.make && !value.make_code) {
    return (
      <View style={st.wrap}>
        <Text style={st.label}>{`${t("vehicleMake")} · ${t("vehicleModel")}`}</Text>
        <View style={[st.selected, error ? st.errorBorder : null]}>
          <Car size={20} color={colors.blue600} />
          <View style={st.flex}>
            <Text style={st.rowTitle}>{selectionLabel({ make: value.make, model: value.model })}</Text>
            <Text style={st.pending}>{t("vehiclePendingReview")}</Text>
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

  const hasMake = !!make;
  const hasModel = !!modelCode;
  const showGeneration = hasModel && (generationsState === "loading" || generations.length > 0);
  const genYears = generation ? generationYears(generation, reference?.model_years) : [];
  const showVariant = !!generation && !!year && (variantsState === "loading" || variants.length > 0);
  const specs = value?.variant
    ? [value.power_hp ? `${value.power_hp} hp` : null, value.engine_capacity_cc ? `${value.engine_capacity_cc} cc` : null, value.powertrain, value.transmission].filter(Boolean).join(" · ")
    : "";

  return (
    <View style={st.wrap}>
      {makesState === "error" ? (
        <InlineError label={t("vehicleLoadError")} retry={t("retry")} onRetry={loadMakes} />
      ) : (
        <SelectField
          label={t("vehicleMake")}
          value={make?.code}
          placeholder={t("vehicleSearchMake")}
          options={makeOptions}
          loading={makesState === "loading" && !makes.length}
          hint={
            makesState === "loading"
              ? t("vehicleLoadingMakes")
              : makesState === "ready" && !makes.length
                ? t("vehicleNoMakes", { query: chinese ? t("vehicleChineseChip") : "" })
                : !hasMake && !error
                  ? t("vehicleCommonMakes")
                  : undefined
          }
          error={!hasMake ? error : undefined}
          onChange={(code) => {
            const m = makes.find((x) => x.code === code) ?? (make?.code === code ? make : null);
            if (m) pickMake(m);
          }}
          sheetHeader={
            <View style={st.chips}>
              <Chip label={t("vehicleChineseChip")} selected={chinese} onPress={() => setChinese((c) => !c)} />
            </View>
          }
        />
      )}
      {modelsState === "error" ? (
        <InlineError label={t("vehicleLoadError")} retry={t("retry")} onRetry={loadModels} />
      ) : (
        <SelectField
          label={t("vehicleModel")}
          value={value?.model_code}
          options={models.map((m) => ({ value: m.code, label: m.name, subtitle: m.aliases.length ? m.aliases.join(" · ") : undefined }))}
          disabled={!hasMake || (modelsState === "ready" && !models.length)}
          loading={hasMake && modelsState === "loading"}
          hint={!hasMake ? t("vehicleChooseMakeFirst") : modelsState === "ready" && !models.length ? t("vehicleNoModels") : undefined}
          error={hasMake && !hasModel ? error : undefined}
          onChange={(code) => {
            const m = models.find((x) => x.code === code);
            if (m) pickModel(m);
          }}
        />
      )}
      {showGeneration ? (
        <SelectField
          label={t("vehicleGeneration")}
          value={generationPick}
          loading={generationsState === "loading"}
          options={generations.map((g) => ({ value: g.code, label: g.name, subtitle: g.year_from ? `${g.year_from}–${g.year_to ?? t("vehicleGenerationNow")}` : undefined }))}
          onChange={(code) => {
            const g = generations.find((x) => x.code === code);
            if (g) pickGeneration(g);
          }}
        />
      ) : null}
      {generation && genYears.length ? (
        <SelectField label={t("vehicleYear")} value={year} options={genYears.map((y) => ({ value: y, label: y }))} onChange={pickYear} />
      ) : null}
      {showVariant ? (
        <SelectField
          label={t("vehicleEngineVariant")}
          value={variantPick}
          loading={variantsState === "loading"}
          hint={t("vehicleVariantHint")}
          options={variants.map((v) => ({ value: v.code, label: v.name, subtitle: variantSummary(v) || undefined }))}
          onChange={(code) => {
            const v = variants.find((x) => x.code === code);
            if (v) pickVariant(v);
          }}
        />
      ) : null}
      {specs ? <Text style={st.meta}>{`${t("vehicleSpecsAutoFilled")} ${specs}`}</Text> : null}
      {notice ? <Text style={st.meta}>{notice}</Text> : null}
      {manualLink}
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
  rowTitle: { ...type.label, color: colors.navy950 },
  selected: { minHeight: 52, flexDirection: "row", alignItems: "center", gap: space.x3, paddingHorizontal: space.x3, borderWidth: 1, borderColor: colors.blue600, backgroundColor: colors.blue50, borderRadius: radius.control },
  errorBorder: { borderColor: colors.danger },
  manualRow: { flexDirection: "row", flexWrap: "wrap", alignItems: "center", gap: space.x2, paddingTop: space.x1 },
  inline: { flexDirection: "row", alignItems: "center", gap: space.x2, paddingVertical: space.x2 },
  retry: { flexDirection: "row", alignItems: "center", gap: space.x1 },
});
