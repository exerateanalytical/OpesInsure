import React, { ReactNode, useEffect, useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { CheckCircle2, Pencil, Plus, UserRound, X, type LucideIcon } from "lucide-react-native";
import { Card, ripple } from "@/components/ui";
import { Banner, TintedIcon } from "@/components/design";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { SchemaForm, useFormSchema, type SchemaFormSubmit } from "@/components/forms/SchemaForm";
import { loadList } from "@/lib/masterData";
import { labelOf, type MasterValue } from "@/lib/masterFields";
import { allFields, type RiskField } from "@/lib/riskSchema";
import { anyFilled, summarizeFields, type LabelResolver } from "@/lib/formSummary";
import type { FormName } from "@/lib/inputForms";
import type { DeviceFix } from "@/lib/locationMatch";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

// ---------------------------------------------------------------------------
// Profile-card primitives: one look for every "details on file" card.
// ---------------------------------------------------------------------------

/**
 * Card with a tinted icon, a title and an Edit action (profile-card look).
 * With `onCancel` it is the same card in edit mode (form inside, Cancel pill).
 */
export function SummaryCard({ icon, title, onEdit, onCancel, editing, editLabel, children }: { icon: LucideIcon; title: string; onEdit?: () => void; onCancel?: () => void; /** Form inside (spaced like a form, not like summary rows). */ editing?: boolean; editLabel?: string; children: ReactNode }) {
  const { t } = useTranslation();
  return (
    <Card style={s.card}>
      <View style={s.head}>
        <TintedIcon icon={icon} tint="blue" size={40} />
        <Text accessibilityRole="header" style={s.title}>{title}</Text>
        {onEdit ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`${editLabel ?? t("summaryEdit")}: ${title}`}
            onPress={onEdit}
            hitSlop={8}
            android_ripple={ripple()}
            style={({ pressed }) => [s.editBtn, pressed && s.pressed]}
          >
            <Pencil size={16} color={colors.blue600} />
            <Text style={s.editText}>{editLabel ?? t("summaryEdit")}</Text>
          </Pressable>
        ) : onCancel ? (
          <Pressable accessibilityRole="button" accessibilityLabel={t("cancel")} onPress={onCancel} hitSlop={8} style={({ pressed }) => [s.editBtn, s.cancelBtn, pressed && s.pressed]}>
            <X size={16} color={colors.navy900} />
            <Text style={[s.editText, s.cancelText]}>{t("cancel")}</Text>
          </Pressable>
        ) : null}
      </View>
      <View style={editing || onCancel ? s.editBody : undefined}>{children}</View>
    </Card>
  );
}

/** Label above value; an empty value becomes an "Add" prompt that opens the editor. */
export function SummaryField({ label, value, onAdd, right, note, items, first }: { label: string; value?: string | null; onAdd?: () => void; right?: ReactNode; note?: string | null; items?: string[]; first?: boolean }) {
  const { t } = useTranslation();
  const empty = items ? items.length === 0 : !value;
  return (
    <View style={[s.field, !first && s.fieldDivider]}>
      <View style={s.flex}>
        <Text style={s.label}>{label}</Text>
        {empty ? (
          onAdd ? (
            <Pressable accessibilityRole="button" accessibilityLabel={`${t("summaryAdd")}: ${label}`} onPress={onAdd} hitSlop={6} style={({ pressed }) => [s.add, pressed && s.pressed]}>
              <Plus size={16} color={colors.blue600} />
              <Text style={s.addText}>{t("summaryAdd")}</Text>
            </Pressable>
          ) : (
            <Text style={s.missing}>{t("summaryNotProvided")}</Text>
          )
        ) : items ? (
          items.map((line, i) => (
            <View key={`${i}-${line}`} style={s.item}>
              <UserRound size={16} color={colors.navy800} />
              <Text style={[s.value, s.flex]} selectable>{line}</Text>
            </View>
          ))
        ) : (
          <Text style={s.value} selectable>{value}</Text>
        )}
        {note ? <Text style={s.note}>{note}</Text> : null}
      </View>
      {right}
    </View>
  );
}

// ---------------------------------------------------------------------------
// Server-form summary + editor
// ---------------------------------------------------------------------------

/** Loads the master lists a set of fields uses and resolves codes to labels in the current language. */
function useMasterLabels(fields: RiskField[]): LabelResolver {
  const { language } = useTranslation();
  const lang = language === "fr" ? "fr" : "en";
  const sources = useMemo(() => {
    const out = new Map<string, { domain: string; list: string }>();
    for (const f of fields) for (const x of [f, ...(f.itemFields ?? [])]) if (x.master) out.set(`${x.master.domain}.${x.master.list}`, x.master);
    return [...out.entries()];
  }, [fields]);
  const [lists, setLists] = useState<Record<string, Map<string, MasterValue>>>({});
  useEffect(() => {
    let live = true;
    for (const [key, src] of sources) {
      if (lists[key]) continue;
      loadList(src.domain, src.list)
        .then((r) => {
          if (live && r) setLists((x) => ({ ...x, [key]: new Map(r.list.values.map((v) => [v.code, v])) }));
        })
        .catch(() => undefined); // offline: codes are humanized instead
    }
    return () => {
      live = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sources]);
  return (f, code) => {
    const v = f.master ? lists[`${f.master.domain}.${f.master.list}`]?.get(code) : undefined;
    return v ? labelOf(v, lang) : undefined;
  };
}

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
        onSubmit={async (payload, ctx) => {
          await onSubmit(payload, ctx);
          setEditing(false);
          setSaved(true);
        }}
      />
    </SummaryCard>
  );
}

const s = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: space.x3 },
  head: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950, flex: 1 },
  editBtn: { flexDirection: "row", alignItems: "center", gap: 6, minHeight: 36, paddingHorizontal: space.x3, borderRadius: radius.pill, backgroundColor: colors.blue50, overflow: "hidden" },
  editText: { ...type.label, color: colors.blue600 },
  editBody: { gap: space.x4 },
  cancelBtn: { backgroundColor: colors.neutral100 },
  cancelText: { color: colors.navy900 },
  pressed: { opacity: 0.85 },
  flex: { flex: 1 },
  field: { flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x3 },
  fieldDivider: { borderTopWidth: 1, borderTopColor: colors.neutral100 },
  label: { ...type.meta, color: colors.neutral600, marginBottom: 2 },
  value: { ...type.body, color: colors.navy950 },
  missing: { ...type.body, color: colors.neutral500 },
  note: { ...type.meta, color: colors.neutral600, marginTop: space.x1 },
  item: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 28 },
  add: { flexDirection: "row", alignItems: "center", gap: 4, minHeight: 32, alignSelf: "flex-start" },
  addText: { ...type.label, color: colors.blue600 },
});
