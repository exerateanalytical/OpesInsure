import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("sign-in uses phone + password with OTP and forgot-password alternatives", () => {
  const client = read("src/api/client.ts");
  const signIn = read("app/(auth)/sign-in.tsx");
  assert.match(client, /\/auth\/mobile\/password-login/);
  assert.match(signIn, /AuthApi\.passwordLogin\(/);
  // Copy lives in the i18n catalogue (EN + FR).
  assert.match(signIn, /t\("useOtpInstead"\)/);
  assert.match(read("src/i18n/en.ts"), /Use a one-time code instead/);
  assert.match(read("src/i18n/fr.ts"), /useOtpInstead: "Utiliser un code/);
  assert.match(signIn, /forgot-password/);
});

test("forgot password requests a code then resets", () => {
  const client = read("src/api/client.ts");
  const forgot = read("app/(auth)/forgot-password.tsx");
  assert.match(client, /\/auth\/mobile\/password\/forgot/);
  assert.match(client, /\/auth\/mobile\/password\/reset/);
  assert.match(forgot, /AuthApi\.forgotPassword\(/);
  assert.match(forgot, /AuthApi\.resetPassword\(/);
});

test("OTP request can choose WhatsApp or SMS", () => {
  const client = read("src/api/client.ts");
  assert.match(client, /requestOtp: \(phone_e164: string, channel\?: OtpChannel\)/);
  assert.match(read("app/(auth)/sign-in.tsx"), /"whatsapp"/);
});

test("sign-up takes a real password and optional recommended email", () => {
  const signUp = read("app/(auth)/sign-up.tsx");
  assert.doesNotMatch(signUp, /throwawayPassword/);
  assert.doesNotMatch(signUp, /expo-crypto/);
  assert.match(signUp, /password\.length < 8/);
  assert.match(signUp, /secureToggle/);
  assert.match(signUp, /Recommended/);
  assert.match(signUp, /verification_channel/);
  assert.match(signUp, /hasTokens\(result\)/);
});

test("auth screens show a dedicated lockout state with the Retry-After countdown", () => {
  // Checklist §3: a locked / too-many-attempts state instead of a generic error.
  assert.match(read("src/api/client.ts"), /Retry-After/);
  const logic = read("src/lib/customerLogic.ts");
  assert.match(logic, /export function isLockout/);
  assert.match(logic, /status === 429/);
  assert.match(logic, /retryAfter/);
  const notice = read("src/components/auth/LockoutNotice.tsx");
  assert.match(notice, /formatCountdown/);
  assert.match(notice, /onDone\(\)/);
  for (const f of [
    "app/(auth)/sign-in.tsx",
    "app/(auth)/verify.tsx",
    "app/(auth)/sign-up.tsx",
    "app/(auth)/forgot-password.tsx",
  ]) {
    const src = read(f);
    assert.match(src, /isLockout\(e\)/, f);
    assert.match(src, /<LockoutNotice seconds=\{locked\}/, f);
  }
});

test("OTP screen counts down expiry from expires_in and rate-limits resend", () => {
  const verify = read("app/(auth)/verify.tsx");
  assert.match(verify, /params\.expiresIn/);
  assert.match(verify, /next\.expires_in/);
  assert.match(verify, /RESEND_COOLDOWN = 60/);
  assert.match(verify, /disabled=\{resendIn > 0/);
  assert.match(verify, /disabled=\{code\.length !== 6 \|\| expired/);
});

test("support contacts come from /public/support-contacts, not hardcoded", () => {
  assert.match(read("src/api/client.ts"), /\/public\/support-contacts/);
  const list = read("src/components/auth/SupportContacts.tsx");
  assert.match(list, /mailto:/);
  assert.match(list, /tel:/);
  assert.match(list, /whatsapp_url/);
  for (const f of [
    "app/terms.tsx",
    "app/(auth)/invitation.tsx",
    "app/access-denied.tsx",
    "app/support/index.tsx",
  ]) {
    const src = read(f);
    assert.doesNotMatch(src, /@opesinsure\.cm/);
    assert.match(src, /SupportContactList/);
  }
});

test("customer account offers email verification", () => {
  assert.match(read("src/api/client.ts"), /\/me\/email\/verification/);
  // Account tab renamed to Profile (Home | Explore | Policies | Claims | Profile).
  assert.match(read("app/(customer)/(tabs)/profile.tsx"), /requestEmailVerification/);
});

test("review follow-ups: demo password from server, challenge on reset, email resend", () => {
  const signIn = read("app/(auth)/sign-in.tsx");
  assert.doesNotMatch(signIn, /Demo@12345/);
  assert.match(signIn, /account\.password/);
  const client = read("src/api/client.ts");
  assert.match(client, /challenge_id,\s*code,\s*password,/);
  assert.doesNotMatch(client, /recent_sales/);
  assert.match(read("app/(auth)/sign-up.tsx"), /result\.verification_channel/);
  assert.match(read("app/(auth)/verify.tsx"), /isEmail \? null/);
  const layout = read("app/_layout.tsx");
  assert.match(layout, /\(auth\)\/forgot-password/);
  assert.match(layout, /\(auth\)\/invitation/);
});
