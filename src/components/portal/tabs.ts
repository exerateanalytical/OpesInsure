import {
  CircleDollarSign,
  CircleUserRound,
  ClipboardCheck,
  ContactRound,
  FileCheck2,
  House,
  ReceiptText,
  ShieldAlert,
} from "lucide-react-native";
import type { PortalTab } from "./PortalShell";

export const agentTabs: PortalTab[] = [
  { label: "Home", icon: House, href: "/agent" },
  { label: "Clients", icon: ContactRound, href: "/agent/clients" },
  { label: "Wallet", icon: CircleDollarSign, href: "/agent/wallet" },
  { label: "Account", icon: CircleUserRound, href: "/agent/account" },
];

export const brokerTabs: PortalTab[] = [
  { label: "Home", icon: House, href: "/broker" },
  { label: "Clients", icon: ContactRound, href: "/broker/clients" },
  { label: "Receivables", icon: ReceiptText, href: "/broker/receivables" },
  { label: "Account", icon: CircleUserRound, href: "/broker/account" },
];

export const carrierTabs: PortalTab[] = [
  { label: "Home", icon: House, href: "/carrier" },
  { label: "Referrals", icon: ClipboardCheck, href: "/carrier/referrals" },
  { label: "Issuance", icon: FileCheck2, href: "/carrier/issuance" },
  { label: "Claims", icon: ShieldAlert, href: "/carrier/claims" },
  { label: "Account", icon: CircleUserRound, href: "/carrier/account" },
];
