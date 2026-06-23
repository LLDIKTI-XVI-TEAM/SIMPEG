import fs from 'node:fs';

const root = new URL('../', import.meta.url);
const envPath = new URL('.env', root);
const outputPath = new URL('postman/simpeg-local-session.postman_environment.json', root);

function loadEnv(path) {
  const text = fs.readFileSync(path, 'utf8');
  const env = {};

  for (const line of text.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }

    const index = trimmed.indexOf('=');
    if (index === -1) {
      continue;
    }

    const key = trimmed.slice(0, index);
    let value = trimmed.slice(index + 1);
    value = value.replace(/^"(.*)"$/, '$1');
    env[key] = value.replace(/\$\{([^}]+)\}/g, (_, name) => env[name] ?? '');
  }

  return env;
}

class CookieJar {
  constructor() {
    this.cookies = new Map();
  }

  store(url, headers) {
    const origin = new URL(url).origin;
    const setCookies = [];

    if (typeof headers.getSetCookie === 'function') {
      setCookies.push(...headers.getSetCookie());
    } else {
      const raw = headers.get('set-cookie');
      if (raw) {
        setCookies.push(...raw.split(/,(?=[^;,]+=)/));
      }
    }

    if (!setCookies.length) {
      return;
    }

    const bucket = this.cookies.get(origin) ?? new Map();

    for (const cookie of setCookies) {
      const [pair] = cookie.split(';');
      const equals = pair.indexOf('=');
      if (equals === -1) {
        continue;
      }
      bucket.set(pair.slice(0, equals).trim(), pair.slice(equals + 1).trim());
    }

    this.cookies.set(origin, bucket);
  }

  header(url) {
    const bucket = this.cookies.get(new URL(url).origin);
    if (!bucket) {
      return '';
    }

    return [...bucket.entries()].map(([key, value]) => `${key}=${value}`).join('; ');
  }

  value(origin, name) {
    return this.cookies.get(origin)?.get(name) ?? '';
  }
}

async function request(jar, url, options = {}) {
  const headers = new Headers(options.headers ?? {});
  const cookie = jar.header(url);

  if (cookie) {
    headers.set('cookie', cookie);
  }

  const response = await fetch(url, {
    ...options,
    headers,
    redirect: 'manual',
  });

  jar.store(url, response.headers);

  return response;
}

function absoluteUrl(base, location) {
  return new URL(location, base).toString();
}

function parseLoginAction(html) {
  const match = html.match(/<form[^>]+action="([^"]+)"/i);
  if (!match) {
    throw new Error('Tidak menemukan form login Keycloak.');
  }

  return match[1].replace(/&amp;/g, '&');
}

function decodedXsrf(value) {
  if (!value) {
    return '';
  }

  return decodeURIComponent(value);
}

async function main() {
  const env = loadEnv(envPath);
  const appUrl = env.APP_URL || 'http://localhost:8000';
  const username = env.KEYCLOAK_TEST_USERNAME;
  const password = env.KEYCLOAK_TEST_PASSWORD;

  if (!username || !password) {
    throw new Error('KEYCLOAK_TEST_USERNAME dan KEYCLOAK_TEST_PASSWORD harus ada di .env.');
  }

  const jar = new CookieJar();

  const loginResponse = await request(jar, `${appUrl}/login`);
  const keycloakAuthUrl = loginResponse.headers.get('location');

  if (loginResponse.status !== 302 || !keycloakAuthUrl) {
    throw new Error(`Ekspektasi /login redirect 302, dapat ${loginResponse.status}.`);
  }

  const formResponse = await request(jar, absoluteUrl(appUrl, keycloakAuthUrl));
  const formHtml = await formResponse.text();
  const action = parseLoginAction(formHtml);

  const formBody = new URLSearchParams();
  formBody.set('username', username);
  formBody.set('password', password);
  formBody.set('credentialId', '');

  const submitResponse = await request(jar, action, {
    method: 'POST',
    headers: {
      'content-type': 'application/x-www-form-urlencoded',
    },
    body: formBody,
  });

  const callbackUrl = submitResponse.headers.get('location');
  if (![302, 303].includes(submitResponse.status) || !callbackUrl) {
    const body = await submitResponse.text();
    throw new Error(`Login Keycloak gagal atau tidak redirect. Status ${submitResponse.status}. Cuplikan: ${body.slice(0, 300)}`);
  }

  const callbackResponse = await request(jar, absoluteUrl(action, callbackUrl));
  const finalLocation = callbackResponse.headers.get('location');
  const finalBody = callbackResponse.status === 200 ? await callbackResponse.text() : '';

  if (![302, 303].includes(callbackResponse.status)) {
    throw new Error(`Callback SIMPEG tidak menghasilkan session login. Status ${callbackResponse.status}. Cuplikan: ${finalBody.slice(0, 300)}`);
  }

  const localOrigin = new URL(appUrl).origin;
  const cookieHeader = jar.header(appUrl);
  const xsrfToken = decodedXsrf(jar.value(localOrigin, 'XSRF-TOKEN'));

  if (!cookieHeader.includes('simpeg-session=')) {
    throw new Error('Cookie simpeg-session tidak ditemukan setelah callback.');
  }

  const environment = {
    name: 'SIMPEG Local Authenticated Session',
    values: [
      { key: 'base_url', value: appUrl, enabled: true },
      { key: 'cookie_header', value: cookieHeader, enabled: true },
      { key: 'xsrf_token', value: xsrfToken, enabled: true },
      { key: 'redirect_after_login', value: finalLocation ?? '', enabled: true },
    ],
  };

  fs.writeFileSync(outputPath, `${JSON.stringify(environment, null, 2)}\n`);

  console.log(`Session berhasil dibuat untuk ${username}.`);
  console.log(`Environment Postman ditulis ke ${outputPath.pathname}`);
}

main().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
