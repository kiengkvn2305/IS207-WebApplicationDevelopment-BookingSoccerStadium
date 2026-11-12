// Run only against tests/http-router.php and the isolated sannha_test database.
import assert from 'node:assert/strict';
const base = 'http://127.0.0.1:8089/';
let cookie = '',
  csrf = '',
  count = 0;
async function request(path, body, token = csrf, raw = false) {
  const response = await fetch(base + path, {
    redirect: 'manual',
    headers: { Cookie: cookie, 'Content-Type': 'application/json', 'X-CSRF-Token': token },
    ...(body === undefined ? {} : { method: 'POST', body: raw ? body : JSON.stringify(body) }),
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
  return { response, data };
}
function check(condition, label) {
  assert.ok(condition, label);
  count++;
  console.log('PASS: ' + label);
}
let result = await request('api/login', { email: 'admin@sannha.vn', password: 'Admin@12345' }, '');
check(result.response.status === 419, 'empty CSRF rejected before session token exists');
result = await request('api/book', {});
check(result.response.status === 401, 'anonymous booking rejected');
result = await request('admin');
check(
  result.response.status === 302 && result.response.headers.get('location') === '/login',
  'anonymous admin redirected'
);
result = await request('api/me');
csrf = result.data.csrf;
check(typeof csrf === 'string' && csrf.length > 0, 'session CSRF issued');
result = await request('api/login', '{broken', csrf, true);
check(result.response.status === 400, 'malformed JSON rejected');
result = await request('api/login', '42', csrf, true);
check(result.response.status === 400, 'scalar JSON rejected');
result = await request('api/login', { email: 'khach@sannha.vn', password: 'Demo@12345' });
check(result.response.status === 200, 'customer login');
result = await request('api/me');
csrf = result.data.csrf;
check(result.data.user.profile_version >= 2, 'profile version returned after reload');
result = await request('api/admin/overview');
check(result.response.status === 403, 'customer cannot access admin API');
const date = new Date(Date.now() + 86400000 * 2).toLocaleDateString('en-CA', {
  timeZone: 'Asia/Ho_Chi_Minh',
});
result = await request('api/book', { pitch_id: 1, date, hours: [10.5] });
check(result.response.status === 422, 'fractional hour rejected');
result = await request('api/book', { pitch_id: 1, date, hours: [10, 10] });
check(result.response.status === 422, 'duplicate hour rejected');
result = await request('api/book', { pitch_id: 1, date, hours: '10' });
check(result.response.status === 422, 'scalar hours rejected');
result = await request('api/book', { pitch_id: 1, date, hours: [10, 12] });
check(result.response.status === 422, 'noncontiguous hours rejected');
result = await request('api/book', { pitch_id: 1, date, hours: [6] }, 'wrong-token');
check(result.response.status === 419, 'invalid CSRF rejected');
result = await request('api/slots?date=' + date);
check(
  result.response.status === 200 && result.data.venues.length === 1,
  'real database availability response'
);
result = await request('api/logout', {});
check(result.response.status === 200, 'logout');
result = await request('api/me');
csrf = result.data.csrf;
result = await request('api/login', { email: 'admin@sannha.vn', password: 'Admin@12345' });
check(result.response.status === 200, 'admin login');
result = await request('api/me');
csrf = result.data.csrf;
result = await request('api/admin/overview');
check(
  result.response.status === 200 && Array.isArray(result.data.vouchers),
  'admin dashboard backed by database'
);
const code = 'HTTP_' + Date.now();
result = await request('api/admin/voucher', {
  code,
  discount_type: 'percent',
  discount_value: 20,
  min_total: 0,
  starts_at: '2026-01-01T00:00',
  ends_at: '2029-01-01T00:00',
  usage_limit: 1,
});
check(result.response.status === 200 && result.data.id > 0, 'admin creates voucher');
result = await request('api/admin/voucher', {
  code,
  discount_type: 'percent',
  discount_value: 20,
  min_total: 0,
  starts_at: '2026-01-01T00:00',
  ends_at: '2029-01-01T00:00',
  usage_limit: 1,
});
check(result.response.status === 409, 'duplicate voucher rejected');
result = await request('api/logout', {});
result = await request('api/me');
csrf = result.data.csrf;
result = await request('api/login', { email: 'chusan@sannha.vn', password: 'Demo@12345' });
check(result.response.status === 200, 'owner login');
result = await request('api/me');
csrf = result.data.csrf;
result = await request('api/owner/overview');
check(
  result.response.status === 200 && result.data.pitches.length === 3,
  'owner dashboard backed by database'
);
result = await request('api/webhooks/sepay', {});
check(result.response.status === 503, 'unconfigured payment webhook fails explicitly');
console.log(`${count} HTTP checks passed.`);
