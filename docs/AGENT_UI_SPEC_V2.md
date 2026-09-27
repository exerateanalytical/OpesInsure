# OpesInsure Commercial Agent Mobile — Master Visual Specification v2.0

Owner-locked specification (2026-09-28). Visual master: the approved Agent Profile screen. Every commercial-agent screen inherits its proportions, maturity, spacing, iconography, typography, navigation and restrained heritage treatment. Tokens live in `src/theme/agent.ts`.

Style: mature, financial-services-grade, insurance-grade, clean, premium, native-mobile, African-heritage branded, non-generic. Portrait.

## 1. Colours
- Brand: navy #073656, deep navy #063454, mid blue #0F5A8D, action blue #1664D4, light blue #75A8E6, gold #D89209, soft gold #FFF5DD.
- Background: page #F8F9FC, surface #FFFFFF, surface soft #F5F7FA, blue tint #EEF5FD.
- Text: primary #111113, heading #073656, secondary #64748B, muted #98A2B3.
- Border: default #E9EAEB, strong #D7DFEA.
- Semantic: success #159455 / bg #EAF8EF; warning #C77B00 / bg #FFF6DE; danger #D92D20 / bg #FFF3F2; info #1664D4 / bg #EEF5FD.
- Usage: gold = active navigation, selected states, premium financial emphasis, restrained heritage accents only. Green = verified, active, completed, paid, successful only. Red = failure, destructive, security warning, account deletion only. Blue = primary actions and interactive controls. Teal prohibited. Multi-colour icon bubbles prohibited.

## 2. Typography (Inter)
Screen title 28/34 700 · hero amount 30/36 700 · section title 18/24 700 · card title 15/21 600 · body 14/20 400 · secondary 13/18 400 · caption 12/16 500 · button 15 600. No glowing text, excessive letter spacing, faux-metal text, oversized gradient headings or decorative font effects.

## 3. Layout grid
Horizontal padding 20 · min safe area 16 · section gap 24 · subsection gap 16 · row gap 8 · card padding 16 · card radius 18 · input radius 14 · button radius 14 · primary button height 52 · row min height 62 · touch target ≥ 48 · bottom navigation 72 · base grid 8.

## 4. Surfaces and cards
- Standard card: white, 1px #E9EAEB border, radius 18, no or extremely subtle shadow.
- Navigation row: outline icon · title · optional subtitle · optional status · chevron; white; Lucide outline icon in #073656; whole row tappable.
- Financial summary: deep navy or white depending on screen; gold as a small accent only; large value; decorative pattern 3–5% opacity.
- Status chip: radius 99, height 26, only when meaningful.
- Danger card: bg #FFF3F2, border #F97066, text #D92D20.
- No card exists merely to decorate content.

## 5. Icons
Lucide outline, stroke 1.75–2, default #073656. Sizes: navigation 22, row 22, action 20, small 18. Prohibited: 3D, cartoon, glowing, random multicolour, inconsistent thick strokes, emoji.

## 6. Heritage
African geometric line pattern, subtle Africa silhouette, gold line ornament, navy heritage texture. Placement: profile hero, financial hero, screen header accent, empty-state illustration. Opacity 3–8%. Never behind body text, never inside every card, no heavy full-screen pattern. One primary heritage moment per screen.

## 7. Shell
- Operational screens: header "OPESINSURE" wordmark + "Commercial Agent Portal", notifications bell and avatar on the right; bottom navigation.
- Drill-down screens: back arrow top-left, centred screen title; bottom navigation may stay but never competes with form actions.

## 8. Bottom navigation (locked)
Home · Leads · Customers · Policies · Earnings. Active #D89209, inactive #073656, background white, top border #E9EAEB, labels visible.

