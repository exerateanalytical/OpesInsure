import React from "react";
import { useLocalSearchParams, router } from "expo-router";
import { StyleSheet, View } from "react-native";
import { Building2, CarFront, Package, ScanLine } from "lucide-react-native";
import { Button, Screen, StatusChip } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { SummaryCard, SummaryField } from "@/components/forms/SchemaSummary";
import { StatePanel } from "@/components/StatePanel";
import { AssetsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { normalizeAsset } from "@/lib/riskAsset";
import { useTranslation } from "@/i18n";
import { space } from "@/theme/tokens";

export default function Asset() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data: a, loading, error, reload } = useLoad(async () => normalizeAsset(await AssetsApi.show(id)), [id]);
  return (
    <Screen>
      <BrandHeader title={a?.label ?? t("assetVehicle")} back right={null} />
      <StatePanel loading={loading} error={error} data={a} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("assetsLoading")}>
        {(a) => {
          const verified = a.status === "VERIFIED" || a.status === "ACTIVE";
          const vehicle = a.type === "VEHICLE";
          // Details on file read as a profile card (label above value); missing ones say so.
          const rows: [string, string | null | undefined][] = [
            [t("fltType"), a.type ? td(`assetType_${a.type}`, a.type) : null],
            ...(vehicle
              ? ([
                  [t("vehicleRegistration"), a.registration_number],
                  [t("vehicleMake"), a.make],
                  [t("vehicleModel"), a.model],
                  [t("vehicleYear"), a.year ? String(a.year) : null],
                ] as [string, string | null | undefined][])
              : []),
          ];
          return (
            <>
              <SummaryCard icon={vehicle ? CarFront : a.type === "PROPERTY" ? Building2 : Package} title={a.registration_number ?? a.label ?? t("assetVehicle")}>
                <View style={s.chip}>
                  <StatusChip label={td(`status_${a.status}`, a.status)} tone={verified ? "success" : "warning"} />
                </View>
                {rows.map(([label, value]) => (
                  <SummaryField key={label} label={label} value={value} />
                ))}
              </SummaryCard>
              {vehicle ? <Button label={t("assetScanCard")} icon={ScanLine} onPress={() => router.push(`/assets/${id}/scan`)} /> : null}
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  chip: { flexDirection: "row", paddingBottom: space.x2 },
});
