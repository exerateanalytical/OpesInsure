# Free-text fields audit — "selected, not typed"

Date: 2026-09-25 · Scope: every input schema the mobile app receives for a new insurance, a proposal, KYC/profile, beneficiaries, addresses and claims.
REQ: REQ-MDM-003, REQ-MDM-005, REQ-MOB-002. Test: `tests/Feature/MasterData/SelectionFirstInputsTest.php`.

Owner rule: a user adding an insurance **selects**. Typing happens only through the "Other / Not listed" fallback, which files a master-data suggestion. Numbers and dates stay numeric/date pickers with min/max.

## Schemas audited

| Schema | Where it comes from | Endpoint |
|---|---|---|
| MOTOR risk wizard | `app/Application/Vehicles/MotorRiskSchema.php` (vehicle agent; not edited). Bindings added in `RiskSchemaCatalogue::bindMotorFreeText` | `GET /api/v1/mobile/catalogue/lines/MOTOR/risk-schema` |
| 14 non-motor risk wizards | `app/Application/Catalogue/NonMotorRiskSchemas.php` (now v3) | same, per line |
| 27 specialty flows | `database/data/master_data/specialty_2026.json` `domain.flow` via `MasterDataFlows` | same, per line (fallback) |
| Disclosure questions | `disclosure_schema_versions.questions` (admin-authored; seeded ones are all booleans) | `MobileDisclosureController` |
| Customer profile + beneficiaries | `MobileCustomerAccountController@updateProfile` rules | new `GET /api/v1/forms/customer_profile` |
| KYC identifier / document | `MobileKycController` rules | new `GET /api/v1/forms/kyc_identifier`, `kyc_document` |
| Claim FNOL | `MobileClaimController@store` rules | new `GET /api/v1/forms/claim_fnol` |
| Agent lead | `PartnerAgentWorkspaceController@createLead` | new `GET /api/v1/forms/agent_lead` |

## Before → after, field by field

### Changed: free text now bound to a list

| Schema.field | Before | After |
|---|---|---|
| MOTOR.cargo_type | `text` | `select_master` cargo.cargo_category, allow_other |
| MOTOR.previous_insurer | `text` | `select_master` source {institutions, insurer}, `endpoint` `/api/v1/public/institutions?type=insurer` (official register), allow_other |
| HOME.city | `text` (required rating fact) | `select_master` geography.city, parent `department` (region → department → city), allow_other |
| HOME.address | `text` "Address / landmark" | kept as `text` **Street / landmark** only (LANDMARK), max 255 |
| HOME.unusual_construction | `text`, shown when construction_material = OTHER | removed: the same text is now `construction_material_other` from the fallback (filed to review queue) |
| BUSINESS.city | `text` (required rating fact) | `select_master` geography.city; new `department` picker between region and city |
| PROFESSIONAL_LIABILITY.claims_details | `text` "Describe the claims" | `multi_select_master` `claims_causes` claims.cause_of_loss, parent_code LIABILITY, allow_other |
| CONSTRUCTION.site_address | `text` required | region → new `department` → new `city` pickers; `site_address` optional landmark |
| MINING.site_location (flow) | `TEXT` required | region → department → city pickers added in the flow; text now optional landmark |
| HOUSEHOLD_CONTENTS.address (flow) | `TEXT` required | same geography pickers; text optional landmark |
| EVENT.venue_address (flow) | `TEXT` required | same geography pickers; text optional landmark |
| Profile.region / city | free `string` | pickers geography.cameroon_region → cameroon_department (helper) → city |
| Profile.occupation | free `string` | occupations.occupation, allow_other |
| Profile.beneficiaries.*.relationship | free `string` | persons.relationship, allow_other |
| KYC.identifier_type | free `string` | documents.identity_document, allow_other |
| KYC.identifier_country | 2-letter `string` | geography.country (closed, default CM) |
| KYC document.purpose | free `string` | documents.identity_document, allow_other |
| FNOL.incident_type | free `string` | claims.cause_of_loss, parent `claim_category` (helper picker claims.claim_category) |
| FNOL.incident_location | free `string` | region → department → city helper pickers composed into `incident_location`, plus landmark text |
| FNOL.policy_id | uuid | picker from `endpoint` `/api/v1/policies` (value_key id) |
| Lead.city | free `string` | geography.city with region/department helpers |
| Lead.product_interest | free `string` | picker from `/api/v1/catalogue/lines` (value_key code) |

### Numbers and dates

| Field(s) | Before | After |
|---|---|---|
| Every `number` / `money` without `min` (≈150 fields incl. all specialty flows) | no bounds | `min: 0` added by the contract. No upper cap invented where none is established; existing caps kept |
| Dates of birth (HEALTH/LIFE/GROUP_ACCIDENT members, profile) | no bounds | `min: 1900-01-01`, `max: today` |
| TRAVEL departure/return, CONSTRUCTION start | no bounds | `min: today` |
| FNOL incident_at | server `before_or_equal:now` | `max: now`, `with_time: true` |

### Already selected (no change needed)
All other `select_master` / `multi_select_master` fields in the 14 non-motor wizards and the specialty flows (284 master-backed pickers), and the MOTOR make/model/year/body/powertrain/usage/class selectors.

25 specialty pickers are **closed** (`allow_other: false`) by design: cover options, premium frequencies, waiting periods, territories and similar product terms that the carrier defines (e.g. CREDIT_LIFE.covers, FUNERAL.waiting_period, LEGAL_PROTECTION.territory). MOTOR `zone`, `cover_type`, `battery_ownership` and the vehicle enumerations are inline closed rating codes. HEALTH.medical_declaration is closed (`other_allowed: false`, medical question set).

