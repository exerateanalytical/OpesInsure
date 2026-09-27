import React, { useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronDown, ChevronUp, History, KeyRound, ShieldAlert, ShieldCheck, SlidersHorizontal } from "lucide-react-native";
import { AccountApi, LoginActivity } from "@/api/client";
import { Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { FreshnessNote } from "@/components/Freshness";
import { AgentCard, AgentEmptyState, AgentNavRow, AgentSection, AgentShell } from "@/components/agent";
import { AgentLoadGate, AgentOfflineNote } from "@/components/security/AgentStates";
import { useLoginEventLabel } from "@/components/security/loginEvent";
import { activeFilterCount, FiltersSheet, type FilterSection, type FilterValues } from "@/components/customer/FiltersSheet";
import { useFreshLoad } from "@/hooks/useFreshLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useIsAgentPortal } from "@/hooks/useIsAgentPortal";
import { activityDevices, groupLoginActivity, loginStatus, matchesDevice, timeOf, type LoginCluster, type SessionStatus } from "@/lib/securityActivity";
import { useTimezone } from "@/store/timezone";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { agentColors as c, agentIcon, agentType as T } from "@/theme/agent";

const STATUSES: SessionStatus[] = ["Recognized", "New Device", "Suspicious", "Signed Out"];

/**
 * Account > Security > Login activity (SEC-ACC-002). Server rows only; the
 * location shown is the server-derived country, never device GPS. The agent
 * portal gets the spec v2 screen 04 layout (grouped by day, collapsed repeats,
 * device filter sheet, rows open the Login Event Detail).
 */
export default function LoginActivityScreen() {
  const { t, td } = useTranslation();
  const agent = useIsAgentPortal();
  const f = useFormatters();
  const { data, loading, error, reload, lastUpdatedAt, stale } = useFreshLoad(() => AccountApi.loginActivity(), [], "security");
  if (agent) return <AgentLoginActivity data={data} loading={loading} error={error} reload={reload} lastUpdatedAt={lastUpdatedAt} stale={stale} />;
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
              const event = (row.event_type ?? "").toUpperCase();
              // Non sign-in events (sign-out, OTP, step-up, attestation...) show their own label.
              const eventLabel = event && event !== "LOGIN" ? td(`loginEvent_${event}`, event.replaceAll("_", " ").toLowerCase()) : null;
              const outcome = (row.outcome ?? "").toUpperCase();
              return (
                <Card key={row.id} style={styles.card} onPress={() => router.push({ pathname: "/account/login-activity/[id]", params: { id: row.id } })}>
                  <View style={styles.row}>
                    <TintedIcon
                      icon={risky ? ShieldAlert : row.new_device ? KeyRound : ShieldCheck}
                      tint={risky ? "red" : row.new_device ? "gold" : "green"}
                      size={44}
                    />
                    <View style={styles.flex}>
                      <Text style={styles.title}>{eventLabel ?? t(failed ? "secActivityFailed" : "secActivitySignIn", { method })}</Text>
                      <Text style={styles.body}>{f.dateTime(row.occurred_at)}</Text>
                      <Text style={styles.body}>{meta}</Text>
                    </View>
                  </View>
                  {row.new_device || flags.length || outcome ? (
                    <View style={styles.chips}>
                      {outcome ? (
                        <StatusChip label={td(`loginOutcome_${outcome}`, outcome.replaceAll("_", " ").toLowerCase())} tone={failed ? "danger" : "success"} />
                      ) : null}
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

function AgentLoginActivity({
  data,
  loading,
  error,
  reload,
  lastUpdatedAt,
  stale,
}: {
  data: LoginActivity[] | null | undefined;
  loading: boolean;
  error: unknown;
  reload: () => Promise<unknown> | void;
  lastUpdatedAt: number | null;
  stale: boolean;
}) {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const timeZone = useTimezone((s) => s.timezone);
  const eventLabel = useLoginEventLabel();
  const [sheet, setSheet] = useState(false);
  const [values, setValues] = useState<FilterValues>({ device: [], status: [] });
  const [open, setOpen] = useState<Record<string, boolean>>({});
  const rows = useMemo(() => data ?? [], [data]);
  const sections: FilterSection[] = useMemo(
    () => [
      { key: "device", title: t("laFilterTitle"), options: activityDevices(rows).map((d) => ({ value: d.id, label: d.label })) },
      { key: "status", title: t("laFieldStatus"), options: STATUSES.map((s) => ({ value: s, label: td(`agentSt_${s.replace(" ", "")}`, s) })) },
    ],
    [rows, t, td],
  );
  const apply = (v: FilterValues) =>
    rows.filter(
      (r) =>
        (!(v.device ?? []).length || (v.device ?? []).some((d) => matchesDevice(r, d))) &&
        (!(v.status ?? []).length || (v.status ?? []).includes(loginStatus(r))),
    );
  const filtered = apply(values);
  const days = groupLoginActivity(filtered, timeZone);
  const active = activeFilterCount(values, sections);
  const picked = values.device ?? [];
  const deviceLabel = picked.length
    ? (sections[0]?.options ?? []).filter((o) => picked.includes(o.value)).map((o) => o.label).join(", ")
    : t("laAllDevices");
  const openEvent = (row: LoginActivity) => router.push({ pathname: "/account/login-activity/[id]", params: { id: row.id } });
  const where = (row: LoginActivity) => [row.device_name ?? row.platform ?? t("secActivityUnknownDevice"), timeOf(row.occurred_at, timeZone, f.language), row.country_code].filter(Boolean).join(" · ");
  const single = (row: LoginActivity, divider: boolean) => (
    <AgentNavRow
      key={row.id}
      icon={History}
      title={eventLabel(row)}
      subtitle={where(row)}
      status={loginStatus(row)}
      divider={divider}
      accessibilityLabel={t("laOpenEvent", { device: row.device_name ?? row.platform ?? t("secActivityUnknownDevice") })}
      onPress={() => openEvent(row)}
    />
  );
  const cluster = (cl: LoginCluster, divider: boolean) => {
    if (cl.rows.length === 1) return single(cl.latest, divider);
    const expanded = !!open[cl.key];
    const Icon = expanded ? ChevronUp : ChevronDown;
    return (
      <View key={cl.key}>
        <AgentNavRow
          icon={History}
          title={cl.latest.device_name ?? cl.latest.platform ?? t("secActivityUnknownDevice")}
          subtitle={t("laCollapsed", { n: cl.rows.length, time: timeOf(cl.latest.occurred_at, timeZone, f.language) })}
          status={loginStatus(cl.latest)}
          divider={divider}
          chevron={false}
          right={<Icon size={agentIcon.small} color={c.muted} strokeWidth={agentIcon.stroke} />}
          onPress={() => setOpen((o) => ({ ...o, [cl.key]: !expanded }))}
        />
        {expanded ? <View style={a.nested}>{cl.rows.map((r) => single(r, true))}</View> : null}
      </View>
    );
  };
  return (
    <AgentShell
      variant="drilldown"
      title={t("secActivityTitle")}
      refreshing={loading && !!data}
      onRefresh={() => void reload()}
      headerRight={
        <Pressable accessibilityRole="button" accessibilityLabel={active ? `${t("laFilter")} (${active})` : t("laFilter")} hitSlop={6} onPress={() => setSheet(true)} style={a.filterBtn}>
          <SlidersHorizontal size={agentIcon.action} color={c.navy} strokeWidth={agentIcon.stroke} />
          {active ? <View style={a.dot} /> : null}
        </Pressable>
      }
    >
      <AgentOfflineNote />
      <FreshnessNote lastUpdatedAt={lastUpdatedAt} stale={stale} />
      <Pressable accessibilityRole="button" accessibilityLabel={`${t("laFilterTitle")}: ${deviceLabel}`} onPress={() => setSheet(true)} style={a.device}>
        <Text style={a.deviceText} numberOfLines={1}>{deviceLabel}</Text>
        <ChevronDown size={agentIcon.small} color={c.navy} strokeWidth={agentIcon.stroke} />
      </Pressable>
      <AgentLoadGate loading={loading} error={error} data={data} onRetry={() => void reload()} rows={6}>
        {() =>
          days.length ? (
            <>
              {days.map((day) => (
                <AgentSection
                  key={day.day}
                  title={day.relative === "today" ? t("today") : day.relative === "yesterday" ? t("yesterday") : f.date(day.clusters[0]?.latest.occurred_at)}
                >
                  <AgentCard padded={false}>{day.clusters.map((cl, i) => cluster(cl, i > 0))}</AgentCard>
                </AgentSection>
              ))}
            </>
          ) : (
            <AgentEmptyState icon={History} title={t("laEmpty")} body={t("laEmptyBody")} />
          )
        }
      </AgentLoadGate>
      <FiltersSheet
        visible={sheet}
        onClose={() => setSheet(false)}
        sections={sections}
        value={values}
        onApply={(v) => {
          setValues(v);
          setSheet(false);
        }}
        count={(v) => apply(v).length}
      />
    </AgentShell>
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
const a = StyleSheet.create({
  filterBtn: { width: 44, height: 44, borderRadius: 22, alignItems: "center", justifyContent: "center", backgroundColor: c.surface, borderWidth: 1, borderColor: c.border },
  dot: { position: "absolute", top: 9, right: 9, width: 8, height: 8, borderRadius: 4, backgroundColor: c.gold },
  device: { minHeight: 48, flexDirection: "row", alignItems: "center", gap: 8, paddingHorizontal: 14, borderRadius: 14, borderWidth: 1, borderColor: c.borderStrong, backgroundColor: c.surface },
  deviceText: { ...T.body, color: c.text, flex: 1 },
  nested: { backgroundColor: c.surfaceSoft },
});
