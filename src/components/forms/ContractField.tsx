import React from "react";
import { Text } from "react-native";
import { TextField } from "@/components/ui";
import { DateField, PickerField, YesNoField, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { DateTimeField, toCameroonIso } from "@/components/DateTimeField";
import { MasterSelectField } from "@/components/masterData/MasterSelectField";
import { RepeaterField } from "@/components/masterData/RepeaterField";
import { VehiclePicker } from "@/components/vehicles/VehiclePicker";
import { api } from "@/api/client";
import { useTranslation } from "@/i18n";
import { apiPath, endpointValues } from "@/lib/inputForms";
import { resolveDateBound, type RiskField } from "@/lib/riskSchema";
import type { MasterValue } from "@/lib/masterFields";
import type { VehicleSelection } from "@/lib/vehicles";

/** Loads an endpoint picker's rows (policies, insurance lines, insurer register). */
export function loadEndpointValues(endpoint: string, valueKey: string): Promise<MasterValue[]> {
  const path = apiPath(endpoint);
  const url = `${path}${path.includes("?") ? "&" : "?"}per_page=100`;
  return api<unknown>(url, { envelope: true, timeoutMs: 12000 }).then((p) => endpointValues(p, valueKey));
}

const yearOf = (iso?: string) => (iso && /^\d{4}/.test(iso) ? Number(iso.slice(0, 4)) : undefined);

export type ContractFieldProps = {
  field: RiskField;
  value?: string;
  values: Record<string, string>;
  error?: string;
  onChange: (v: string) => void;
  /** Sets any other key (the "{key}_other" text). */
  setAny: (key: string, v: string) => void;
  /** Display label of a pick (used by compose_into targets). */
  onLabel?: (key: string, label: string) => void;
  lineCode?: string;
  /** Suggestion context for "Other / Not listed" (quote.risk, form.claim_fnol, …). */
  screen?: string;
  /** Keeps only some endpoint rows (e.g. ACTIVE policies for a claim). */
  endpointFilter?: (v: MasterValue) => boolean;
  // Vehicle wizard only.
  riskAssetId?: string | null;
  vehicle?: VehicleSelection | null;
  onVehicle?: (s: VehicleSelection | null) => void;
};

/**
 * One field of InputFieldContract v1 — the single renderer for risk wizards
 * and GET /forms/{form}. Controlled lists are searchable pickers, closed
 * options inline pickers, numbers a numeric keypad with min/max, dates a date
 * picker bounded by min/max ("today" / "now"). A text box appears only for a
 * free_text field (or a local fallback schema).
 */
export function ContractField({ field, value, values, error, onChange, setAny, onLabel, lineCode, screen, endpointFilter, riskAssetId, vehicle, onVehicle }: ContractFieldProps) {
  const { t, language } = useTranslation();
  const text = language === "fr" && field.labelFr ? field.labelFr : field.label;
  const label = field.required ? text : `${text} ${t("mdOptional")}`;
  const pickerChange = (v: string, other?: string, pickLabel?: string) => {
    onChange(v);
    if (other !== undefined) setAny(`${field.key}_other`, other);
    if (pickLabel !== undefined) onLabel?.(field.key, pickLabel);
  };

  if (field.endpoint && (field.type === "select" || field.type === "select_master" || field.type === "multi_select_master")) {
    const endpoint = field.endpoint;
    const valueKey = field.valueKey ?? "id";
    return (
      <MasterSelectField
        label={text} required={field.required} domain={field.master?.domain ?? "endpoint"} list={field.master?.list ?? field.key} value={value}
        multiple={field.type === "multi_select_master"} otherAllowed={field.otherAllowed === true} otherText={values[`${field.key}_other`]} error={error}
        lineCode={lineCode} fieldKey={field.key} screen={screen} suggest={!!field.master} loaderKey={`${endpoint}|${valueKey}`}
        loader={() => loadEndpointValues(endpoint, valueKey).then((rows) => (endpointFilter ? rows.filter(endpointFilter) : rows))}
        onChange={pickerChange}
      />
    );
  }

  switch (field.type) {
    case "select_master":
    case "multi_select_master":
      return field.master ? (
        <MasterSelectField
          label={text} required={field.required} domain={field.master.domain} list={field.master.list} value={value}
          multiple={field.type === "multi_select_master"} parent={field.parentField ? values[field.parentField] : field.parentCode}
          otherAllowed={field.otherAllowed} otherText={values[`${field.key}_other`]} error={error} lineCode={lineCode} fieldKey={field.key} screen={screen}
          onChange={pickerChange}
        />
      ) : null;
    case "repeater":
      return <RepeaterField field={field} value={value} onChange={onChange} error={error} lineCode={lineCode} screen={screen} />;
    case "file":
      return <Text style={ps.meta}>{`${text} — ${t("formFileLater")}`}</Text>;
    case "vehicle_make":
      return onVehicle ? <VehiclePicker value={vehicle ?? null} onChange={onVehicle} error={error} riskAssetId={riskAssetId} /> : null;
    case "vehicle_model":
    case "vehicle_generation":
    case "vehicle_variant":
      return null; // chosen inside the make picker
    case "select":
      return <PickerField label={label} value={value} options={field.options ?? []} onChange={onChange} error={error} />;
    case "date": {
      const min = resolveDateBound(field.dateMin);
      const max = resolveDateBound(field.dateMax);
      if (field.withTime) {
        const ms = value && Number.isFinite(Date.parse(value)) ? Date.parse(value) : Date.now() - 3_600_000;
        return (
          <>
            <DateTimeField
              label={label}
              value={ms}
              maxNow={field.dateMax === "now"}
              onChange={(next) => onChange(toCameroonIso(next))}
              language={language}
              labels={{
                date: t("dateLabel"), time: t("timeLabel"), today: t("today"), yesterday: t("yesterday"), previousDay: t("previousDay"),
                nextDay: t("nextDay"), earlier: t("earlier"), later: t("later"), hour: t("hourUnit"), minutes: t("minutesUnit"),
              }}
            />
            {error ? <Text accessibilityRole="alert" style={ps.error}>{error}</Text> : null}
          </>
        );
      }
      const y = new Date().getFullYear();
      // Without bounds: birth / licence dates look back, cover dates look ahead (local fallback schemas).
      const past = /birth|licence|issued/i.test(field.key);
      return (
        <DateField label={label} value={value} onChange={onChange} error={error}
          minYear={yearOf(min) ?? (past ? y - 100 : y - 1)} maxYear={yearOf(max) ?? (past ? y : y + 2)} />
      );
    }
    case "boolean":
      return <YesNoField label={label} value={value} onChange={onChange} error={error} />;
    case "number":
    case "money": {
      const bounds =
        field.min !== undefined && field.max !== undefined ? t("formBetween", { min: field.min, max: field.max })
        : field.min !== undefined && field.min > 0 ? t("formAtLeast", { min: field.min })
        : field.max !== undefined ? t("formAtMost", { max: field.max }) : undefined;
      const hint = [field.help ?? (field.type === "money" ? t("mdAmountFcfa") : undefined), bounds].filter(Boolean).join(" · ") || undefined;
      return (
        <TextField label={label} value={value ?? ""} onChangeText={(v) => onChange(v.replace(/[^\d.]/g, ""))} placeholder={field.placeholder}
          keyboardType={field.type === "money" || (Number.isInteger(field.min ?? 0) && Number.isInteger(field.max ?? 0)) ? "number-pad" : "decimal-pad"} error={error} hint={hint} />
      );
    }
    default: {
      const reason = field.freeText;
      return (
        <TextField
          label={label}
          value={value ?? ""}
          onChangeText={onChange}
          placeholder={field.placeholder}
          maxLength={field.maxLength}
          multiline={field.multiline}
          style={field.multiline ? { minHeight: 120, textAlignVertical: "top", paddingTop: 12 } : undefined}
          autoCapitalize={!reason || reason === "IDENTIFIER" ? "characters" : reason === "PERSON_NAME" ? "words" : "sentences"}
          keyboardType={field.pattern?.startsWith("^\\+") ? "phone-pad" : "default"}
          error={error}
          hint={field.help ?? (field.multiline && field.maxLength ? t("formCharsLeft", { count: Math.max(0, field.maxLength - (value ?? "").length) }) : undefined)}
        />
      );
    }
  }
}
