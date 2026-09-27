import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { KeyRound, ShieldAlert, ShieldCheck } from "lucide-react-native";
import { AccountApi } from "@/api/client";
import { Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { FreshnessNote } from "@/components/Freshness";
import { useFreshLoad } from "@/hooks/useFreshLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Account > Security > Login activity (SEC-ACC-002). Server rows only; the
 * location shown is the server-derived country, never device GPS.
 */
export default function LoginActivityScreen() {
  const { t } = useTranslation();
  const f = useFormatters();
  const { data, loading, error, reload, lastUpdatedAt, stale } = useFreshLoad(() => AccountApi.loginActivity(), [], "security");
  return (
    <Screen>
      <BrandHeader title={t("secActivityTitle")} subtitle={t("secActivitySubtitle")} back right={null} />
      <FreshnessNote lastUpdatedAt={lastUpdatedAt} stale={stale} />
      <StatePanel
        loading={loading}
        error={error}
        data={data}
        onRetry={() => void reload()}
        loadingLabel={t("secActivityLoading")}
        emptyTitle={t("secActivityEmpty")}
        emptyMessage={t("secActivityEmptyBody")}
      >
        {(rows) => (
          <>
            {rows.map((row) => {
              const flags = (row.anomaly_flags ?? []).filter((flag) => flag !== "NEW_DEVICE");
              const failed = row.outcome ? row.outcome.toUpperCase() !== "SUCCESS" : false;
              const risky = flags.length > 0 || failed;
              const meta = [
                row.device_name ?? row.platform ?? t("secActivityUnknownDevice"),
                row.app_version ? `v${row.app_version}` : null,
                row.country_code ? t("secActivityCountry", { country: row.country_code }) : null,
                row.masked_ip ?? null,
              ]
                .filter(Boolean)
                .join(" · ");
              const method = (row.method ?? "").replaceAll("_", " ").toLowerCase() || "-";
              return (
                <Card key={row.id} style={styles.card}>
                  <View style={styles.row}>
                    <TintedIcon
                      icon={risky ? ShieldAlert : row.new_device ? KeyRound : ShieldCheck}
                      tint={risky ? "red" : row.new_device ? "gold" : "green"}
                      size={44}
                    />
                    <View style={styles.flex}>
                      <Text style={styles.title}>{t(failed ? "secActivityFailed" : "secActivitySignIn", { method })}</Text>
                      <Text style={styles.body}>{f.dateTime(row.occurred_at)}</Text>
                      <Text style={styles.body}>{meta}</Text>
                    </View>
                  </View>
                  {row.new_device || flags.length ? (
                    <View style={styles.chips}>
                      {row.new_device ? <StatusChip label={t("secActivityNewDevice")} tone="warning" /> : null}
                      {flags.map((flag) => (
                        <StatusChip key={flag} label={flag.replaceAll("_", " ")} tone="danger" />
                      ))}
                    </View>
                  ) : null}
                </Card>
              );
            })}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  body: { ...type.meta, color: colors.neutral600 },
});
