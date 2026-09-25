import React, { useCallback, useEffect, useMemo, useState } from "react";
import { StyleSheet, Text } from "react-native";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { Button, Card, SectionTitle } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ContractField } from "@/components/forms/ContractField";
import { api } from "@/api/client";
import { useTranslation } from "@/i18n";
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
}) {
  const { t, language } = useTranslation();
  const lang = language === "fr" ? "fr" : "en";
  const { schema, error: loadError, loading, reload } = useFormSchema(form);
  const [values, setValues] = useState<Record<string, string>>({});
  const [labels, setLabels] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [submitError, setSubmitError] = useState<unknown>(null);
  const seedKey = useMemo(() => JSON.stringify(initialValues ?? {}), [initialValues]);

  useEffect(() => {
    if (schema) setValues(initialFormValues(schema, JSON.parse(seedKey) as Record<string, string>));
  }, [schema, seedKey]);

  if (loading && !schema) return <LoadingState label={t("loading")} />;
  if (!schema) return <ErrorCard error={loadError} fallback={t("formLoadFailed")} onRetry={() => void reload()} />;

  const fields = allFields(schema);
  const setValue = (key: string, v: string) => {
    setValues((x) => ({ ...x, [key]: v, ...(x[key] !== v ? clearedDependents(fields, key) : {}) }));
    setErrors((x) => (x[key] ? { ...x, [key]: "" } : x));
  };
  const setAny = (key: string, v: string) => setValues((x) => ({ ...x, [key]: v }));

  const submit = async () => {
    const shown = schema.steps.map((s) => ({ ...s, fields: s.fields.filter((f) => !hide.includes(f.key)) }));
    const e = Object.assign({}, ...shown.map((s) => validateStep(s, values, lang))) as Record<string, string>;
    setErrors(e);
    if (Object.values(e).some(Boolean)) return;
    setBusy(true);
    setSubmitError(null);
    try {
      await onSubmit(buildFormPayload(schema, values, labels), { values, schema });
      if (resetOnSuccess) setValues(initialFormValues(schema));
    } catch (err) {
      setSubmitError(err);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      {schema.steps.map((step) => {
        const visible = step.fields.filter((f) => !hide.includes(f.key) && isFieldVisible(f, values));
        if (!visible.length) return null;
        return (
          <React.Fragment key={step.key}>
            {schema.steps.length > 1 ? <SectionTitle title={lang === "fr" && step.titleFr ? step.titleFr : step.title} /> : null}
            <Card>
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
              ))}
            </Card>
          </React.Fragment>
        );
      })}
      {submitError ? <ErrorCard error={submitError} fallback={t("actionFailed")} onRetry={() => void submit()} /> : null}
      {Object.values(errors).some(Boolean) ? <Text accessibilityRole="alert" style={s.error}>{t("formFixErrors")}</Text> : null}
      {footer}
      <Button label={submitLabel} loading={busy} disabled={disabled || busy} onPress={() => void submit()} />
    </>
  );
}

const s = StyleSheet.create({
  error: { ...type.meta, color: colors.dangerText },
});