## 9. Screens
1. **Agent Profile / Account Home** (visual master) — header; profile hero (avatar, full name, "Commercial Agent", verification status, account status, agent ID, phone, email, broker/parent entity, region, Edit Profile); sections ACCOUNT (Personal information, Agent information, KYC & verification, Payout details), PREFERENCES (Notification settings, Language & region), SECURITY (Security & sessions, Login activity), SUPPORT & LEGAL (Help & support, Privacy & legal); "Sign out securely"; bottom nav. A gateway, not one huge settings screen.
2. **Notification Settings** — "Control how OpesInsure contacts you." Channels (Push, SMS, Email, WhatsApp); Sales & policies (New lead assigned, Quote updates, Policy issued, Renewal reminders, Policy cancellation); Claims (Claim submitted, Claim status changed, Additional info required, Claim decision); Earnings (Commission accrued, Commission available, Commission paid, Withdrawal updates); Security (New login, Password changed, New device, Suspicious activity — lock/REQUIRED indicator, not switches); Save Preferences. Switches in OpesInsure blue. No duplicate switches elsewhere.
3. **Security & Sessions** — Account security (Password + last changed, Transaction PIN, Two-factor authentication, Biometric login switch); Active sessions (this device with CURRENT SESSION, others with last active, row opens session detail); "Sign out all other sessions"; Recent security activity rows. Recognized = subtle green; Suspicious = red; New device = amber.
4. **Login Activity** — device filter + Filter; grouped by day (Today, Yesterday, dated); repeated sign-ins collapsed ("5 sign-ins · Latest 21:15"); rows open event detail. Event fields: device name, platform, application, city, country, date, time, status, recognized device, approximate IP if available.
5. **Earnings Overview** — "Earnings / Your commissions and payouts."; period selector; TOTAL EARNED hero; Available, Pending, Paid this month, Withdrawn; search + Filter; COMMISSIONS rows (amount strongest, status second, customer / product · insurer below, policy number · date as metadata; opens detail); WITHDRAWAL HISTORY rows (amount, status, method, masked number, date; opens detail); bottom nav.
6. **Earnings Filters** — bottom sheet: Date range, Insurance company, Insurance product, Policy type, Customer, Selling staff/agent, Commission status checkboxes (Accrued, Pending, Available, Paid, Reversed, Disputed), Payment status, Policy status, Branch, Reset, "Show N Commissions". No bottom nav in the sheet.
7. **Commission Detail** — amount + status hero; Policy information (policy number, customer, insurer, product, policy status); Commission calculation (premium, rate, gross, adjustments, net); Timeline (policy issued, accrued, available); View Policy + Request Withdrawal. Optional: commission reference, agent reference, branch, settlement batch, tax/withholding.
8. **Withdrawal Detail** — amount + status; withdrawal ID, method, masked account, requested date-time, estimated settlement; status timeline (Requested → Under review → Processing → Paid, or Failed/Rejected/Reversed/Cancelled); Contact Support.
9. **Language & Region** — segmented English/Français; Country, Currency (XAF · Central African CFA Franc), Date format, Time format, Time zone (Africa/Douala · WAT); Save Changes. Full EN/FR localization; no duplicated headings.
10. **Privacy & Legal** — Legal documents (Privacy Policy, Terms of Use, Agent Agreement, Commission Terms, Claims & Complaints Policy); Data & privacy (Download my data, Request correction, Consent history); Danger zone (Delete my account → confirmation workflow) with the retention note.

Secondary screens behind these (same system): Edit Profile, Personal Information, Agent Information, KYC & Verification, Payout Details, Change Password, Transaction PIN, Two-Factor Authentication, Session Detail, Login Event Detail, Request Withdrawal, Withdrawal Confirmation, Help & Support, the five legal documents, Download My Data, Request Data Correction, Consent History, Delete Account Confirmation.

## 10. Status vocabulary (no other wording)
Agent: Active, Suspended, Inactive, Pending Verification. Commission: Accrued, Pending, Available, Paid, Reversed, Disputed. Session: Current, Recognized, New Device, Suspicious, Expired, Signed Out. Verification: Verified, Pending, Action Required, Rejected. Withdrawal: Requested, Under Review, Processing, Paid, Failed, Rejected, Reversed, Cancelled.

## 11. States
Loading = skeletons; empty = professional icon + title + explanation + relevant CTA ("No commissions yet — Your commissions will appear here when eligible policies are issued."; "No login history — New account activity will appear here."; "No active sessions — There are no other active sessions on your account."); error = short explanation + retry; offline = persistent non-blocking indicator; partial data = show what exists and mark unavailable fields.

## 12. Interaction
Full-row tappable navigation rows; native switches; filters in a bottom sheet; native date sheet for ranges; dropdowns as bottom-sheet selectors; destructive actions via native confirmation sheet; financial rows and session rows open detail; back button top-left; primary actions sticky on long forms.

## 13. Must avoid
Glowing circles, excessive gradients, random icon colours, teal controls, oversized chips, heavy shadows, rainbow cards, generic fintech illustration, colour collision, crowded typography, cheap iconography.
