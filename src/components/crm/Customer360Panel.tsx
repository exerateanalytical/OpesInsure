import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { Card, SectionTitle } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { Customer360Api } from "@/api/crm";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";
import { AgentCard, AgentSection, AgentSkeleton } from "@/components/agent";
import { agentColors as ac, agentType as aT } from "@/theme/agent";

/** REQ-CRM-002 Customer 360 (GET /customers/{party}/overview). Renders nothing without a party id or when not permitted. */
export function Customer360Panel({ partyId, variant = "default" }: { partyId?: string | null; /** "agent" = Commercial Agent spec v2 section; other portals keep "default". */ variant?: "default" | "agent" }) {
  const { t, td, date } = useTranslation();
  const q = useLoad(() => (partyId ? Customer360Api.overview(partyId) : Promise.resolve(null)), [partyId]);
  const status = (q.error as { status?: number } | null)?.status;
  if (!partyId || status === 403 || status === 404) return null;
  if (variant === "agent") {
    const o = q.data;
    // Optional enrichment: while loading show a skeleton; on failure / no data the 360 section is simply omitted.
    if (q.loading && !o) return <AgentSkeleton rows={2} />;
    if (!o) return null;
    const lines = (items: string[]) => items.map((x, i) => <Text key={i} style={[a.line, i > 0 && a.divider]}>{x}</Text>);
    return (
      <AgentSection title={t("c360Title")}>
        <AgentCard>
          {o.customer.customer_number ? <Text style={a.meta}>{t("c360CustomerNumber", { n: o.customer.customer_number })}</Text> : null}
          <View style={a.counts}>
            <Text style={a.count}>{t("c360Policies", { n: o.counts.policies })}</Text>
            <Text style={a.count}>{t("c360Claims", { n: o.counts.claims })}</Text>
            <Text style={a.count}>{t("c360Documents", { n: o.counts.documents })}</Text>
          </View>
          {o.kyc[0] ? <Text style={a.meta}>{t("c360Kyc")}: {td(`kycStatus_${o.kyc[0].status}`, o.kyc[0].status)}</Text> : null}
        </AgentCard>
        {o.policies.length ? <AgentCard>{lines(o.policies.slice(0, 10).map((p) => `${p.policy_number ?? "—"} · ${td(`status_${p.status}`, p.status)} · ${date(p.coverage_ends_at)}`))}</AgentCard> : null}
        {o.claims.length ? <AgentCard>{lines(o.claims.slice(0, 10).map((c) => `${c.claim_number} · ${td(`claimStatus_${c.status}`, c.status)}`))}</AgentCard> : null}
        {o.beneficiaries.length ? (
          <AgentCard>
            <Text style={a.head}>{t("benTitle")}</Text>
            {lines(o.beneficiaries.map((b) => (b.restricted ? t("c360Restricted") : `${b.full_name ?? "—"} · ${b.allocation_pct ?? 0}%`)))}
          </AgentCard>
        ) : null}
        {o.timeline.length ? (
          <AgentCard>
            <Text style={a.head}>{t("c360Timeline")}</Text>
            {lines(o.timeline.slice(0, 15).map((e) => `${date(e.at)} · ${td(`c360Kind_${e.kind}`, e.kind)} · ${td(`status_${e.label}`, e.label)}`))}
          </AgentCard>
        ) : null}
      </AgentSection>
    );
  }
  return (
    <>
      <SectionTitle title={t("c360Title")} />
      <StatePanel {...q} data={q.data ?? (q.loading || q.error ? undefined : null)} onRetry={q.reload} isEmpty={(d) => !d} emptyTitle={t("c360Title")} emptyMessage={t("c360Unavailable")}>
        {(o) =>
          o ? (
            <>
              <Card>
                {o.customer.customer_number ? <Text style={s.meta}>{t("c360CustomerNumber", { n: o.customer.customer_number })}</Text> : null}
                <View style={s.counts}>
                  <Text style={s.count}>{t("c360Policies", { n: o.counts.policies })}</Text>
                  <Text style={s.count}>{t("c360Claims", { n: o.counts.claims })}</Text>
                  <Text style={s.count}>{t("c360Documents", { n: o.counts.documents })}</Text>
                </View>
                {o.kyc[0] ? <Text style={s.meta}>{t("c360Kyc")}: {td(`kycStatus_${o.kyc[0].status}`, o.kyc[0].status)}</Text> : null}
              </Card>
              {o.policies.length ? (
                <Card>
                  {o.policies.slice(0, 10).map((p) => (
                    <Text key={p.id} style={s.body}>
                      {p.policy_number ?? "—"} · {td(`status_${p.status}`, p.status)} · {date(p.coverage_ends_at)}
                    </Text>
                  ))}
                </Card>
              ) : null}
              {o.claims.length ? (
                <Card>
                  {o.claims.slice(0, 10).map((c) => (
                    <Text key={c.id} style={s.body}>
                      {c.claim_number} · {td(`claimStatus_${c.status}`, c.status)}
                    </Text>
                  ))}
                </Card>
              ) : null}
              {o.beneficiaries.length ? (
                <Card>
                  <Text style={s.head}>{t("benTitle")}</Text>
                  {o.beneficiaries.map((b) => (
                    <Text key={b.id} style={s.body}>
                      {b.restricted ? t("c360Restricted") : `${b.full_name ?? "—"} · ${b.allocation_pct ?? 0}%`}
                    </Text>
                  ))}
                </Card>
              ) : null}
              {o.timeline.length ? (
                <Card>
                  <Text style={s.head}>{t("c360Timeline")}</Text>
                  {o.timeline.slice(0, 15).map((e) => (
                    <Text key={`${e.kind}-${e.id}`} style={s.body}>
                      {date(e.at)} · {td(`c360Kind_${e.kind}`, e.kind)} · {td(`status_${e.label}`, e.label)}
                    </Text>
                  ))}
                </Card>
              ) : null}
            </>
          ) : null
        }
      </StatePanel>
    </>
  );
}

const s = StyleSheet.create({
  counts: { flexDirection: "row", flexWrap: "wrap", gap: space.x3 },
  count: { ...type.body, color: colors.navy950 },
  head: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
});

const a = StyleSheet.create({
  counts: { flexDirection: "row", flexWrap: "wrap", gap: 16, marginVertical: 4 },
  count: { ...aT.cardTitle, color: ac.heading },
  head: { ...aT.cardTitle, color: ac.heading, marginBottom: 4 },
  line: { ...aT.secondary, color: ac.text, paddingVertical: 8 },
  divider: { borderTopWidth: 1, borderTopColor: ac.border },
  meta: { ...aT.secondary, color: ac.secondary },
});
