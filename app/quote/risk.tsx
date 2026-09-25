import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Car, Check, UserRound } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { DateField, ErrorCard, Stepper, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { AssetsApi, CatalogueApi, RiskAsset } from "@/api/client";
import { RiskAssetTypesApi } from "@/api/crm";
import { assetTypesForLine } from "@/lib/crm";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { unwrapPage } from "@/lib/purchase";
import { allFields, buildFacts, clearedDependents, isFieldVisible, isValidIsoDate, localRiskSchema, normalizeRiskSchema, RiskField, RiskSchema, validateStep } from "@/lib/riskSchema";
import { useVehicleReference } from "@/components/vehicles/VehiclePicker";
import { ContractField } from "@/components/forms/ContractField";
import { MasterSelectField } from "@/components/masterData/MasterSelectField";
import { selectionToValues, VehicleReference, VehicleSelection } from "@/lib/vehicles";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";


function prefillFromAsset(asset: RiskAsset): Record<string, string> {
  const out: Record<string, string> = {};
  if (asset.registration_number) out.registration_number = asset.registration_number;
  if (asset.make) out.make = asset.make;
  if (asset.model) out.model = asset.model;
  if (asset.year) out.year = String(asset.year);
  return out;
}

export default function Risk() {
  const product = useInsurance((s) => s.product);
  const busy = useInsurance((s) => s.busy);
  const storeError = useInsurance((s) => s.error);
  const setFacts = useInsurance((s) => s.setRiskFacts);
  const setRiskAsset = useInsurance((s) => s.setRiskAsset);
  const riskAssetId = useInsurance((s) => s.riskAssetId);
  const insured = useInsurance((s) => s.insured);
  const setInsured = useInsurance((s) => s.setInsured);
  const submit = useInsurance((s) => s.submitQuote);
  const customerId = useSession((s) => s.activeWorkspace?.customer_id);
  const line = (product ?? "").toUpperCase();

  const [schema, setSchema] = useState<RiskSchema | null>(null);
  const [schemaLoading, setSchemaLoading] = useState(true);
  const [assets, setAssets] = useState<RiskAsset[]>([]);
  const [assetType, setAssetType] = useState<string | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [step, setStep] = useState(0);
  const [submitError, setSubmitError] = useState<unknown>(null);
  const { reference } = useVehicleReference();

  const loadSchema = useCallback(async () => {
    if (!line) return setSchemaLoading(false);
    setSchemaLoading(true);
    let next: RiskSchema | null = null;
    try {
      next = normalizeRiskSchema(await CatalogueApi.riskSchema(line), line);
    } catch {
      // Endpoint absent (404) or unreachable: the local schema keeps the flow usable.
    }
    setSchema(next ?? localRiskSchema(line));
    setSchemaLoading(false);
  }, [line]);

  useEffect(() => {
    void loadSchema();
    // Insurable object types per line come from GET /risk-asset-types (REQ-RSK-001).
    void RiskAssetTypesApi.list()
      .catch(() => [])
      .then(async (types) => {
        const codes = assetTypesForLine(types, line);
        const own = types.find((x) => x.line_code === line);
        setAssetType(own?.code ?? (line === "MOTOR" ? "VEHICLE" : null));
        if (!codes.length) return setAssets([]);
        const x = await AssetsApi.list();
        setAssets(unwrapPage<RiskAsset>(x).items.filter((a) => codes.includes(String(a.type).toUpperCase()) || (line === "MOTOR" && !!a.registration_number)));
      })
      .catch(() => setAssets([]));
  }, [line, loadSchema]);

  // Step 0 is "who / what is insured"; schema steps follow.
  const { t, language } = useTranslation();
  const stepTitle = (s?: { title: string; titleFr?: string }) => (s ? (language === "fr" && s.titleFr ? s.titleFr : s.title) : undefined);
  const steps = useMemo(() => [t("qtInsured"), ...(schema?.steps.map((s) => (language === "fr" && s.titleFr ? s.titleFr : s.title)) ?? [])], [schema, language, t]);
  const current = step > 0 ? schema?.steps[step - 1] : undefined;
  const isLast = step === steps.length - 1;

  const insuredError =
    insured.mode === "other" && (!insured.full_name.trim() || !insured.relationship || !isValidIsoDate(insured.date_of_birth))
      ? t("qtInsuredMissing")
      : null;

  const next = async () => {
    if (step === 0) {
      if (insuredError) return setErrors({ insured: insuredError });
      setErrors({});
      return setStep(1);
    }
    if (!current || !schema) return;
    const e = validateStep(current, values, language === "fr" ? "fr" : "en");
    setErrors(e);
    if (Object.keys(e).length) return;
    if (!isLast) return setStep(step + 1);
    if (!customerId) return;
    setFacts(buildFacts(schema, values));
    setSubmitError(null);
    try {
      const result = await submit(customerId);
      const status = String(result.quote.status).toUpperCase();
      if (status === "REFERRED" || (!result.offers.length && status !== "OFFERED"))
        router.replace({ pathname: "/quote/referral", params: { quoteId: result.quote.id } });
      else router.push("/quote/offers");
    } catch (e) {
      setSubmitError(e);
    }
  };

  const setAny = (key: string, v: string) => setValues((x) => ({ ...x, [key]: v }));
  const setValue = (key: string, v: string) => {
    // A new parent (region, category, …) clears its children.
    setValues((x) => ({ ...x, [key]: v, ...(x[key] !== v && schema ? clearedDependents(allFields(schema), key) : {}) }));
    if (errors[key]) setErrors((x) => ({ ...x, [key]: "" }));
  };

  if (!line || (!schemaLoading && !schema))
    return (
      <Screen>
        <AppHeader title={t("qtCoverDetails")} back />
        <Card>
          <Text style={ps.title}>{t("qtChooseSupported")}</Text>
        </Card>
        <Button label={t("qtChooseProduct")} onPress={() => router.replace("/quote/product")} />
      </Screen>
    );

  return (
    <Screen>
      <AppHeader title={stepTitle(current) ?? t("qtWhoInsured")} subtitle={t(schema?.source === "server" ? "qtStep2Server" : "qtStep2Local")} back />
      {schemaLoading ? (
        <LoadingState label={t("qtLoadingQuestions")} />
      ) : (
        <>
          <Stepper steps={steps} current={step} />
          {step === 0 ? (
            <>
              <Card>
                <Text style={ps.title}>{t("qtWhoCover")}</Text>
                <View style={st.choiceRow}>
                  {(["self", "other"] as const).map((mode) => (
                    <Pressable
                      key={mode}
                      accessibilityRole="radio"
                      accessibilityState={{ selected: insured.mode === mode }}
                      style={[st.choice, insured.mode === mode && st.choiceOn]}
                      onPress={() => setInsured(mode === "self" ? { mode: "self" } : { mode: "other", full_name: "", date_of_birth: "", relationship: "" })}
                    >
                      <UserRound size={18} color={colors.blue600} />
                      <Text style={st.choiceText}>{mode === "self" ? t("qtMe") : t("qtSomeoneElse")}</Text>
                    </Pressable>
                  ))}
                </View>
                {insured.mode === "other" ? (
                  <>
                    <TextField label={t("fullName")} value={insured.full_name} onChangeText={(full_name) => setInsured({ ...insured, full_name })} />
                    <MasterSelectField label={t("qtRelationshipToYou")} required domain="persons" list="relationship" otherAllowed={false} value={insured.relationship || undefined} onChange={(relationship) => setInsured({ ...insured, relationship })} />
                    <DateField label={t("dateOfBirth")} value={insured.date_of_birth} onChange={(date_of_birth) => setInsured({ ...insured, date_of_birth })} maxYear={new Date().getFullYear()} />
                  </>
                ) : null}
                {errors.insured ? <Text style={ps.error}>{errors.insured}</Text> : null}
              </Card>
              {assets.length ? (
                <Card>
                  <Text style={ps.title}>{t(line === "MOTOR" ? "qtUseSavedVehicle" : line === "HOME" ? "qtUseSavedProperty" : "qtUseSavedObject")}</Text>
                  <Text style={ps.meta}>{t("qtAssetPrefilled")}</Text>
                  {assets.map((a) => {
                    const on = riskAssetId === a.id;
                    return (
                      <Pressable
                        key={a.id}
                        accessibilityRole="radio"
                        accessibilityState={{ selected: on }}
                        style={[st.asset, on && st.choiceOn]}
                        onPress={() => {
                          setRiskAsset(on ? null : a.id);
                          if (!on) setValues((v) => ({ ...v, ...prefillFromAsset(a) }));
                        }}
                      >
                        <Car size={18} color={colors.blue600} />
                        <View style={st.flex}>
                          <Text style={st.choiceText}>{a.label || a.registration_number || t("qtSavedAsset")}</Text>
                          {a.registration_number ? <Text style={ps.meta}>{a.registration_number}</Text> : null}
                        </View>
                        {on ? <Check size={18} color={colors.blue600} /> : null}
                      </Pressable>
                    );
                  })}
                  <Button label={t("qtAddNew")} variant="tertiary" onPress={() => router.push(assetType ? { pathname: "/assets/new", params: { type: assetType } } : "/assets/new")} />
                </Card>
              ) : null}
            </>
          ) : current ? (
            <Card>
              {current.fields.map((f) => (
                isFieldVisible(f, values) ? (
                  <ContractField
                    key={f.key}
                    field={withReferenceOptions(f, reference)}
                    value={values[f.key]}
                    values={values}
                    error={(f.type === "vehicle_make" ? errors[f.key] || errors.model_code : errors[f.key]) || undefined}
                    riskAssetId={riskAssetId}
                    vehicle={selectionFromValues(values)}
                    onChange={(v) => setValue(f.key, v)}
                    setAny={setAny}
                    lineCode={line}
                    screen="quote.risk"
                    onVehicle={(sel) => {
                      // Clear the previous vehicle's keys, then apply the new selection (codes + snapshot text).
                      setValues((x) => ({ ...x, make_code: "", model_code: "", make: "", model: "", vehicle_review_id: "", vehicle_generation_code: "", vehicle_variant_code: "", ...(sel ? selectionToValues(sel) : {}) }));
                      setErrors((x) => ({ ...x, make_code: "", model_code: "" }));
                    }}
                  />
                ) : null
              ))}
            </Card>
          ) : null}
          {!customerId ? <Text style={ps.error}>{t("qtNoCustomerIdentity")}</Text> : null}
          {submitError || (storeError && isLast) ? <ErrorCard error={submitError ?? { message: storeError }} fallback={t("qtOffersNotCalculated")} onRetry={() => void next()} /> : null}
          <Text style={ps.meta}>{t("qtServerValidates")}</Text>
          <View style={st.nav}>
            {step > 0 ? <View style={st.flex}><Button label={t("qtBack")} variant="secondary" disabled={busy} onPress={() => setStep(step - 1)} /></View> : null}
            <View style={st.flex}>
              <Button label={isLast ? t("qtGetLiveOffers") : t("next")} loading={busy} disabled={isLast && !customerId} onPress={() => void next()} />
            </View>
          </View>
        </>
      )}
    </Screen>
  );
}

/** Localized options from the vehicle reference (EN/FR) for body type, fuel, usage, … */
function withReferenceOptions(field: RiskField, reference: VehicleReference | null): RiskField {
  const rows = field.reference && reference ? (reference as unknown as Record<string, { code: string; label: string }[]>)[field.reference] : undefined;
  return rows?.length ? { ...field, options: rows.map((r) => ({ value: r.code, label: r.label })) } : field;
}

function selectionFromValues(values: Record<string, string>): VehicleSelection | null {
  if (!values.make) return null;
  return {
    make_code: values.make_code || undefined,
    make: values.make,
    model_code: values.model_code || undefined,
    model: values.model ?? "",
    year: values.year || undefined,
    manual: !values.make_code || !values.model_code,
    review_id: values.vehicle_review_id || undefined,
  };
}

const st = StyleSheet.create({
  choiceRow: { flexDirection: "row", gap: space.x2 },
  choice: { flex: 1, minHeight: 52, flexDirection: "row", gap: space.x2, alignItems: "center", justifyContent: "center", borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control },
  choiceOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  choiceText: { ...type.label, color: colors.navy950 },
  asset: { minHeight: 52, flexDirection: "row", gap: space.x3, alignItems: "center", paddingHorizontal: space.x3, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control },
  flex: { flex: 1 },
  nav: { flexDirection: "row", gap: space.x3 },
});
