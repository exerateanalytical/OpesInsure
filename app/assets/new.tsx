import React, { useMemo, useState } from "react";
import { Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { CarFront, Package } from "lucide-react-native";
import { Button, Card, Screen, TextField } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { ErrorCard, PickerField, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ReviewFooter, ReviewIntro, ReviewRows, ReviewSection, useReviewCopy } from "@/components/review/ReviewSummary";
import { VehiclePicker, useVehicleReference } from "@/components/vehicles/VehiclePicker";
import { AssetsApi } from "@/api/client";
import { RiskAssetTypesApi } from "@/api/crm";
import { duplicateAssetId } from "@/lib/crm";
import { reviewRows } from "@/lib/formSummary";
import type { RiskField } from "@/lib/riskSchema";
import { useLoad } from "@/hooks/useLoad";
import { useInsurance } from "@/store/insurance";
import { useTranslation } from "@/i18n";
import { modelYears, selectionLabel, VehicleSelection } from "@/lib/vehicles";

const opt = (rows?: { code: string; label: string }[]) => (rows ?? []).map((r) => ({ value: r.code, label: r.label }));

/** Insured object: type from GET /risk-asset-types; vehicles use the vehicle picker, other types a name + reference. */
export default function NewAsset() {
  const { t, td, language } = useTranslation();
  // returnTo=quote: opened from the quote's "Add new" — go back to the quote with the new asset selected.
  const params = useLocalSearchParams<{ type?: string; returnTo?: string }>();
  const fromQuote = params.returnTo === "quote";
  const finish = (id: string, path: string) => {
    if (!fromQuote) return router.replace(path as never);
    useInsurance.getState().setRiskAsset(id);
    if (router.canGoBack()) router.back();
    else router.replace("/quote/risk");
  };
  const types = useLoad(() => RiskAssetTypesApi.list(), []);
  const [assetType, setAssetType] = useState<string>(String(params.type || "VEHICLE").toUpperCase());
  const isVehicle = assetType === "VEHICLE";
  const [duplicateId, setDuplicateId] = useState<string | null>(null);
  const { reference, error: referenceError, retry } = useVehicleReference();
  const [label, setLabel] = useState("");
  const [registration, setRegistration] = useState("");
  const [vehicle, setVehicle] = useState<VehicleSelection | null>(null);
  const [details, setDetails] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<unknown>(null);
  // What will be created is shown read-only for a last check before it is saved.
  const [reviewing, setReviewing] = useState(false);
  const copy = useReviewCopy();
  const years = useMemo(() => modelYears(reference?.model_years).map((y) => ({ value: y, label: y })), [reference]);
  const set = (k: string) => (v: string) => setDetails((d) => ({ ...d, [k]: v }));

  const typeOptions = (types.data?.length ? types.data : [{ code: "VEHICLE", label: t("assetVehicle") }]).map((x) => ({ value: x.code, label: td(`assetType_${x.code}`, x.label) }));
  const typeLabel = typeOptions.find((o) => o.value === assetType)?.label ?? td(`assetType_${assetType}`, assetType);
  const reg = (registration || vehicle?.registration_number || "").trim().toUpperCase();
  const vehicleName = label.trim() || selectionLabel({ make: vehicle?.make, model: vehicle?.model }) || t("vehicleDefaultNickname");

  const review = () => {
    setSaveError(null);
    setDuplicateId(null);
    setReviewing(true);
  };

  const saveObject = async () => {
    setSaving(true);
    setSaveError(null);
    setDuplicateId(null);
    try {
      const a = await AssetsApi.createObject({ type: assetType, display_name: label.trim(), external_reference: registration.trim() || null });
      finish(a.id, `/assets/${a.id}`);
    } catch (e) {
      setDuplicateId(duplicateAssetId(e));
      setSaveError(e);
    } finally {
      setSaving(false);
    }
  };

  const save = async () => {
    setSaving(true);
    setSaveError(null);
    setDuplicateId(null);
    try {
      const facts: Record<string, unknown> = {
        registration_number: reg,
        make: vehicle?.make,
        model: vehicle?.model,
        ...(vehicle?.make_code ? { make_code: vehicle.make_code } : {}),
        ...(vehicle?.model_code ? { model_code: vehicle.model_code } : {}),
        ...(vehicle?.review_id ? { vehicle_review_id: vehicle.review_id } : {}),
        ...(vehicle?.vin ? { vin: vehicle.vin } : {}),
        ...(vehicle?.engine_number ? { engine_number: vehicle.engine_number } : {}),
      };
      const merged = { year: vehicle?.year, body_type: vehicle?.body_type, powertrain: vehicle?.powertrain, vehicle_usage: vehicle?.vehicle_usage, ...details };
      for (const [k, v] of Object.entries(merged)) if (v) facts[k] = k === "year" ? Number(v) : v;
      const a = await AssetsApi.createVehicle({ display_name: vehicleName, registration_number: reg, facts });
      // The photo scan can be done later from the vehicle page; mid-quote the customer goes straight back.
      finish(a.id, `/assets/${a.id}/scan`);
    } catch (e) {
      // 409 duplicate vehicle: offer the existing asset instead of a second record.
      setDuplicateId(duplicateAssetId(e));
      setSaveError(e);
    } finally {
      setSaving(false);
    }
  };

  const duplicate = duplicateId ? (
    <Card>
      <Text style={ps.title}>{t("assetDuplicateTitle")}</Text>
      <Text style={ps.meta}>{t("assetDuplicateBody")}</Text>
      <Button label={t(fromQuote ? "assetUseExisting" : "assetOpenExisting")} variant="secondary" onPress={() => finish(duplicateId, `/assets/${duplicateId}`)} />
    </Card>
  ) : null;

  if (reviewing) {
    // The vehicle facts in words: make/model by name, option codes as their labels (formSummary).
    const facts: [RiskField, string | undefined][] = [
      [{ key: "make_code", label: t("vehicleMake"), type: "vehicle_make", textKey: "make" }, vehicle?.make_code || vehicle?.make],
      [{ key: "model_code", label: t("vehicleModel"), type: "vehicle_model", textKey: "model" }, vehicle?.model_code || vehicle?.model],
      [{ key: "generation", label: t("vehicleGeneration"), type: "text" }, vehicle?.generation],
      [{ key: "year", label: t("vehicleYear"), type: "text" }, details.year ?? vehicle?.year],
      [{ key: "variant", label: t("vehicleEngineVariant"), type: "text" }, vehicle?.variant],
      [{ key: "body_type", label: t("vehicleBodyType"), type: "select", options: opt(reference?.body_types) }, details.body_type ?? vehicle?.body_type],
      [{ key: "powertrain", label: t("vehicleFuel"), type: "select", options: opt(reference?.powertrains) }, details.powertrain ?? vehicle?.powertrain],
      [{ key: "transmission", label: t("vehicleTransmission"), type: "select", options: opt(reference?.transmissions) }, details.transmission ?? vehicle?.transmission],
      [{ key: "vehicle_usage", label: t("vehicleUsage"), type: "select", options: opt(reference?.usage_types) }, details.vehicle_usage ?? vehicle?.vehicle_usage],
      [{ key: "vin", label: t("vehicleVin"), type: "text" }, vehicle?.vin],
      [{ key: "engine_number", label: t("vehicleEngineNumber"), type: "text" }, vehicle?.engine_number],
    ];
    // Steps the picker skipped (no generation/variant data, no VIN typed) are left out.
    const optional = ["generation", "variant", "vin", "engine_number"];
    const shown = facts.filter(([f, v]) => !optional.includes(f.key) || !!v);
    const values: Record<string, string> = { make: vehicle?.make ?? "", model: vehicle?.model ?? "", ...Object.fromEntries(shown.map(([f, v]) => [f.key, v ?? ""])) };
    const rows = reviewRows(shown.map(([f]) => f), values, language === "fr" ? "fr" : "en", () => undefined, copy);
    return (
      <Screen
        footer={
          <ReviewFooter
            label={isVehicle && !fromQuote ? t("vehicleSaveAndScan") : t("assetConfirmAdd")}
            loading={saving}
            disabled={!!duplicateId}
            onConfirm={() => void (isVehicle ? save() : saveObject())}
            onBack={() => setReviewing(false)}
          />
        }
      >
        <BrandHeader title={isVehicle ? t("vehicleAddTitle") : t("assetAddTitle")} subtitle={isVehicle ? t("vehicleAddSubtitle") : t("assetAddSubtitle")} back right={null} />
        <ReviewIntro body={t("reviewSaveIntro")} />
        <ReviewSection icon={isVehicle ? CarFront : Package} title={typeLabel} onEdit={() => setReviewing(false)}>
          <ReviewRows
            rows={[
              { key: "type", label: t("assetReviewType"), value: typeLabel },
              ...(isVehicle
                ? [...rows, { key: "registration", label: t("vehicleRegistration"), value: reg || null }, { key: "nickname", label: t("vehicleNickname"), value: vehicleName }]
                : [
                    { key: "name", label: t("assetDisplayName"), value: label.trim() || null },
                    { key: "reference", label: t("assetReference"), value: registration.trim() || null },
                  ]),
            ]}
          />
        </ReviewSection>
        {duplicate}
        {saveError && !duplicateId ? <ErrorCard error={saveError} fallback={t("vehicleSaveError")} onRetry={() => void (isVehicle ? save() : saveObject())} /> : null}
      </Screen>
    );
  }

  if (!isVehicle)
    return (
      <Screen>
        <BrandHeader title={t("assetAddTitle")} subtitle={t("assetAddSubtitle")} back right={null} />
        <Card>
          <PickerField label={t("assetTypeLabel")} value={assetType} options={typeOptions} onChange={setAssetType} />
        </Card>
        <Card>
          <TextField label={t("assetDisplayName")} value={label} onChangeText={setLabel} />
          <TextField label={t("assetReference")} value={registration} onChangeText={setRegistration} />
          <Button label={t("reviewBeforeSave")} disabled={!label.trim()} onPress={review} />
        </Card>
      </Screen>
    );

  return (
    <Screen>
      <BrandHeader title={t("vehicleAddTitle")} subtitle={t("vehicleAddSubtitle")} back right={null} />
      {typeOptions.length > 1 ? (
        <Card>
          <PickerField label={t("assetTypeLabel")} value={assetType} options={typeOptions} onChange={setAssetType} />
        </Card>
      ) : null}
      <Card>
        <VehiclePicker value={vehicle} onChange={setVehicle} />
      </Card>
      {vehicle?.model ? (
        <Card>
          <PickerField label={t("vehicleYear")} value={details.year ?? vehicle.year} options={years} onChange={set("year")} />
          {referenceError ? <ErrorCard error={{ message: t("vehicleLoadError") }} fallback={t("vehicleLoadError")} onRetry={retry} /> : null}
          {!vehicle.manual || !vehicle.body_type ? <PickerField label={t("vehicleBodyType")} value={details.body_type ?? vehicle.body_type} options={opt(reference?.body_types)} onChange={set("body_type")} /> : null}
          <PickerField label={t("vehicleFuel")} value={details.powertrain ?? vehicle.powertrain} options={opt(reference?.powertrains)} onChange={set("powertrain")} />
          <PickerField label={t("vehicleTransmission")} value={details.transmission} options={opt(reference?.transmissions)} onChange={set("transmission")} />
          <PickerField label={t("vehicleUsage")} value={details.vehicle_usage ?? vehicle.vehicle_usage} options={opt(reference?.usage_types)} onChange={set("vehicle_usage")} />
        </Card>
      ) : null}
      <Card>
        <TextField label={t("vehicleNickname")} value={label} onChangeText={setLabel} placeholder={t("vehicleDefaultNickname")} />
        <TextField label={t("vehicleRegistration")} autoCapitalize="characters" value={registration || vehicle?.registration_number || ""} onChangeText={setRegistration} placeholder="LT 000 AA" />
        {!vehicle?.model ? <Text style={ps.meta}>{t("vehicleChooseMakeFirst")}</Text> : null}
        <Button label={t("reviewBeforeSave")} disabled={!(registration.trim() || vehicle?.registration_number) || !vehicle?.model} onPress={review} />
      </Card>
    </Screen>
  );
}
