import React from "react";
import { LucideIcon } from "lucide-react-native";
import { Card } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
export function OperationsList({
  rows,
  icon,
  onPress,
}: {
  rows: { id: string; title: string; subtitle: string; status: string }[];
  icon: LucideIcon;
  onPress?: (id: string) => void;
}) {
  return (
    <Card>
      {rows.map((x) => (
        <FlowRow
          key={x.id}
          icon={icon}
          title={x.title}
          subtitle={x.subtitle}
          status={x.status}
          onPress={onPress ? () => onPress(x.id) : undefined}
        />
      ))}
    </Card>
  );
}
