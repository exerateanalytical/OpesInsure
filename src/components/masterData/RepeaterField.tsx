import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { Plus, Trash2 } from "lucide-react-native";
import { Button, TextField } from "@/components/ui";
import { DateField, YesNoField } from "@/components/purchase/PurchaseUi";
import { MasterSelectField } from "@/components/masterData/MasterSelectField";
import { useTranslation } from "@/i18n";
import { allocationTotals, parseItems, validateRepeater, type RepeaterItem } from "@/lib/masterFields";
import { resolveDateBound, type RiskField } from "@/lib/riskSchema";
import { colors, radius, space, type } from "@/theme/tokens";

const yearOf = (iso?: string) => (iso && /^\d{4}/.test(iso) ? Number(iso.slice(0, 4)) : undefined);

/**
 * Members / beneficiaries / scheduled items builder. Items are stored as a
 * JSON string; each item's fields render like wizard fields (controlled lists
 * included). Allocation shares show a running total per rank (must be 100%).
 */
export function RepeaterField({ field, value, onChange, error, lineCode, screen }: { field: RiskField; value?: string; onChange: (v: string) => void; error?: string; lineCode?: string; screen?: string }) {
  const { t, language } = useTranslation();
  const lang = language === "fr" ? "fr" : "en";
  const items = parseItems(value);
  const label = (f: RiskField) => (lang === "fr" && f.labelFr ? f.labelFr : f.label);
  const { itemErrors } = error ? validateRepeater(field, value ?? "", lang) : { itemErrors: [] as Record<string, string>[] };
  const save = (next: RepeaterItem[]) => onChange(JSON.stringify(next));
  const set = (i: number, key: string, v: string, other?: string) =>
    save(items.map((it, j) => (j === i ? { ...it, [key]: v, ...(other !== undefined ? { [`${key}_other`]: other } : {}) } : it)));
  const canAdd = field.maxItems === undefined || items.length < field.maxItems;
  const totals = field.allocation ? allocationTotals(field.allocation, items) : null;

  return (
    <View style={s.wrap}>
      <Text style={s.title}>{field.required ? label(field) : `${label(field)} ${t("mdOptional")}`}</Text>
      {items.length === 0 ? <Text style={s.hint}>{t("mdRepeaterEmpty")}</Text> : null}
      {items.map((item, i) => (
        <View key={i} style={s.item}>
          <View style={s.itemHeader}>
            <Text style={s.itemTitle}>{`${label(field)} ${i + 1}`}</Text>
            <Pressable accessibilityRole="button" accessibilityLabel={t("mdRemove")} onPress={() => save(items.filter((_, j) => j !== i))} hitSlop={8}>
              <Trash2 size={18} color={colors.danger} />
            </Pressable>
          </View>
          {(field.itemFields ?? []).map((sub) => {
            const err = itemErrors[i]?.[sub.key];
            const subLabel = sub.required ? label(sub) : `${label(sub)} ${t("mdOptional")}`;
            if (sub.type === "select_master" && sub.master)
              return (
                <MasterSelectField key={sub.key} label={label(sub)} required={sub.required} domain={sub.master.domain} list={sub.master.list} value={item[sub.key]}
                  parent={sub.parentField ? item[sub.parentField] : sub.parentCode} otherAllowed={sub.otherAllowed} otherText={item[`${sub.key}_other`]}
                  onChange={(v, other) => set(i, sub.key, v, other)} error={err} lineCode={lineCode} fieldKey={`${field.key}.${sub.key}`} screen={screen} />
              );
            if (sub.type === "date")
              return <DateField key={sub.key} label={subLabel} value={item[sub.key]} onChange={(v) => set(i, sub.key, v)} error={err} minYear={yearOf(resolveDateBound(sub.dateMin)) ?? new Date().getFullYear() - 110} maxYear={yearOf(resolveDateBound(sub.dateMax)) ?? new Date().getFullYear()} />;
            if (sub.type === "boolean") return <YesNoField key={sub.key} label={subLabel} value={item[sub.key]} onChange={(v) => set(i, sub.key, v)} error={err} />;
            const numeric = sub.type === "number" || sub.type === "money";
            return (
              <TextField key={sub.key} label={subLabel} value={item[sub.key] ?? ""} error={err} keyboardType={numeric ? "decimal-pad" : "default"} maxLength={sub.maxLength} autoCapitalize={sub.freeText === "PERSON_NAME" ? "words" : "sentences"}
                onChangeText={(v) => set(i, sub.key, numeric ? v.replace(/[^\d.]/g, "") : v)} hint={sub.type === "money" ? t("mdAmountFcfa") : undefined} />
            );
          })}
        </View>
      ))}
      {totals
        ? Object.entries(totals).map(([group, sum]) => (
            <Text key={group} style={[s.total, Math.abs(sum - field.allocation!.total) < 0.001 ? s.ok : s.bad]}>
              {t("mdAllocationTotal", { group: group === "_" ? "" : ` (${group.toLowerCase()})`, sum, total: field.allocation!.total })}
            </Text>
          ))
        : null}
      {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
      {canAdd ? <Button label={t("mdAdd")} icon={Plus} variant="secondary" onPress={() => save([...items, {}])} /> : null}
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { gap: space.x2 },
  title: { ...type.label, color: colors.navy950 },
  hint: { ...type.meta, color: colors.neutral600 },
  item: { gap: space.x2, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.control, padding: space.x3 },
  itemHeader: { flexDirection: "row", justifyContent: "space-between", alignItems: "center" },
  itemTitle: { ...type.label, color: colors.navy950 },
  total: { ...type.meta },
  ok: { color: colors.success },
  bad: { color: colors.dangerText },
  error: { ...type.meta, color: colors.dangerText },
});
