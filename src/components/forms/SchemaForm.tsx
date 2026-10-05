import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { Button, Card, SectionTitle } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ContractField } from "@/components/forms/ContractField";
import { FormLocationAutofill } from "@/components/forms/LocationAutofill";
import { ReviewIntro, SchemaReviewSection } from "@/components/review/ReviewSummary";
import { ClipboardList, Pencil } from "lucide-react-native";
import type { DeviceFix } from "@/lib/locationMatch";
import { api } from "@/api/client";
import { useTranslation } from "@/i18n";
import { sectionPayload } from "@/lib/formSummary";
import { buildFormPayload, initialFormValues, normalizeFormSchema, type FormName, type FormSchema } from "@/lib/inputForms";
import { allFields, clearedDependents, isFieldVisible, validateStep } from "@/lib/riskSchema";
import type { MasterValue } from "@/lib/masterFields";
import { colors, type } from "@/theme/tokens";

const CACHE = (form: string) => `opesinsure.forms.${form}`;
const memory = new Map<string, FormSchema>();

/** GET /forms/{form}; the last good copy is kept for offline use. */
export async function loadFormSchema(form: FormName): Promise<FormSchema> {
  try {
    const schema = normalizeFormSchema(await api<unknown>(`/forms/${form}`, { anonymous: true, envelope: true, timeoutMs: 10000 }), form);
    if (!schema) throw new Error("Unusable form schema");
    memory.set(form, schema);
    AsyncStorage.setItem(CACHE(form), JSON.stringify(schema)).catch(() => undefined);
    return schema;
  } catch (e) {
    const known = memory.get(form);
    if (known) return known;
    try {
      const raw = await AsyncStorage.getItem(CACHE(form));
      if (raw) return JSON.parse(raw) as FormSchema;
    } catch {
      // no cached copy
    }
    throw e;
  }
}

export function useFormSchema(form: FormName) {
  const [schema, setSchema] = useState<FormSchema | null>(memory.get(form) ?? null);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(!memory.has(form));
  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setSchema(await loadFormSchema(form));
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  }, [form]);
  useEffect(() => {
    void reload();
  }, [reload]);
  return { schema, error, loading, reload };
}

export type SchemaFormSubmit = (payload: Record<string, unknown>, ctx: { values: Record<string, string>; schema: FormSchema }) => Promise<void>;

/**
 * Renders a server form (GET /forms/{form}) with the contract renderer and
 * submits the typed payload: helpers (submit:false) are dropped and
 * compose_into targets carry "Landmark, City, Department, Region".
 */
