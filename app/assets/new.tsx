import React, { useMemo, useState } from "react";
import { Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { ErrorCard, PickerField, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { VehiclePicker, useVehicleReference } from "@/components/vehicles/VehiclePicker";
import { AssetsApi } from "@/api/client";
import { RiskAssetTypesApi } from "@/api/crm";
import { duplicateAssetId } from "@/lib/crm";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { modelYears, selectionLabel, VehicleSelection } from "@/lib/vehicles";

const opt = (rows?: { code: string; label: string }[]) => (rows ?? []).map((r) => ({ value: r.code, label: r.label }));

/** Insured object: type from GET /risk-asset-types; vehicles use the vehicle picker, other types a name + reference. */
export default function NewAsset() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<{ type?: string }>();
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
  const years = useMemo(() => modelYears(reference?.model_years).map((y) => ({ value: y, label: y })), [reference]);
  const set = (k: string) => (v: string) => setDetails((d) => ({ ...d, [k]: v }));

  const typeOptions = (types.data?.length ? types.data : [{ code: "VEHICLE", label: t("assetVehicle") }]).map((x) => ({ value: x.code, label: td(`assetType_${x.code}`, x.label) }));

  const saveObject = async () => {
    setSaving(true);
    setSaveError(null);
    setDuplicateId(null);
    try {
      const a = await AssetsApi.createObject({ type: assetType, display_name: label.trim(), external_reference: registration.trim() || null });
      router.replace(`/assets/${a.id}`);
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
    const reg = (registration || vehicle?.registration_number || "").trim().toUpperCase();
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
      const a = await AssetsApi.createVehicle({ display_name: label.trim() || selectionLabel({ make: vehicle?.make, model: vehicle?.model }) || t("vehicleDefaultNickname"), registration_number: reg, facts });
      router.replace(`/assets/${a.id}/scan`);
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
      <Button label={t("assetOpenExisting")} variant="secondary" onPress={() => router.replace(`/assets/${duplicateId}`)} />
    </Card>
  ) : null;

  if (!isVehicle)
    return (
      <Screen>
        <AppHeader title={t("assetAddTitle")} subtitle={t("assetAddSubtitle")} back />
        <Card>
          <PickerField label={t("assetTypeLabel")} value={assetType} options={typeOptions} onChange={setAssetType} />
        </Card>
        <Card>
          <TextField label={t("assetDisplayName")} value={label} onChangeText={setLabel} />
          <TextField label={t("assetReference")} value={registration} onChangeText={setRegistration} />
          {duplicate}
          {saveError && !duplicateId ? <ErrorCard error={saveError} fallback={t("vehicleSaveError")} onRetry={() => void saveObject()} /> : null}
          <Button label={t("assetSave")} loading={saving} disabled={!label.trim()} onPress={() => void saveObject()} />
        </Card>
      </Screen>
    );

  return (
    <Screen>
      <AppHeader title={t("vehicleAddTitle")} subtitle={t("vehicleAddSubtitle")} back />
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
        {duplicate}
        {saveError && !duplicateId ? <ErrorCard error={saveError} fallback={t("vehicleSaveError")} onRetry={() => void save()} /> : null}
        {!vehicle?.model ? <Text style={ps.meta}>{t("vehicleChooseMakeFirst")}</Text> : null}
        <Button label={t("vehicleSaveAndScan")} loading={saving} disabled={!(registration.trim() || vehicle?.registration_number) || !vehicle?.model} onPress={() => void save()} />
      </Card>
    </Screen>
  );
}
