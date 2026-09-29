import React, { useMemo, useState } from "react";
import { CheckCircle2, type LucideIcon } from "lucide-react-native";
import { Banner } from "@/components/design";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { SchemaForm, useFormSchema, type SchemaFormSubmit } from "@/components/forms/SchemaForm";
import { SummaryCard, SummaryField, useMasterLabels } from "@/components/forms/SummaryCard";
import { allFields } from "@/lib/riskSchema";
import { anyFilled, summarizeFields } from "@/lib/formSummary";
import type { FormName } from "@/lib/inputForms";
import type { DeviceFix } from "@/lib/locationMatch";
import { useTranslation } from "@/i18n";

// Profile-card primitives live in SummaryCard.tsx (no SchemaForm import, so the
// review components can use them without a require cycle); re-exported here.
export { SummaryCard, SummaryField, useMasterLabels };

// ---------------------------------------------------------------------------
// Server-form summary + editor
// ---------------------------------------------------------------------------

/** Read-only summary of some fields of a server form (values as the server holds them). */
export function SchemaSummary({ form, values, only, icon, title, onEdit }: { form: FormName; values: Record<string, string>; only: string[]; icon: LucideIcon; title: string; onEdit: () => void }) {
  const { t, language } = useTranslation();
  const lang = language === "fr" ? "fr" : "en";
  const { schema, error, loading, reload } = useFormSchema(form);
  const fields = useMemo(() => (schema ? allFields(schema).filter((f) => only.includes(f.key)) : []), [schema, only]);
  const resolve = useMasterLabels(fields);
  if (loading && !schema) return <LoadingState label={t("loading")} />;
  if (!schema) return <ErrorCard error={error} fallback={t("formLoadFailed")} onRetry={() => void reload()} />;
  const rows = summarizeFields(fields, values, lang, resolve, { other: t("summaryOther"), yes: t("yes"), no: t("no") });
  return (
    <SummaryCard icon={icon} title={title} onEdit={onEdit}>
      {rows.map((r, i) => (
        <SummaryField key={r.key} first={i === 0} label={r.label} value={r.value} items={r.items} onAdd={onEdit} />
      ))}
    </SummaryCard>
  );
}

/**
 * One section of a server form that reads as filled: a summary card when
 * the server already holds values, the form when nothing is on file or the
 * customer taps Edit; saving returns to the summary with a confirmation.
 * `values` is the server's copy (the parent refreshes it from the save
 * response); `seed` may add device-only drafts to prefill the form.
 */
export function EditableSchemaSection({
  form,
  only,
  values,
  seed,
  icon,
  title,
  submitLabel,
  onSubmit,
  onLocation,
  disabled,
  review,
}: {
  form: FormName;
  only: string[];
  values: Record<string, string>;
  seed?: Record<string, string> | null;
  icon: LucideIcon;
  title: string;
  submitLabel: string;
  onSubmit: SchemaFormSubmit;
  onLocation?: (fix: DeviceFix | null) => void;
  /** No editing (server capability says so): the summary only. */
  disabled?: boolean;
  /** Check-before-save review (SchemaForm `review`). */
  review?: React.ComponentProps<typeof SchemaForm>["review"];
}) {
  const { t } = useTranslation();
  const [editing, setEditing] = useState<boolean | null>(null);
  const [saved, setSaved] = useState(false);
  const filled = anyFilled(values, only);
  const showForm = !disabled && (editing ?? !filled);
  const edit = disabled
    ? undefined
    : () => {
        setSaved(false);
        setEditing(true);
      };
  if (!showForm) {
    return (
      <>
        {saved ? <Banner icon={CheckCircle2} tint="green" body={t("profileSaved")} /> : null}
        <SchemaSummary form={form} values={values} only={only} icon={icon} title={title} onEdit={edit ?? (() => undefined)} />
      </>
    );
  }
  return (
    <SummaryCard icon={icon} title={title} editing onCancel={filled ? () => setEditing(false) : undefined}>
      <SchemaForm
        form={form}
        only={only}
        flat
        initialValues={{ ...(seed ?? {}), ...Object.fromEntries(Object.entries(values).filter(([, v]) => v)) }}
        submitLabel={submitLabel}
        onLocation={onLocation}
        review={review}
        onSubmit={async (payload, ctx) => {
          await onSubmit(payload, ctx);
          setEditing(false);
          setSaved(true);
        }}
      />
    </SummaryCard>
  );
}
