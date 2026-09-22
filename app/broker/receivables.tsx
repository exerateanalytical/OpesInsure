import React, { useEffect, useState } from "react";
import { ReceiptText } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi } from "@/api/client";
export default function Receivables() {
  const [x, setX] = useState<
    {
      id: string;
      customer_name: string;
      amount_minor: number;
      currency: "XAF";
      status: string;
      due_at: string;
    }[]
  >([]);
  useEffect(() => {
    BrokerApi.receivables().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Receivables"
        subtitle="Backend ledger remains authoritative"
        back
      />
      <OperationsList
        icon={ReceiptText}
        rows={x.map((r) => ({
          id: r.id,
          title: r.customer_name,
          subtitle: `${new Intl.NumberFormat("fr-CM").format(r.amount_minor / 100)} FCFA · due ${r.due_at}`,
          status: r.status,
        }))}
      />
    </Screen>
  );
}
