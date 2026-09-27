import React, { useState } from "react";
import { Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as DocumentPicker from "expo-document-picker";
import { ArrowUpCircle, CalendarDays, ChevronRight, Clock3, FileText, Flag, Headset, MessageSquare, MessagesSquare, Paperclip, Send, Tag, Ticket, User } from "lucide-react-native";
import { Button, Card, Screen, ripple } from "@/components/ui";
import { BrandHeader, CtaBar, SectionHeading, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { SupportApi } from "@/api/client";
import { CustomerApi } from "@/api/customer";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { withoutRelock } from "@/lib/appLock";

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
      const picked = await withoutRelock(() => DocumentPicker.getDocumentAsync({
        type: ["image/jpeg", "image/png", "application/pdf"],
        copyToCacheDirectory: true,
      }));
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

  const open = q.data ? !CLOSED.includes(q.data.status) : false;

  return (
    <Screen
      footer={
        q.data && open ? (
          <CtaBar>
            <View style={styles.replyBar}>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t("supportAttach")}
                accessibilityState={{ busy: busy === "attach", disabled: !!busy }}
                disabled={!!busy}
                onPress={() => void attach()}
                android_ripple={ripple()}
                style={({ pressed }) => [styles.attachBtn, pressed && styles.pressed, !!busy && styles.disabled]}
              >
                <Paperclip size={20} color={colors.navy900} />
              </Pressable>
              <TextInput
                accessibilityLabel={t("supportReply")}
                value={message}
                onChangeText={setMessage}
                placeholder={t("supportReplyPlaceholder")}
                placeholderTextColor={colors.neutral500}
                multiline
                style={styles.replyInput}
              />
              <View style={styles.sendWrap}>
                <Button label={t("supportAddReply")} icon={Send} loading={busy === "reply"} disabled={!message.trim() || !!busy} onPress={() => void reply()} />
              </View>
            </View>
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("supportTicketDetail")} back right="help" />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(c) => {
          const closed = CLOSED.includes(c.status);
          const messages = c.messages ?? [];
          const attachments = c.attachments ?? [];
          const statusTone = closed ? styles.statusNeutral : styles.statusOpen;
          return (
            <>
              <View style={styles.card}>
                <View style={styles.headRow}>
                  <TintedIcon icon={Ticket} tint="blue" size={48} />
                  <View style={styles.flex}>
                    <View style={styles.refRow}>
                      <Text style={styles.reference}>{c.reference}</Text>
                      <View style={[styles.statusChip, statusTone]}>
                        <View style={[styles.dot, { backgroundColor: closed ? colors.neutral500 : colors.success }]} />
                        <Text style={[styles.statusText, { color: closed ? colors.neutral700 : colors.successText }]} numberOfLines={1}>{td(`supportStatus_${c.status}`, c.status)}</Text>
                      </View>
                    </View>
                    <Text style={styles.subject}>{c.subject}</Text>
                  </View>
                </View>
                <Text style={styles.body}>{c.description}</Text>
                <View style={styles.metaGrid}>
                  <View style={styles.metaCell}>
                    <TintedIcon icon={Flag} tint="gold" size={36} />
                    <View style={styles.metaText}>
                      <Text style={styles.metaLabel}>{t("supportPriority")}</Text>
                      <Text style={[styles.metaValue, c.priority === "HIGH" && styles.gold]}>{td(`priority_${c.priority}`, c.priority)}</Text>
                    </View>
                  </View>
                  <View style={[styles.metaCell, styles.metaBorder]}>
                    <TintedIcon icon={CalendarDays} tint="blue" size={36} />
                    <View style={styles.metaText}>
                      <Text style={styles.metaLabel}>{t("supportCreated")}</Text>
                      <Text style={styles.metaValue}>{date(c.created_at)}</Text>
                    </View>
                  </View>
                  <View style={[styles.metaCell, styles.metaBorder]}>
                    <TintedIcon icon={FileText} tint="blue" size={36} />
                    <View style={styles.metaText}>
                      <Text style={styles.metaLabel}>{t("supportCategoryLabel")}</Text>
                      <Text style={styles.metaValue}>{td(`supportCategory_${c.category}`, c.category)}</Text>
                    </View>
                  </View>
                </View>
              </View>

              <View style={styles.card}>
                <SectionHeading title={t("supportConversation")} icon={MessagesSquare} right={<View style={styles.countPill}><Text style={styles.countText}>{t("supportUpdates", { count: messages.length })}</Text></View>} />
                {messages.length ? (
                  <View style={styles.timeline}>
                    {messages.map((v, i) => {
                      const mine = v.sender === "CUSTOMER";
                      return (
                        <View key={v.id} style={styles.msgRow}>
                          <View style={styles.avatarCol}>
                            <TintedIcon icon={mine ? User : Headset} tint={mine ? "gold" : "blue"} size={48} />
                            {i < messages.length - 1 ? <View style={styles.rail} /> : null}
                          </View>
                          <View style={styles.flex}>
                            <View style={styles.msgHead}>
                              <Text style={styles.sender}>{mine ? t("you") : t("supportTeam")}</Text>
                              <Text style={styles.meta}>{date(v.created_at, true)}</Text>
                            </View>
                            <View style={[styles.bubble, mine ? styles.mine : styles.theirs]}>
                              <Text style={styles.body}>{v.body}</Text>
                            </View>
                          </View>
                        </View>
                      );
                    })}
                  </View>
                ) : (
                  <Text style={styles.meta}>{c.description}</Text>
                )}
              </View>

              <View style={styles.card}>
                <SectionHeading title={t("supportSummary")} icon={FileText} />
                <View style={styles.summaryGrid}>
                  <View style={styles.summaryCell}>
                    <TintedIcon icon={Tag} tint="blue" size={32} />
                    <View style={styles.flex}>
                      <Text style={styles.metaLabel}>{t("supportCategoryLabel")}</Text>
                      <Text style={styles.summaryValue} numberOfLines={2}>{td(`supportCategory_${c.category}`, c.category)}</Text>
                    </View>
                  </View>
                  <View style={[styles.summaryCell, styles.summaryBorder]}>
                    <TintedIcon icon={Flag} tint="blue" size={32} />
                    <View style={styles.flex}>
                      <Text style={styles.metaLabel}>{t("supportPriority")}</Text>
                      <Text style={styles.summaryValue} numberOfLines={2}>{td(`priority_${c.priority}`, c.priority)}</Text>
                    </View>
                  </View>
                  <View style={[styles.summaryCell, styles.summaryTop]}>
                    <TintedIcon icon={MessageSquare} tint="blue" size={32} />
                    <View style={styles.flex}>
                      <Text style={styles.metaLabel}>{t("supportChannel")}</Text>
                      <Text style={styles.summaryValue} numberOfLines={2}>{t("supportChannelApp")}</Text>
                    </View>
                  </View>
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={`${t("supportAttachments")}. ${t("supportFilesCount", { count: attachments.length })}`}
                    disabled={closed || !!busy}
                    onPress={() => void attach()}
                    style={({ pressed }) => [styles.summaryCell, styles.summaryBorder, styles.summaryTop, pressed && styles.pressed]}
                  >
                    <TintedIcon icon={Paperclip} tint="blue" size={32} />
                    <View style={styles.flex}>
                      <Text style={styles.metaLabel}>{t("supportAttachments")}</Text>
                      <Text style={styles.summaryValue}>{t("supportFilesCount", { count: attachments.length })}</Text>
                    </View>
                    {!closed ? <ChevronRight size={18} color={colors.navy800} /> : null}
                  </Pressable>
                </View>
                {attachments.length ? (
                  <View style={styles.files}>
                    {attachments.map((a) => (
                      <View key={a.id} style={styles.fileRow}>
                        <Paperclip size={16} color={colors.neutral600} />
                        <Text style={[styles.meta, styles.flex]} numberOfLines={1}>{a.file_name}</Text>
                        <Text style={styles.meta}>{td(`attachmentStatus_${a.status}`, a.status)}</Text>
                      </View>
                    ))}
                  </View>
                ) : null}
                {!closed ? (
                  <View style={styles.nextUpdate}>
                    <TintedIcon icon={Clock3} tint="gold" size={44} />
                    <View style={styles.flex}>
                      <View style={styles.nextHead}>
                        <Text style={[styles.summaryValue, styles.grow]}>{t("supportNextUpdate")}</Text>
                        <View style={styles.awaiting}>
                          <Text style={styles.awaitingText}>{c.status === "WAITING_CUSTOMER" ? td(`supportStatus_${c.status}`, c.status) : t("supportAwaitingResponse")}</Text>
                        </View>
                      </View>
                      <Text style={styles.meta}>{t("supportNextUpdateBody")}</Text>
                    </View>
                  </View>
                ) : null}
              </View>

              {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
              {notice ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}
              {closed ? (
                <Card>
                  <Text style={styles.body}>{t("supportClosed")}</Text>
                  <Button label={t("supportNewTicket")} variant="secondary" onPress={() => router.push("/support/new")} />
                </Card>
              ) : c.priority !== "HIGH" ? (
                <Card>
                  <Text style={styles.meta}>{t("supportEscalateHint")}</Text>
                  <Button label={t("supportEscalate")} icon={ArrowUpCircle} variant="tertiary" loading={busy === "escalate"} disabled={!!busy} onPress={() => void escalate()} />
                </Card>
              ) : null}
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  disabled: { opacity: 0.5 },
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3 },
  headRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  refRow: { flexDirection: "row", flexWrap: "wrap", alignItems: "flex-start", justifyContent: "space-between", gap: space.x2 },
  grow: { flexBasis: 90, flexGrow: 1, flexShrink: 1 },
  reference: { fontFamily: "Inter_700Bold", fontSize: 19, lineHeight: 25, color: colors.navy950, flexBasis: 150, flexGrow: 1, flexShrink: 1 },
  subject: { ...type.body, color: colors.neutral700, marginTop: 2 },
  statusChip: { flexDirection: "row", alignItems: "center", gap: 6, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 6, maxWidth: 140 },
  statusOpen: { backgroundColor: colors.successSoft },
  statusNeutral: { backgroundColor: colors.neutral100 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  statusText: { ...type.caption },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
  metaGrid: { flexDirection: "row", borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  metaText: { alignSelf: "stretch" },
  metaCell: { flex: 1, alignItems: "flex-start", gap: space.x1, paddingHorizontal: space.x2 },
  metaBorder: { borderLeftWidth: 1, borderLeftColor: colors.neutral200, paddingLeft: space.x2 },
  metaLabel: { ...type.meta, color: colors.neutral500 },
  metaValue: { ...type.label, color: colors.navy950 },
  gold: { color: colors.gold600 },
  countPill: { backgroundColor: colors.blue50, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 5 },
  countText: { ...type.caption, color: colors.blue700 },
  timeline: { gap: space.x4 },
  msgRow: { flexDirection: "row", gap: space.x3 },
  avatarCol: { alignItems: "center" },
  rail: { flex: 1, width: 2, backgroundColor: colors.blue100, marginTop: space.x2, minHeight: space.x4 },
  msgHead: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x2, marginBottom: space.x1 },
  sender: { ...type.label, color: colors.navy950 },
  bubble: { borderRadius: radius.card, padding: space.x3 },
  mine: { backgroundColor: colors.blue50 },
  theirs: { backgroundColor: colors.neutral100 },
  summaryGrid: { flexDirection: "row", flexWrap: "wrap", borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card },
  summaryCell: { flexBasis: 160, flexGrow: 1, flexDirection: "row", alignItems: "center", gap: space.x2, paddingVertical: space.x3, paddingHorizontal: space.x2 },
  summaryBorder: { borderLeftWidth: 1, borderLeftColor: colors.neutral200 },
  summaryTop: { borderTopWidth: 1, borderTopColor: colors.neutral200 },
  summaryValue: { ...type.label, color: colors.navy950 },
  files: { gap: space.x1 },
  fileRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  nextUpdate: { flexDirection: "row", alignItems: "flex-start", gap: space.x3, backgroundColor: colors.gold50, borderRadius: radius.card, padding: space.x3 },
  nextHead: { flexDirection: "row", flexWrap: "wrap", alignItems: "center", gap: space.x2 },
  awaiting: { backgroundColor: colors.gold100, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 4 },
  awaitingText: { ...type.caption, color: colors.gold600 },
  replyBar: { flexDirection: "row", alignItems: "flex-end", gap: space.x2 },
  attachBtn: { width: 50, height: 50, borderRadius: radius.control, borderWidth: 1, borderColor: colors.neutral300, backgroundColor: colors.white, alignItems: "center", justifyContent: "center", overflow: "hidden" },
  replyInput: { ...type.body, flex: 1, minHeight: 50, maxHeight: 120, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control, backgroundColor: colors.white, paddingHorizontal: space.x3, paddingVertical: space.x3, color: colors.navy950 },
  sendWrap: { minWidth: 130 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
