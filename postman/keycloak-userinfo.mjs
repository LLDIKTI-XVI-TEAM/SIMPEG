import fs from 'node:fs';

const root = new URL('../', import.meta.url);
const envText = fs.readFileSync(new URL('.env', root), 'utf8');
const env = {};

for (const line of envText.split(/\r?\n/)) {
  const trimmed = line.trim();
  if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) {
    continue;
  }

  const index = trimmed.indexOf('=');
  env[trimmed.slice(0, index)] = trimmed.slice(index + 1).replace(/^"(.*)"$/, '$1');
}

const tokenUrl = `${env.KEYCLOAK_BASE_URL}/realms/${env.KEYCLOAK_REALM}/protocol/openid-connect/token`;
const body = new URLSearchParams({
  grant_type: 'password',
  client_id: env.KEYCLOAK_CLIENT_ID,
  client_secret: env.KEYCLOAK_CLIENT_SECRET,
  username: env.KEYCLOAK_TEST_USERNAME,
  password: env.KEYCLOAK_TEST_PASSWORD,
  scope: 'openid',
});

const tokenResponse = await fetch(tokenUrl, {
  method: 'POST',
  headers: {
    'content-type': 'application/x-www-form-urlencoded',
  },
  body,
});

const tokenPayload = await tokenResponse.json();

if (!tokenResponse.ok) {
  console.log(JSON.stringify({
    status: tokenResponse.status,
    error: tokenPayload.error,
    error_description: tokenPayload.error_description,
  }, null, 2));
  process.exit(1);
}

const userinfoResponse = await fetch(`${env.KEYCLOAK_BASE_URL}/realms/${env.KEYCLOAK_REALM}/protocol/openid-connect/userinfo`, {
  headers: {
    authorization: `Bearer ${tokenPayload.access_token}`,
  },
});

const userinfo = await userinfoResponse.json();

console.log(JSON.stringify({
  status: userinfoResponse.status,
  sub: userinfo.sub,
  preferred_username: userinfo.preferred_username,
  email: userinfo.email,
  email_verified: userinfo.email_verified,
  name: userinfo.name,
}, null, 2));
