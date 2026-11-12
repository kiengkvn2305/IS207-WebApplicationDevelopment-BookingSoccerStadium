import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const base = 'http://127.0.0.1:8089/';
const php = 'C:/xampp/php/php.exe';
const fixtures = 'tests/http-fixtures.php';
const fixtureIds = JSON.parse(execFileSync(php, [fixtures, 'seed'], { encoding: 'utf8' }));
const emails = [];
let checks = 0;

function verify(condition, message) {
  assert.ok(condition, message);
  checks++;
}

function client() {
  let cookie = '';
  let csrf = '';

  async function request(path, { method = 'GET', body, token = csrf } = {}) {
    const isForm = body instanceof FormData;
    const response = await fetch(base + path, {
      method,
      redirect: 'manual',
      headers: {
        Cookie: cookie,
        'X-CSRF-Token': token,
        ...(isForm ? {} : { 'Content-Type': 'application/json' }),
      },
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    });
    for (const value of response.headers.getSetCookie()) {
      if (value.startsWith('PHPSESSID=')) cookie = value.split(';')[0];
    }
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      data = text;
    }
    if (path === 'api/me' && data.csrf) csrf = data.csrf;
    return { status: response.status, data };
  }

  async function login(email, password) {
    csrf = (await request('api/me')).data.csrf;
    const result = await request('api/login', { method: 'POST', body: { email, password } });
    if (result.status === 200) csrf = (await request('api/me')).data.csrf;
    return result;
  }

  return { request, login };
}

const customer = client();
const owner = client();
const admin = client();
let uploadedImage;

