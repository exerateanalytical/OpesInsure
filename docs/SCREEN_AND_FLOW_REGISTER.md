# OpesInsure Mobile Screen and Flow Register

## Experience architecture

| Experience | Primary navigation | First priority |
|---|---|---|
| Public | Insurers, brokers, verification, sign in | Trusted institutional information |
| Customer | Home, Compare, Policies, Claims, Account | Active cover and next protective action |
| Agent | Dashboard, Clients, Sell, Portfolio, Wallet | Renewals, assisted sale and commission state |
| Broker | Dashboard, Clients, Production, Claims, More | Work requiring action and receivables |
| Carrier | Dashboard, Referrals, Exchange, Settlement, More | Underwriting and issuance queues |
| Platform | Dashboard, Operations, Compliance, Integrations, More | Provider health, finance and security exceptions |

## Implemented routes

| Route | Flow responsibility | Role |
|---|---|---|
| `/` | Splash 1: institutional brand | Public |
| `/welcome` | Splash 2: value proposition and Opesware attribution | Public |
| `/(auth)/sign-in` | Phone authentication request | All |
| `/(auth)/verify` | OTP verification | All |
| `/(auth)/role` | Server-authorised workspace selection shell | Authenticated |
| `/(customer)/(tabs)` | Customer dashboard | Customer |
| `/(customer)/(tabs)/compare` | Comparison entry | Customer |
| `/(customer)/(tabs)/policies` | Policy portfolio | Customer |
| `/(customer)/(tabs)/claims` | Claim list and FNOL entry | Customer |
| `/(customer)/(tabs)/account` | Profile, language, security and sign-out | Customer |
| `/quote/product` | Select insurance class | Customer/agent |
| `/quote/risk` | Capture risk facts | Customer/agent |
| `/quote/offers` | Comparable carrier offers | Customer/agent |
| `/quote/disclosure` | Documents, disclosures and consent | Customer/agent |
| `/checkout` | Immutable price and fee review | Customer/agent |
| `/payment` | Durable payment state and recovery | Customer/agent |
| `/confirmation` | Policy and fulfilment confirmation | Customer/agent |
| `/policy/[id]` | Policy detail, download, renewal and service | Customer |
| `/claim/new` | First notification of loss | Customer/agent |
| `/verify` | Privacy-minimised certificate verification | Public |
| `/institutions/insurers` | ASAC insurer directory | Public/all |
| `/institutions/insurer/[id]` | Company details and verified public offers | Public/all |
| `/institutions/brokers` | Dated MINFI broker publication | Public/all |
| `/institutions/broker/[id]` | Broker record and verification warning | Public/all |
| `/workspace/[role]` | Permission-aware operational dashboard | Agent/broker/carrier/admin |

## Required production completion flows

The present scaffold establishes navigation, styling, state semantics and integration boundaries. These deeper operational screens must be connected to the existing Laravel modules rather than rebuilt independently:

1. Agent client capture, OCR correction, origin-lock confirmation, assisted proposal, payment request, commission detail, withdrawal, reversal and dispute.
2. Broker client/fleet detail, quote workspace, receivables, endorsement, renewal workbench, claims, bordereaux, marketplace publishing, reports and compliance evidence.
3. Carrier product/tariff catalogue, delegated-authority limits, underwriting referral, issuance exchange, endorsements, cancellation, claims exchange and settlement.
4. Platform partner lifecycle, tariff governance, treasury, reconciliation, fraud, DSR, privileged access, regulatory reporting, logistics, support, webhook delivery and release assurance.
5. Authentication recovery, MFA challenge, invitation acceptance, device/session management and step-up authentication for high-risk actions.
6. Every route requires loading, empty, populated, offline/queued, retry, timeout, conflict, denied, success, cancelled, failed, expired and read-only states where applicable.

## Navigation principles

- Customers receive no more than five bottom destinations.
- Workspace navigation comes from effective server permissions, not only the local role name.
- Sensitive actions require an API permission check even when hidden in the interface.
- A denied deep link renders a clear access-denied screen; it never exposes cached data from another tenant.
- Financial and legal actions use explicit verbs and a review step.
