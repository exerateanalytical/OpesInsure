import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as DocumentPicker from "expo-document-picker";
import { ArrowUpCircle, Paperclip, Send } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { SupportApi } from "@/api/client";
import { CustomerApi } from "@/api/customer";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const CLOSED = ["RESOLVED", "CLOSED", "CANCELLED"];
/** Categories the server already treats as HIGH priority
 * (MobileSupportController::store). */
const HIGH = ["PAYMENT", "CLAIM", "FRAUD", "SECURITY"];

export default function SupportDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, date } = useTranslation();
  const q = useLoad(() => SupportApi.show(id), [id]);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState<"reply" | "attach" | "escalate" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const run = async (kind: "reply" | "attach" | "escalate", fn: () => Promise<void>) => {
    setBusy(kind);
    setError(null);
    setNotice(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof Error ? e.message : t("actionFailed"));
    } finally {
      setBusy(null);
    }
  };
  const reply = () =>
    run("reply", async () => {
      q.setData(await SupportApi.reply(id, message.trim()));
      setMessage("");
    });
  const attach = () =>
    run("attach", async () => {
      const picked = await DocumentPicker.getDocumentAsync({
        type: ["image/jpeg", "image/png", "application/pdf"],
        copyToCacheDirectory: true,
      });
      const asset = picked.assets?.[0];
      if (picked.canceled || !asset) return;
      const form = new FormData();
      // The server validates a "file" field (jpg, png or pdf, 10 MB).
      form.append("file", {
        uri: asset.uri,
        name: asset.name,
        type: asset.mimeType ?? "application/octet-stream",
      } as unknown as Blob);
      q.setData(await SupportApi.upload(id, form));
      setNotice(t("supportAttached"));
    });
  /** Escalation: a HIGH-priority follow-up case referencing this one. */
  const escalate = () =>
    run("escalate", async () => {
      const current = q.data;
      if (!current) return;
      const category = HIGH.includes(current.category) ? current.category : "SECURITY";
      const created = await CustomerApi.createSupportCase({
        category,
        priority: "HIGH",
        parent_case_id: current.id,
        subject: t("supportEscalationSubject", { ref: current.reference }).slice(0, 200),
        description: t("supportEscalationBody", { ref: current.reference, subject: current.subject }),
      });
      router.replace({ pathname: "/support/[id]", params: { id: created.id } });
    });

  return (
    <Screen>
      <AppHeader title={q.data?.reference ?? t("supportCase")} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(c) => {
          const closed = CLOSED.includes(c.status);
          return (
            <>
              <Card>
                <View style={styles.chips}>
                  <StatusChip label={td(`supportStatus_${c.status}`, c.status)} tone={closed ? "neutral" : "info"} />
                  <StatusChip label={td(`priority_${c.priority}`, c.priority)} tone={c.priority === "HIGH" ? "warning" : "neutral"} />
                </View>
                <Text style={styles.title}>{c.subject}</Text>
                <Text style={styles.body}>{c.description}</Text>
                <Text style={styles.meta}>
                  {td(`supportCategory_${c.category}`, c.category)} · {date(c.created_at)}
                </Text>
              </Card>
              {(c.messages ?? []).map((v) => (
                <View key={v.id} style={[styles.bubble, v.sender === "CUSTOMER" ? styles.mine : styles.theirs]}>
                  <Text style={styles.sender}>{v.sender === "CUSTOMER" ? t("you") : t("supportTeam")}</Text>
                  <Text style={styles.body}>{v.body}</Text>
                  <Text style={styles.meta}>{date(v.created_at)}</Text>
                </View>
              ))}
              {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
              {notice ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}
              {closed ? (
                <Card>
                  <Text style={styles.body}>{t("supportClosed")}</Text>
                  <Button label={t("supportNewTicket")} variant="secondary" onPress={() => router.push("/support/new")} />
                </Card>
              ) : (
                <Card>
                  <TextField label={t("supportReply")} multiline value={message} onChangeText={setMessage} style={styles.area} />
                  <Button label={t("supportSend")} icon={Send} loading={busy === "reply"} disabled={!message.trim() || !!busy} onPress={() => void reply()} />
                  <Button label={t("supportAttach")} icon={Paperclip} variant="secondary" loading={busy === "attach"} disabled={!!busy} onPress={() => void attach()} />
                  {c.priority !== "HIGH" ? (
                    <>
                      <Text style={styles.meta}>{t("supportEscalateHint")}</Text>
                      <Button label={t("supportEscalate")} icon={ArrowUpCircle} variant="tertiary" loading={busy === "escalate"} disabled={!!busy} onPress={() => void escalate()} />
                    </>
                  ) : null}
                </Card>
              )}
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  chips: { flexDirection: "row", gap: space.x2, flexWrap: "wrap" },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
  sender: { ...type.caption, color: colors.neutral600 },
  bubble: { borderRadius: radius.card, padding: space.x3, gap: space.x1, maxWidth: "92%" },
  mine: { alignSelf: "flex-end", backgroundColor: colors.blue50 },
  theirs: { alignSelf: "flex-start", backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200 },
  area: { minHeight: 96, textAlignVertical: "top", paddingTop: 12 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
