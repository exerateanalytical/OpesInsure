import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("sign-in uses phone + password with OTP and forgot-password alternatives", () => {
  const client = read("src/api/client.ts");
  const signIn = read("app/(auth)/sign-in.tsx");
  assert.match(client, /\/auth\/mobile\/password-login/);
  assert.match(signIn, /AuthApi\.passwordLogin\(/);
  assert.match(signIn, /Use a one-time code instead/);
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

test("no 429 lockout countdown in auth screens", () => {
  for (const f of ["app/(auth)/sign-in.tsx", "app/(auth)/verify.tsx", "app/(auth)/sign-up.tsx"]) {
    const src = read(f);
    assert.doesNotMatch(src, /retryAfter|429|Send another code in/);
  }
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
  assert.match(read("app/(customer)/(tabs)/account.tsx"), /requestEmailVerification/);
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