try {
  const routes = [
    ...readFileSync('app/routes.php', 'utf8').matchAll(
      /\$r->add\(\s*'(GET|POST)',\s*'([^']+)',\s*'(\w+)',\s*'(\w+)'\s*\)/g
    ),
  ];
  const publicBooking = new Set(['slots', 'reviews', 'topRated', 'matches', 'sepayWebhook']);
  const protectedRoutes = routes
    .map(([, method, path, controller, action]) => {
      let roles;
      if (controller === 'AdminController') roles = ['admin'];
      if (controller === 'OwnerController') roles = ['owner'];
      if (controller === 'NotificationController') roles = ['customer', 'owner'];
      if (controller === 'AuthController' && action === 'updateProfile')
        roles = ['customer', 'owner', 'admin'];
      if (controller === 'BookingController' && !publicBooking.has(action)) roles = ['customer'];
      return { method, path: path.replace(/^\//, ''), roles };
    })
    .filter((route) => route.roles);
  verify(
    protectedRoutes.length >= 35,
    'protected route discovery must not silently skip endpoints'
  );

  const anonymous = client();
  for (const route of protectedRoutes) {
    const result = await anonymous.request(route.path, {
      method: route.method,
      ...(route.method === 'POST' ? { body: {} } : {}),
    });
    verify(result.status === 401, `anonymous access denied: ${route.path}`);
  }
  console.log(`PASS: anonymous guards across ${protectedRoutes.length} endpoints`);

  for (const [role, actor, email, password] of [
    ['customer', customer, 'khach@sannha.vn', 'Demo@12345'],
    ['owner', owner, 'chusan@sannha.vn', 'Demo@12345'],
    ['admin', admin, 'admin@sannha.vn', 'Admin@12345'],
  ]) {
    verify((await actor.login(email, password)).status === 200, `${role} login`);
    for (const route of protectedRoutes) {
      if (!route.roles.includes(role)) {
        const result = await actor.request(route.path, {
          method: route.method,
          ...(route.method === 'POST' ? { body: {} } : {}),
        });
        verify(result.status === 403, `role boundary: ${role} cannot ${route.path}`);
      } else if (route.method === 'POST') {
        const result = await actor.request(route.path, {
          method: 'POST',
          body: {},
          token: 'invalid',
        });
        verify(result.status === 419, `CSRF boundary: ${route.path}`);
      }
    }
    console.log(`PASS: ${role} role and CSRF matrix`);
  }

  const post = (actor, path, body) => actor.request(path, { method: 'POST', body });
  const notice = fixtureIds[0];
  verify(
    (await post(customer, 'api/notifications/read', { id: notice })).status === 200,
    'read notification'
  );
  verify(
    (await customer.request('api/notifications')).data.items.find((item) => +item.id === notice)
      .read_at !== null,
    'read state persisted'
  );
  verify(
    (await post(customer, 'api/notifications/unread', { id: notice })).status === 200,
    'unread notification'
  );
  verify(
    (await customer.request('api/notifications')).data.items.find((item) => +item.id === notice)
      .read_at === null,
    'unread state persisted'
  );
  verify(
    (await post(owner, 'api/notifications/unread', { id: notice })).status === 404,
    'unread cannot cross account boundary'
  );
  verify(
    (await post(customer, 'api/notifications/unread', { id: fixtureIds[2] })).status === 404,
    'customer cannot edit owner notification'
  );
  verify(
    (await post(customer, 'api/notifications/unread', { id: [notice] })).status === 422,
    'array identifier rejected'
  );
  verify(
    (await post(customer, 'api/notifications/unread', { id: true })).status === 422,
    'boolean identifier rejected'
  );
  verify(
    (await post(customer, 'api/notifications/unread', { id: 1.5 })).status === 422,
    'fractional identifier rejected'
  );
  verify((await post(customer, 'api/notifications/read-all', {})).status === 200, 'bulk mark read');
  verify(
    (await customer.request('api/notifications')).data.unread_count === 0,
    'bulk read updates badge count'
  );
  verify(
    (await post(customer, 'api/notifications/unread-all', {})).status === 200,
    'bulk mark unread'
  );
  verify(
    (await customer.request('api/notifications')).data.unread_count >= 2,
    'bulk unread restores badge count'
  );
  console.log('PASS: notification read/unread persistence, counters and ownership');

  verify(
    (
      await post(owner, 'api/owner/pitch', {
        name: 'Bad price',
        type: 'Sân 5 người',
        price: 10000.9,
        peak_price: 20000,
      })
    ).status === 422,
    'fractional pitch price rejected'
  );
  verify(
    (await post(owner, 'api/owner/pitch-toggle', { id: { invalid: 1 } })).status === 422,
    'nested identifier rejected'
  );
  const voucher = (await admin.request('api/admin/overview')).data.vouchers[0];
  verify(
    (await post(admin, 'api/admin/voucher-toggle', { id: voucher.id })).status === 422,
    'missing voucher active state rejected'
  );
  verify(
    (await post(admin, 'api/admin/voucher-toggle', { id: voucher.id, active: null })).status ===
      422,
    'null voucher active state rejected'
  );

  for (const role of ['owner', 'admin']) {
    const actor = client();
    await actor.request('api/me');
    const email = `http-audit-${role}-${Date.now()}@example.test`;
    emails.push(email);
    const result = await post(actor, 'api/register', {
      role,
      name: 'HTTP audit',
      phone: '0900000006',
      email,
      password: 'Test@12345',
      venue: 'Audit venue',
    });
    verify(result.status === 200, `register ${role} input`);
    const login = await actor.login(email, 'Test@12345');
    if (role === 'owner')
      verify(login.status === 403, 'pending owner cannot log in before approval');
    else {
      verify(login.status === 200, 'registration with admin input creates ordinary customer');
      verify(
        (await actor.request('api/me')).data.user.role === 'customer',
        'self registration cannot elevate role to admin'
      );
    }
  }

  const badUpload = new FormData();
  badUpload.append('image', new Blob(['not an image'], { type: 'image/png' }), 'fake.png');
  verify(
    (await post(owner, 'api/owner/venue-image', badUpload)).status === 422,
    'invalid image bytes rejected'
  );
  const upload = new FormData();
  upload.append(
    'image',
    new Blob(
      [
        Buffer.from(
          'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII=',
          'base64'
        ),
      ],
      { type: 'image/png' }
    ),
    'fake.php'
  );
  const uploaded = await post(owner, 'api/owner/venue-image', upload);
  verify(
    uploaded.status === 200 && /uploads\/venues\/[a-f0-9]+\.png$/.test(uploaded.data.image_url),
    'upload is stored using validated image extension'
  );
  uploadedImage = (await owner.request('api/owner/overview')).data.venue_images.find(
    (image) => image.url === uploaded.data.image_url
  )?.id;
  verify(Boolean(uploadedImage), 'uploaded image linked to owner database record');
  verify(
    (await post(owner, 'api/owner/venue-image-delete', { id: uploadedImage })).status === 200,
    'owner removes uploaded image and metadata'
  );
  uploadedImage = null;
  console.log('PASS: scalar validation, registration roles and image upload workflow');
  console.log(`${checks} HTTP audit checks passed.`);
} finally {
  if (uploadedImage)
    await owner.request('api/owner/venue-image-delete', {
      method: 'POST',
      body: { id: uploadedImage },
    });
  execFileSync(php, [fixtures, 'cleanup', JSON.stringify({ notifications: fixtureIds, emails })]);
}
