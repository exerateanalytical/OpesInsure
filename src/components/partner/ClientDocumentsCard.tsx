import React from "react";
import { Text } from "react-native";
import { FileText } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { Card } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { ClientDocument, shortDate } from "@/api/partner";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { useTranslation } from "@/i18n";
import { AgentCard, AgentSection } from "@/components/agent";
import { AgentListRow } from "./AgentListUi";
import { BookNote } from "./AgentBookUi";

/** Issued documents of a book client; only current documents carry a (signed, short-lived) download link. */
export function ClientDocumentsCard({ customerId, load, policyId, variant = "default" }: { customerId: string; load: (id: string) => Promise<ClientDocument[]>; /** Only this policy's documents (policy detail). */ policyId?: string; /** "agent" = Commercial Agent spec v2 section; broker keeps "default". */ variant?: "default" | "agent" }) {
  const { t, language } = useTranslation();
  const q = useLoad(() => load(customerId), [customerId]);
  const docs = (q.data ?? []).filter((d) => !policyId || d.policy_id === policyId);
  if (q.loading || q.error) return null;
  if (variant === "agent") {
    return (
      <AgentSection title={t("ptClientDocuments")}>
        <AgentCard padded={false}>
          {docs.length === 0 ? <BookNote>{t("ptNoClientDocuments")}</BookNote> : null}
          {docs.map((d, i) => {
            const title = (language === "fr" && d.title_fr) || d.title;
            return (
              <AgentListRow
                key={d.id}
                first={i === 0}
                icon={FileText}
                title={title}
                subtitle={[d.document_number, d.policy_number, shortDate(d.issued_at)].filter(Boolean).join(" · ")}
                status={d.is_current ? d.status : "EXPIRED"}
                statusLabel={d.is_current ? d.status.replaceAll("_", " ") : t("ptDocNotCurrent")}
                onPress={d.download_url ? () => openDocumentUrl(d.download_url!, title, d.document_number ?? undefined) : undefined}
              />
            );
          })}
        </AgentCard>
      </AgentSection>
    );
  }
  return (
    <Card>
      <Text style={{ fontWeight: "600" }}>{t("ptClientDocuments")}</Text>
      {docs.length === 0 ? <Text>{t("ptNoClientDocuments")}</Text> : null}
      {docs.map((d) => {
        const title = (language === "fr" && d.title_fr) || d.title;
        return (
          <FlowRow
            key={d.id}
            icon={FileText}
            title={title}
            subtitle={[d.document_number, d.policy_number, shortDate(d.issued_at)].filter(Boolean).join(" · ")}
            status={d.is_current ? d.status : t("ptDocNotCurrent")}
            onPress={d.download_url ? () => openDocumentUrl(d.download_url!, title, d.document_number ?? undefined) : undefined}
          />
        );
      })}
    </Card>
  );
}