### Still free text, with reason (cannot be a list)

| Reason | Fields |
|---|---|
| PERSON_NAME | HEALTH/GROUP_ACCIDENT members.*.full_name; LIFE life_assured_name, payer_name, beneficiaries.*.full_name; LIFE_INSURANCE policyholder, life_assured, beneficiaries.*.name; EDUCATION_SAVINGS child; RETIREMENT/FUNERAL beneficiaries.*.name, family_members.*.name; CREDIT_LIFE borrower; FUNERAL insured; GROUP_LIFE members.*.name; profile beneficiaries.*.name; lead full_name |
| ORGANISATION_NAME | HEALTH company_name; GROUP_ACCIDENT organisation_name; CONSTRUCTION contractor_name (these carry `institution_ref`); CREDIT_LIFE lender; GROUP_LIFE company; SURETY beneficiary; MARINE_HULL builder; EMPLOYER_LIABILITY employer |
| IDENTIFIER | MOTOR registration_number, vin; EQUIPMENT/ELECTRONIC/MOBILE_DEVICE/VALUABLE serial_number; MOBILE_DEVICE imei; AVIATION registration; MARINE_HULL registration_number, vessel_name; FLEET vehicles.*.registration; GROUP_LIFE master_policy_number; SURETY contract_reference; MINING permit_reference; KYC identifier_value; FNOL police_reference; lead phone_e164 |
| NARRATIVE | FNOL description; CARGO commodity_description; LIFE_INSURANCE medical_details; SURETY other_bond_description; CREDIT_INSURANCE bad_debt_details; VALUABLE_ITEMS model_description; PUBLIC_LIABILITY activity_description; EVENT event_name; HOUSEHOLD_CONTENTS scheduled_items.*.description |
| LANDMARK | HOME address; CONSTRUCTION site_address; MINING site_location; HOUSEHOLD_CONTENTS address; EVENT venue_address; profile address_line1; FNOL incident_location. Street/landmark comes after the geography pickers |

## Gaps left open (no list invented)

- **Quarter / neighbourhood**: `geography.quarter` exists but is empty (structure only, "populated from approved submissions and verified imports"). Landmark text stays typed until a verified source is imported.
- **Arrondissement / commune**: `cameroon_arrondissement`, `cameroon_commune` are empty pending a verified government import (MDM-013). The cascade stops at department → city.
- **City coverage**: `geography.city` has 15 cities + OTHER. Smaller towns go through "Other" → review queue. HOME/BUSINESS `city` is now validated: a free string is rejected, and a pick outside the list must be `OTHER` + `city_other`.
- **GROUP_LIFE members.*.beneficiaries** is a single text field in the specialty flow; it should become a nested beneficiaries repeater (name + persons.relationship + share). Left for the master-data flow owner.
- **Insurer list for previous_insurer**: served by the official register endpoint, not a master-data list, so the server does not validate it (MOTOR facts skip `RiskFactsProcessor`).
- **Disclosure questions**: the seeded ones are all booleans. If an admin authors a `text` question, it is served with `free_text.reason = UNCLASSIFIED` unless the question sets `free_text_reason`. Admin-side enforcement (Filament) is not in this change.
- **Server validation of profile/KYC/FNOL/lead codes**: these endpoints still accept any string (a code, or the typed "Other" text). The schemas make the app send codes. Strict server checks would break older app builds, so they are left for a later step.

## Contract the mobile app must render (InputFieldContract v1)

Every field in `risk-schema`, `forms/{form}` and disclosure questions carries:

```jsonc
{
  "key": "city", "label_en": "City", "label_fr": "Ville", "type": "select_master", "required": true, "step": "location",
  "input": "picker",                       // picker | multi_picker | number | money | date | boolean | text | file | repeater
  "source": { "domain": "geography", "list": "city", "parent": "department", "parent_code": null },
  "parent_field": "department",            // legacy mirror of source.parent
  "allow_other": true, "other_allowed": true,
  "visible_if": { ... }, "min": 0, "max": 100, "item_fields": [ ... ]
}
```

Rendering rules:
1. `source` object → load `GET /api/v1/master-data/{domain}?list={list}` (or `/search?list=&q=&parent=`). Filter by the value of field `source.parent`, or by `source.parent_code` when that is set. Clear child values when the parent changes.
2. `allow_other: true` → add "Other / Not listed". On pick, send `key: "OTHER"` and `key_other: "<text>"`. For risk facts the server files the suggestion. For profile/KYC/FNOL/lead the app also `POST /api/v1/master-data/suggestions {domain, list, text, parent_code}`.
3. `options` (no `source`) → closed inline picker, never free text.
4. `endpoint` (+ `value_key`) → picker fed by that API (policies, insurance lines, insurer register).
5. Vehicle `VEHICLE_MAKE` / `VEHICLE_MODEL`: `source` stays the endpoint string (unchanged). The object form is in `source_ref`.
6. `free_text.reason` present → plain text input, honouring `max_length`, `pattern`, `multiline`. No other field may render as a text box.
7. `number`/`money` → numeric keypad with `min` (always present) and `max` when present. `date` → date picker. `min`/`max` are ISO dates or `today`/`now`.
8. Forms only: `submit: false` marks helper pickers that are not sent. `compose_into` means the helper labels are joined into that text target (e.g. `incident_location` = "Landmark, City, Department, Region"). `submit_to` names the endpoint.
