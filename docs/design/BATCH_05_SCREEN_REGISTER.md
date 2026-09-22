# Batch 05 Screen Register

All screens inherit the OpesInsure design tokens, 44 px interaction targets, responsive grids, visible focus states and Lucide outline icons. Critical risk and compliance states use text, icon and color together. Maps and evidence never obstruct primary actions.

| Module | Screens | Lucide icons |
|---|---|---|
| Logistics | Fulfilment queue; order detail; assignment; pickup; live status; delivery OTP; failed attempt; return; courier directory | `PackageOpen`, `Bike`, `MapPinned`, `ScanLine`, `Navigation`, `KeyRound`, `TriangleAlert`, `Undo2`, `UsersRound` |
| Notifications | Template directory/editor; preference centre; delivery queue; delivery detail; failed/retry; receipts | `MessagesSquare`, `FilePenLine`, `SlidersHorizontal`, `ListRestart`, `MessageCircleWarning`, `RefreshCw`, `CheckCheck` |
| Support & complaints | Ticket intake; queue; detail; SLA view; escalation; regulatory complaint; resolution; archive | `LifeBuoy`, `Inbox`, `MessageSquareText`, `Timer`, `ArrowUpRight`, `Landmark`, `CircleCheckBig`, `Archive` |
| Fraud & risk | Rule versions; alert queue; alert detail; signal evidence; review; decision; monitoring | `ShieldAlert`, `Siren`, `ScanSearch`, `Waypoints`, `ClipboardSearch`, `Gavel`, `Radar` |
| Audit & compliance | Compliance cases; privileged access; access review; audit explorer; retention; data request; export | `Scale`, `KeySquare`, `UserRoundCheck`, `ScrollText`, `ArchiveRestore`, `ContactRound`, `FileDown` |

Fraud scores are never presented as automatic guilt. The interface labels them as signals requiring review and exposes the matched rules and evidence. Compliance decisions always require reason text and show the deciding actor.

