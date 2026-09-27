import React from "react";
import { Text } from "react-native";
import { FileText } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { Card } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { ClientDocument, shortDate } from "@/api/partner";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { useTranslation } from "@/i18n";

/** Issued documents of a book client; only current documents carry a (signed, short-lived) download link. */
export function ClientDocumentsCard({ customerId, load }: { customerId: string; load: (id: string) => Promise<ClientDocument[]> }) {
  const { t, language } = useTranslation();
  const q = useLoad(() => load(customerId), [customerId]);
  const docs = q.data ?? [];
  if (q.loading || q.error) return null;
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
