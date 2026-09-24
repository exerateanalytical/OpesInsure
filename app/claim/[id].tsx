import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Camera, FileCheck2, Paperclip } from "lucide-react-native";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  SectionTitle,
  StatusChip,
} from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { colors, space, type } from "@/theme/tokens";

const human = (v?: string | null) => (v ? v.replaceAll("_", " ") : "");

export default function ClaimDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => ClaimsApi.show(id), [id]);
  const timeline = useLoad(() => ClaimRecordsApi.timeline(id), [id]);
  const evidence = useLoad(() => ClaimRecordsApi.evidence(id), [id]);
  const go = (pathname: string) =>
    router.push({ pathname: pathname as never, params: { id } });
  return (
    <Screen>
      <AppHeader title="Claim details" subtitle={q.data?.claim_number} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false}>
        {(claim) => (
          <>
            <Card>
              <StatusChip
                label={human(claim.status)}
                tone={
                  claim.status === "REJECTED"
                    ? "danger"
                    : claim.status === "SETTLED"
                      ? "success"
                      : "info"
                }
              />
              <Text style={styles.title}>{claim.claim_number}</Text>
              <Text style={styles.body}>
                Incident: {new Date(claim.incident_at).toLocaleString()}
              </Text>
              {claim.incident_location ? (
                <Text style={styles.body}>
                  Location: {claim.incident_location}
                </Text>
              ) : null}
            </Card>
            <Card>
              <Text style={styles.title}>Your report</Text>
              <Text style={styles.body}>{claim.description}</Text>
            </Card>
            <Button
              label="Complete incident and parties"
              variant="secondary"
              onPress={() => go("/claim/[id]/incident")}
            />
            <Button
              label="Add or review evidence"
              icon={Camera}
              variant="secondary"
              onPress={() => go("/claim/[id]/evidence")}
            />
            <Button
              label="Evidence requirements"
              variant="secondary"
              onPress={() => go("/claim/[id]/checklist")}
            />
            <Button
              label="Inspection and repair"
              variant="secondary"
              onPress={() => go("/claim/[id]/inspection")}
            />
            <Button
              label="Settlement and payment"
              variant="secondary"
              onPress={() => go("/claim/[id]/settlement")}
            />
            {claim.status === "REJECTED" ? (
              <Button
                label="Appeal decision"
                variant="secondary"
                onPress={() => go("/claim/[id]/appeal")}
              />
            ) : null}
          </>
        )}
      </StatePanel>
      <SectionTitle title="Evidence submitted" />
      <StatePanel
        {...evidence}
        onRetry={evidence.reload}
        emptyTitle="No evidence yet"
        emptyMessage="Photos and documents you attach to this claim appear here."
      >
        {(items) => (
          <Card>
            {items.map((e) => (
              <View key={e.id} style={styles.row}>
                <Paperclip size={19} color={colors.blue600} />
                <View style={styles.flex}>
                  <Text style={styles.event}>{human(e.evidence_type)}</Text>
                  {e.submitted_at ? (
                    <Text style={styles.meta}>
                      {new Date(e.submitted_at).toLocaleString()}
                    </Text>
                  ) : null}
                </View>
                <StatusChip
                  label={human(e.status)}
                  tone={
                    e.status === "VERIFIED"
                      ? "success"
                      : e.status === "REJECTED"
                        ? "danger"
                        : "neutral"
                  }
                />
              </View>
            ))}
          </Card>
        )}
      </StatePanel>
      <SectionTitle title="Timeline" />
      <StatePanel
        {...timeline}
        onRetry={timeline.reload}
        emptyTitle="No updates yet"
        emptyMessage="Each step of your claim will be recorded here."
      >
        {(events) => (
          <Card>
            {events.map((event) => (
              <View key={event.id} style={styles.row}>
                <FileCheck2 size={19} color={colors.blue600} />
                <View style={styles.flex}>
                  <Text style={styles.event}>
                    {event.to_status ? human(event.to_status) : human(event.type)}
                  </Text>
                  <Text style={styles.meta}>
                    {new Date(event.occurred_at).toLocaleString()}
                  </Text>
                </View>
              </View>
            ))}
          </Card>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    gap: space.x3,
    alignItems: "center",
    paddingVertical: space.x2,
  },
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  event: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
});