export function SchemaForm({
  form,
  initialValues,
  onSubmit,
  submitLabel,
  endpointFilter,
  hide = [],
  disabled,
  resetOnSuccess,
  footer,
  flat = false,
  submitIcon,
  onLocation,
  only,
  review,
  onValues,
}: {
  form: FormName;
  initialValues?: Record<string, string> | null;
  onSubmit: SchemaFormSubmit;
  submitLabel: string;
  endpointFilter?: Record<string, (v: MasterValue) => boolean>;
  /** Fields the screen renders itself (e.g. the KYC file). */
  hide?: string[];
  disabled?: boolean;
  resetOnSuccess?: boolean;
  footer?: React.ReactNode;
  /** Fields straight on the page canvas (no section cards/titles), as the claim wizard design shows. */
  flat?: boolean;
  submitIcon?: React.ComponentProps<typeof Button>["icon"];
  /** Device position used to prefill town/region (null when cleared or opted out). */
  onLocation?: (fix: DeviceFix | null) => void;
  /**
   * Edit only these fields (one section of a larger form): the others are
   * neither shown, validated nor sent, and a field emptied here is sent as
   * null so the server really clears it.
   */
  only?: string[];
  /**
   * Check-before-submit: a valid form first shows a read-only review of the
   * answers (same labels and option labels, Edit returns to the fields);
   * onSubmit only runs when the customer confirms there.
   */
  review?: { intro?: string; confirmLabel?: string; title?: string; /** Label of the button that opens the review (default "Review before sending"). */ continueLabel?: string };
  /** The raw answers as they change (e.g. "Save draft" on the claim wizard keeps them unvalidated). */
  onValues?: (values: Record<string, string>) => void;
}) {
  const { t, language } = useTranslation();
  const lang = language === "fr" ? "fr" : "en";
  const { schema, error: loadError, loading, reload } = useFormSchema(form);
  const [values, setValues] = useState<Record<string, string>>({});
  const [labels, setLabels] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [submitError, setSubmitError] = useState<unknown>(null);
  const [reviewing, setReviewing] = useState(false);
  const seedKey = useMemo(() => JSON.stringify(initialValues ?? {}), [initialValues]);

  useEffect(() => {
    if (schema) setValues(initialFormValues(schema, JSON.parse(seedKey) as Record<string, string>));
  }, [schema, seedKey]);
  const valuesListener = useRef(onValues);
  valuesListener.current = onValues;
  useEffect(() => {
    valuesListener.current?.(values);
  }, [values]);

  if (loading && !schema) return <LoadingState label={t("loading")} />;
  if (!schema) return <ErrorCard error={loadError} fallback={t("formLoadFailed")} onRetry={() => void reload()} />;

  const Wrap = (flat ? View : Card) as React.ComponentType<{ style?: object; children?: React.ReactNode }>;
  const fields = allFields(schema);
  const inSection = (key: string) => !hide.includes(key) && (!only || only.includes(key));
  const setValue = (key: string, v: string) => {
    setValues((x) => ({ ...x, [key]: v, ...(x[key] !== v ? clearedDependents(fields, key) : {}) }));
    setErrors((x) => (x[key] ? { ...x, [key]: "" } : x));
  };
  const setAny = (key: string, v: string) => setValues((x) => ({ ...x, [key]: v }));

  const submit = async () => {
    const shown = schema.steps.map((s) => ({ ...s, fields: s.fields.filter((f) => inSection(f.key)) }));
    const e = Object.assign({}, ...shown.map((s) => validateStep(s, values, lang))) as Record<string, string>;
    setErrors(e);
    if (Object.values(e).some(Boolean)) return setReviewing(false);
    if (review && !reviewing) {
      setSubmitError(null);
      return setReviewing(true);
    }
    setBusy(true);
    setSubmitError(null);
    try {
      const payload = buildFormPayload(schema, values, labels);
      await onSubmit(only ? sectionPayload(payload, fields, only) : payload, { values, schema });
      setReviewing(false);
      if (resetOnSuccess) setValues(initialFormValues(schema));
    } catch (err) {
      setSubmitError(err);
    } finally {
      setBusy(false);
    }
  };

  if (reviewing)
    return (
      <>
        <ReviewIntro body={review?.intro} />
        {schema.steps.map((step, i) => (
          <SchemaReviewSection
            key={step.key}
            icon={ClipboardList}
            title={(lang === "fr" && step.titleFr ? step.titleFr : step.title) || review?.title || t("reviewYourAnswers")}
            fields={step.fields.filter((f) => inSection(f.key))}
            values={values}
            labels={labels}
            tint={i === 0 ? "blue" : "gold"}
            onEdit={() => setReviewing(false)}
          />
        ))}
        {submitError ? <ErrorCard error={submitError} fallback={t("actionFailed")} onRetry={() => void submit()} /> : null}
        {footer}
        <Button label={review?.confirmLabel ?? submitLabel} icon={submitIcon} loading={busy} disabled={disabled || busy} onPress={() => void submit()} />
        <Button label={t("reviewBackToForm")} icon={Pencil} variant="tertiary" disabled={busy} onPress={() => setReviewing(false)} />
      </>
    );

  return (
    <>
      {schema.steps.map((step) => {
        const visible = step.fields.filter((f) => inSection(f.key) && isFieldVisible(f, values));
        if (!visible.length) return null;
        return (
          <React.Fragment key={step.key}>
            {schema.steps.length > 1 && !flat ? <SectionTitle title={lang === "fr" && step.titleFr ? step.titleFr : step.title} /> : null}
            <Wrap {...(flat ? { style: s.flat } : {})}>
              {visible.some((f) => f.master?.list === "cameroon_region") ? (
                <FormLocationAutofill
                  form={form}
                  fields={visible}
                  values={values}
                  setValues={setValues}
                  onLabels={(l) => setLabels((x) => ({ ...x, ...l }))}
                  onFix={onLocation}
                />
              ) : null}
              {visible.map((f) => (
                <ContractField
                  key={f.key}
                  field={f}
                  value={values[f.key]}
                  values={values}
                  error={errors[f.key] || undefined}
                  onChange={(v) => setValue(f.key, v)}
                  setAny={setAny}
                  onLabel={(key, label) => setLabels((x) => ({ ...x, [key]: label }))}
                  screen={`form.${form}`}
                  endpointFilter={endpointFilter?.[f.key]}
                />
              ))}</Wrap>
          </React.Fragment>
        );
      })}
      {submitError ? <ErrorCard error={submitError} fallback={t("actionFailed")} onRetry={() => void submit()} /> : null}
      {Object.values(errors).some(Boolean) ? <Text accessibilityRole="alert" style={s.error}>{t("formFixErrors")}</Text> : null}
      {footer}
      <Button label={review ? review.continueLabel ?? t("reviewContinue") : submitLabel} icon={submitIcon} loading={busy} disabled={disabled || busy} onPress={() => void submit()} />
    </>
  );
}

const s = StyleSheet.create({
  flat: { gap: 16 },
  error: { ...type.meta, color: colors.dangerText },
});
