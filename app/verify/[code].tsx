import React, { useCallback, useEffect, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import {
  Building2,
  CalendarCheck,
  CalendarX,
  CarFront,
  Clock,
  Eye,
  FileText,
  Fingerprint,
  Hash,
  RefreshCw,
  ScanLine,
  ShieldAlert,
  ShieldCheck,
  ShieldX,
  User,
} from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, DetailRow, type Tint } from "@/components/design";
import { InstitutionMark } from "@/components/InstitutionMark";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { ApiError, PublicApi, type DocumentVerification } from "@/api/client";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/**
 * In-app verification result (POST /public/verify, canonical WF-082 status).
 * Shows only what the public API discloses: status, document type/number,
 * masked holder, issuer, dates and integrity. "Open document" appears when the
 * caller passed the document's own signed link (source) — the public API
 * never returns one.
 */
const TONE: Record<string, { tint: Tint; chip: "success" | "warning" | "danger" | "info" }> = {
  VALID: { tint: "green", chip: "success" },
  EXPIRED: { tint: "gold", chip: "warning" },
  NOT_YET_ACTIVE: { tint: "blue", chip: "info" },
  REPLACED: { tint: "gold", chip: "warning" },
  REVOKED: { tint: "red", chip: "danger" },
  NOT_FOUND: { tint: "red", chip: "danger" },
};

function initialsOf(name?: string | null) {
  return (name ?? "")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 3)
    .map((w) => w[0]!.toUpperCase())
    .join("");
}

export default function VerifyResult() {
  const { t, td, language } = useTranslation();
  const f = useFormatters();
  const params = useLocalSearchParams<{ code: string; t?: string; source?: string; title?: string }>();
  const reference = String(params.code ?? "").trim();
  const [data, setData] = useState<DocumentVerification | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setData(await PublicApi.verifyDocument(reference, params.t || null));
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  }, [reference, params.t]);
  useEffect(() => {
    if (reference) void load();
  }, [load, reference]);

  const status = String(data?.status ?? "NOT_FOUND").toUpperCase();
  const tone = TONE[status] ?? { tint: "red" as Tint, chip: "danger" as const };
  const doc = data?.document ?? null;
  const title = (language === "fr" ? doc?.title_fr : doc?.title_en) ?? doc?.title_en ?? doc?.title_fr ?? data?.product_name ?? null;
  // Insurer (mark + name) is the carrier; "Issued by" can be the platform for system-generated documents.
  const insurer = data?.carrier_name ?? doc?.issuer_name ?? null;
  const issuer = doc?.issuer_name ?? data?.carrier_name ?? null;
  const from = doc?.valid_from ?? data?.coverage_starts_at ?? null;
  const until = doc?.valid_until ?? data?.coverage_ends_at ?? null;
  const number = doc?.document_number ?? doc?.masked_document_number ?? null;
  const Icon = status === "VALID" ? ShieldCheck : status === "NOT_FOUND" || status === "REVOKED" ? ShieldX : ShieldAlert;
  const source = typeof params.source === "string" && /^https:\/\//i.test(params.source) ? params.source : null;

  return (
    <Screen>
      <AppHeader title={t("vfResultTitle")} subtitle={reference} back />
      {loading ? (
        <LoadingState label={t("vfChecking")} />
      ) : error instanceof ApiError && error.status === 429 ? (
        <>
          <Banner icon={Clock} tint="gold" title={t("vfRateLimitedTitle")} body={t("vfRateLimited")} />
          <Button label={t("vfTryAgain")} icon={RefreshCw} variant="secondary" onPress={() => void load()} />
        </>
      ) : error ? (
        <ErrorState error={error} onRetry={() => void load()} />
      ) : data ? (
        <>
          <Banner icon={Icon} tint={tone.tint} title={td(`vfStatus_${status}`, status)} body={td(`vfStatusBody_${status}`, "")} />
          {status !== "NOT_FOUND" ? (
            <Card>
              <View style={styles.issuer}>
                <InstitutionMark logoUrl={doc?.issuer_logo_url} initials={initialsOf(insurer)} size={48} />
                <View style={styles.flex}>
                  <Text style={styles.title}>{title ?? t("vfDocument")}</Text>
                  {insurer ? <Text style={styles.body}>{insurer}</Text> : null}
                </View>
                <StatusChip label={td(`vfResult_${status}`, status)} tone={tone.chip} />
              </View>
              {number ? <DetailRow icon={Hash} label={t("vfNumber")} value={number} compact /> : null}
              {doc?.policy_reference ? <DetailRow icon={FileText} label={t("vfPolicy")} value={doc.policy_reference} compact /> : null}
              {doc?.holder ? <DetailRow icon={User} label={t("vfHolder")} value={doc.holder} compact /> : null}
              {doc?.vehicle ? <DetailRow icon={CarFront} label={t("vfVehicle")} value={doc.vehicle} compact /> : null}
              {insurer && insurer !== issuer ? <DetailRow icon={ShieldCheck} label={t("vfInsurerLabel")} value={insurer} compact /> : null}
              {issuer ? <DetailRow icon={Building2} label={t("vfIssuer")} value={issuer} compact /> : null}
              {data.product_name || data.product_class ? (
                <DetailRow icon={FileText} label={t("vfProduct")} value={[data.product_name, data.product_class].filter(Boolean).join(" · ")} compact />
              ) : null}
              {doc?.issued_at ? <DetailRow icon={Clock} label={t("vfIssued")} value={f.date(doc.issued_at)} compact /> : null}
              {from ? <DetailRow icon={CalendarCheck} label={t("vfValidFrom")} value={f.date(from)} compact /> : null}
              {until ? <DetailRow icon={CalendarX} label={t("vfValidUntil")} value={f.date(until)} compact /> : null}
              {doc?.replaced_by ? <DetailRow icon={RefreshCw} label={t("vfReplacedBy")} value={doc.replaced_by} compact /> : null}
              {doc?.revoked_at ? <DetailRow icon={ShieldX} label={t("vfRevokedAt")} value={f.date(doc.revoked_at)} compact /> : null}
            </Card>
          ) : null}
          {doc?.sha256 || doc?.signature_status ? (
            <Card>
              <Text style={styles.title}>{t("vfIntegrity")}</Text>
              {doc.signature_status ? (
                <DetailRow icon={Fingerprint} label={t("vfSignature")} value={td(`vfSig_${doc.signature_status}`, doc.signature_status)} compact />
              ) : null}
              {doc.sha256 ? (
                <>
                  <Text style={styles.label}>SHA-256</Text>
                  <Text style={styles.hash} selectable>
                    {doc.sha256}
                  </Text>
                </>
              ) : null}
            </Card>
          ) : null}
          {source ? (
            <Button label={t("vfOpenDocument")} icon={Eye} onPress={() => openDocumentUrl(source, params.title || title || reference, number ?? undefined)} />
          ) : null}
          <Button label={t("vfScanAnother")} icon={ScanLine} variant="secondary" onPress={() => router.replace("/verify/scan")} />
          <Text style={styles.note}>{t("vfChecked", { date: f.dateTime(data.checked_at ?? new Date().toISOString()) })}</Text>
          <Text style={styles.note}>{t("vfNotice")}</Text>
        </>
      ) : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  issuer: { flexDirection: "row", alignItems: "center", gap: 12 },
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  label: { ...type.meta, color: colors.neutral600 },
  hash: { ...type.meta, color: colors.navy950, fontFamily: "monospace" },
  note: { ...type.meta, color: colors.neutral600 },
});
