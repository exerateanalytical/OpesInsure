import React, { useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight } from "lucide-react-native";
import { AppHeader, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi, type Institution } from "@/api/extra";
import { useTranslation } from "@/i18n";
import { REGISTER_SOURCE_KEY, filterBrokers } from "@/lib/institutions";
import { colors, radius, space, type } from "@/theme/tokens";

/** Authorized brokers from the DGTCFM/MINFI 2026 register, in regulator order. */
export default function Brokers() {
  const { t } = useTranslation();
  const [query, setQuery] = useState("");
  const q = useLoad(() => InstitutionsApi.list("broker"), []);
  const filtered = useMemo(() => filterBrokers(q.data ?? [], query), [q.data, query]);
  const official = (q.data ?? []).filter((b) => b.is_official_register).length;

  return (
    <Screen>
      <AppHeader
        title={t("authorizedBrokers", { count: official || (q.data?.length ?? 0) })}
        subtitle={t(REGISTER_SOURCE_KEY)}
        back
      />
      <TextField
        label={t("searchBrokers")}
        value={query}
        onChangeText={setQuery}
        placeholder={t("searchBrokersPlaceholder")}
      />
      <Text style={styles.note}>{t("brokerVerifyNote")}</Text>
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("loadingBrokers")}
        emptyTitle={t("noBrokersFound")}
        emptyMessage={t("registerEmptyBody")}
      >
        {() =>
          filtered.length ? (
            <>
              {filtered.map((b) => (
                <BrokerRow key={b.id} broker={b} />
              ))}
            </>
          ) : (
            <Card>
              <Text style={styles.name}>{t("noBrokersFound")}</Text>
            </Card>
          )
        }
      </StatePanel>
      <Text style={styles.source}>{t(REGISTER_SOURCE_KEY)}</Text>
    </Screen>
  );
}

function BrokerRow({ broker }: { broker: Institution }) {
  const { t } = useTranslation();
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={broker.name}
      onPress={() => router.push({ pathname: "/institutions/broker/[id]", params: { id: broker.id } })}
    >
      <Card>
        <View style={styles.row}>
          <View style={styles.number}>
            <Text style={styles.numberText}>{broker.regulator_number ?? "—"}</Text>
          </View>
          <View style={styles.copy}>
            <Text style={styles.name}>{broker.name}</Text>
            <Text style={styles.meta}>
              {[
                broker.regulator_number ? t("regulatorNumber", { number: broker.regulator_number }) : null,
                broker.city,
                broker.phone,
              ]
                .filter(Boolean)
                .join(" · ")}
            </Text>
          </View>
          {broker.licensed ? <StatusChip label={t("licensedStatus")} tone="success" /> : null}
          <ChevronRight size={20} color={colors.neutral500} />
        </View>
      </Card>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  note: { ...type.meta, color: colors.neutral600 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  copy: { flex: 1, gap: 3 },
  number: {
    minWidth: 42,
    height: 42,
    borderRadius: radius.control,
    borderWidth: 1,
    borderColor: colors.neutral200,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 4,
  },
  numberText: { ...type.caption, color: colors.navy800, fontVariant: ["tabular-nums"] },
  name: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  source: { ...type.meta, color: colors.neutral500, textAlign: "center" },
});
