import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Car, Check, UserRound } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { DateField, ErrorCard, PickerField, Stepper, YesNoField, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { AssetsApi, CatalogueApi, RiskAsset } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { unwrapPage } from "@/lib/purchase";
import { buildFacts, isValidIsoDate, localRiskSchema, normalizeRiskSchema, RiskField, RiskSchema, validateStep } from "@/lib/riskSchema";
import { colors, radius, space, type } from "@/theme/tokens";

const ASSET_LINES: Record<string, string[]> = { MOTOR: ["VEHICLE", "MOTOR", "CAR", "MOTORCYCLE"], HOME: ["PROPERTY", "HOME", "BUILDING"] };
const RELATIONSHIPS = [
  { value: "SPOUSE", label: "Spouse" },
  { value: "CHILD", label: "Child" },
  { value: "PARENT", label: "Parent" },
  { value: "EMPLOYEE", label: "Employee" },
  { value: "OTHER", label: "Other" },
];

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
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [step, setStep] = useState(0);
  const [submitError, setSubmitError] = useState<unknown>(null);

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
    if (ASSET_LINES[line])
      AssetsApi.list()
        .then((x) => setAssets(unwrapPage<RiskAsset>(x).items.filter((a) => ASSET_LINES[line]!.includes(String(a.type).toUpperCase()) || line === "MOTOR" && !!a.registration_number)))
        .catch(() => setAssets([]));
  }, [line, loadSchema]);

  // Step 0 is "who / what is insured"; schema steps follow.
  const steps = useMemo(() => ["Insured", ...(schema?.steps.map((s) => s.title) ?? [])], [schema]);
  const current = step > 0 ? schema?.steps[step - 1] : undefined;
  const isLast = step === steps.length - 1;

  const insuredError =
    insured.mode === "other" && (!insured.full_name.trim() || !insured.relationship || !isValidIsoDate(insured.date_of_birth))
      ? "Enter the insured person's name, relationship and date of birth."
      : null;

  const next = async () => {
    if (step === 0) {
      if (insuredError) return setErrors({ insured: insuredError });
      setErrors({});
      return setStep(1);
    }
    if (!current || !schema) return;
    const e = validateStep(current, values);
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

  const setValue = (key: string, v: string) => {
    setValues((x) => ({ ...x, [key]: v }));
    if (errors[key]) setErrors((x) => ({ ...x, [key]: "" }));
  };

  if (!line || (!schemaLoading && !schema))
    return (
      <Screen>
        <AppHeader title="Cover details" back />
        <Card>
          <Text style={ps.title}>Choose a supported insurance product first.</Text>
        </Card>
        <Button label="Choose product" onPress={() => router.replace("/quote/product")} />
      </Screen>
    );

  return (
    <Screen>
      <AppHeader title={current?.title ?? "Who is insured?"} subtitle={`Step 2 of 5 · ${schema?.source === "server" ? "Insurer questionnaire" : "Used for live rating"}`} back />
      {schemaLoading ? (
        <LoadingState label="Loading questions…" />
      ) : (
        <>
          <Stepper steps={steps} current={step} />
          {step === 0 ? (
            <>
              <Card>
                <Text style={ps.title}>Who should this policy cover?</Text>
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
                      <Text style={st.choiceText}>{mode === "self" ? "Me" : "Someone else"}</Text>
                    </Pressable>
                  ))}
                </View>
                {insured.mode === "other" ? (
                  <>
                    <TextField label="Full name" value={insured.full_name} onChangeText={(full_name) => setInsured({ ...insured, full_name })} />
                    <PickerField label="Relationship to you" value={insured.relationship || undefined} options={RELATIONSHIPS} onChange={(relationship) => setInsured({ ...insured, relationship })} />
                    <DateField label="Date of birth" value={insured.date_of_birth} onChange={(date_of_birth) => setInsured({ ...insured, date_of_birth })} maxYear={new Date().getFullYear()} />
                  </>
                ) : null}
                {errors.insured ? <Text style={ps.error}>{errors.insured}</Text> : null}
              </Card>
              {assets.length ? (
                <Card>
                  <Text style={ps.title}>Use a saved {line === "MOTOR" ? "vehicle" : "property"}?</Text>
                  <Text style={ps.meta}>Its details are prefilled and the quote is linked to it.</Text>
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
                          <Text style={st.choiceText}>{a.label || a.registration_number || "Saved asset"}</Text>
                          {a.registration_number ? <Text style={ps.meta}>{a.registration_number}</Text> : null}
                        </View>
                        {on ? <Check size={18} color={colors.blue600} /> : null}
                      </Pressable>
                    );
                  })}
                  <Button label="Add a new one" variant="tertiary" onPress={() => router.push("/assets/new")} />
                </Card>
              ) : null}
            </>
          ) : current ? (
            <Card>
              {current.fields.map((f) => (
                <FieldInput key={f.key} field={f} value={values[f.key]} error={errors[f.key] || undefined} onChange={(v) => setValue(f.key, v)} />
              ))}
            </Card>
          ) : null}
          {!customerId ? <Text style={ps.error}>This workspace has no server-issued customer identity.</Text> : null}
          {submitError || (storeError && isLast) ? <ErrorCard error={submitError ?? { message: storeError }} fallback="Offers could not be calculated." onRetry={() => void next()} /> : null}
          <Text style={ps.meta}>The server validates these facts against the active product and tariff. The app never calculates or invents premiums.</Text>
          <View style={st.nav}>
            {step > 0 ? <View style={st.flex}><Button label="Back" variant="secondary" disabled={busy} onPress={() => setStep(step - 1)} /></View> : null}
            <View style={st.flex}>
              <Button label={isLast ? "Get live offers" : "Next"} loading={busy} disabled={isLast && !customerId} onPress={() => void next()} />
            </View>
          </View>
        </>
      )}
    </Screen>
  );
}

function FieldInput({ field, value, error, onChange }: { field: RiskField; value?: string; error?: string; onChange: (v: string) => void }) {
  const label = field.required ? field.label : `${field.label} (optional)`;
  switch (field.type) {
    case "select":
      return <PickerField label={label} value={value} options={field.options ?? []} onChange={onChange} error={error} />;
    case "date": {
      const y = new Date().getFullYear();
      const past = /birth|licence|issued/i.test(field.key);
      return <DateField label={label} value={value} onChange={onChange} error={error} minYear={past ? y - 100 : y - 1} maxYear={past ? y : y + 2} />;
    }
    case "boolean":
      return <YesNoField label={label} value={value} onChange={onChange} error={error} />;
    case "number":
    case "money":
      return <TextField label={label} value={value ?? ""} onChangeText={(v) => onChange(v.replace(/[^\d.]/g, ""))} placeholder={field.placeholder} keyboardType="numeric" error={error} hint={field.help ?? (field.type === "money" ? "Amount in FCFA" : undefined)} />;
    default:
      return <TextField label={label} value={value ?? ""} onChangeText={onChange} placeholder={field.placeholder} autoCapitalize="characters" error={error} hint={field.help} />;
  }
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
