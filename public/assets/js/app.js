// Tiện ích dùng chung cho mọi trang (View gọi API qua App.api)
const App = (() => {
  let csrf = '';
  const $ = s => document.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = n => Number(n).toLocaleString('vi-VN') + 'đ';
  const pad = h => String(h).padStart(2, '0') + ':00';
  const dmy = s => String(s).split('-').reverse().join('/');

  async function api(path, { method = 'GET', body } = {}) {
    const formData = body instanceof FormData;
    const res = await fetch('api/' + path, {
      method, credentials: 'same-origin',
      headers: { ...(formData ? {} : { 'Content-Type': 'application/json' }), 'X-CSRF-Token': csrf },
      body: body ? (formData ? body : JSON.stringify(body)) : undefined
    });
    let d = {}; try { d = await res.json(); } catch (e) {}
    if (res.status === 401) { location.href = 'login'; throw new Error('Cần đăng nhập'); }
    if (!res.ok) throw new Error(d.error || 'Có lỗi xảy ra.');
    return d;
  }
  function flash(msg, type = 'ok') {
    const el = $('#flash'); if (!el) return alert(msg);
    el.className = 'flash ' + type; el.textContent = msg; el.hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function setupProfile(user) {
    const form = $('#profileForm');
    if (!form || !user) return;
    const roles = { customer: 'Khách hàng', owner: 'Chủ sân', admin: 'Quản trị viên' };
    const resetPasswordFields = () => {
      ['profileCurrentPassword', 'profileNewPassword', 'profileConfirmPassword'].forEach(id => {
        const input = document.getElementById(id);
        const toggle = document.querySelector(`[data-password-toggle="${id}"]`);
        input.type = 'password';
        toggle.textContent = 'Hiện';
        toggle.setAttribute('aria-label', 'Hiện mật khẩu');
        toggle.setAttribute('aria-pressed', 'false');
      });
    };
    $('#profileBtn').onclick = () => {
      $('#profileName').value = user.name || '';
      $('#profileEmail').value = user.email || '';
      $('#profilePhone').value = user.phone || '';
      $('#profileRole').value = roles[user.role] || 'Người dùng';
      resetPasswordFields();
      $('#profileCurrentPassword').value = '';
      $('#profileNewPassword').value = '';
      $('#profileConfirmPassword').value = '';
      $('#profileMessage').textContent = '';
      $('#profileMessage').className = 'profile-message';
      $('#accountDropdown').hidden = true;
      $('#accountBtn').setAttribute('aria-expanded', 'false');
      $('#profileDlg').showModal();
    };
    $('#profileClose').onclick = $('#profileCloseIcon').onclick = () => $('#profileDlg').close();
    form.onsubmit = async e => {
      e.preventDefault();
      const save = $('#profileSave');
      const message = $('#profileMessage');
      save.disabled = true;
      message.textContent = '';
      message.className = 'profile-message';
      try {
        const { user: updated } = await api('profile', { method: 'POST', body: {
          name: $('#profileName').value,
          email: $('#profileEmail').value,
          phone: $('#profilePhone').value,
          current_password: $('#profileCurrentPassword').value,
          new_password: $('#profileNewPassword').value,
          confirm_password: $('#profileConfirmPassword').value
        } });
        Object.assign(user, updated);
        $('#whoami').textContent = updated.name;
        resetPasswordFields();
        $('#profileCurrentPassword').value = '';
        $('#profileNewPassword').value = '';
        $('#profileConfirmPassword').value = '';
        message.textContent = 'Thông tin cá nhân đã được cập nhật.';
        message.classList.add('success');
      } catch (error) {
        message.textContent = error.message;
        message.classList.add('error');
      } finally { save.disabled = false; }
    };
  }
  function setupNotifications(user) {
    const controls = $('.who');
    if (!controls || !user || !['customer', 'owner'].includes(user.role)) return;

    const center = document.createElement('div');
    center.className = 'notification-center';
    center.innerHTML = `<button type="button" class="btn ghost light notification-toggle" aria-expanded="false" aria-controls="notificationPanel">
        Thông báo <span class="notification-count" hidden></span>
      </button>
      <section id="notificationPanel" class="notification-panel" aria-label="Thông báo" hidden>
        <div class="notification-heading"><b>Thông báo của bạn</b>
          <button type="button" class="notification-read-all" disabled>Đánh dấu đã đọc</button>
        </div>
        <div class="notification-list" role="list" aria-live="polite"><p class="hint">Đang tải thông báo...</p></div>
      </section>`;
    controls.append(center);
    const toggle = center.querySelector('.notification-toggle');
    const panel = center.querySelector('#notificationPanel');
    const list = center.querySelector('.notification-list');
    const readAll = center.querySelector('.notification-read-all');
    let loading = false;

    function render(data) {
      const count = Number(data.unread_count || 0);
      const badge = center.querySelector('.notification-count');
      badge.hidden = count === 0;
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.setAttribute('aria-label', `${count} thông báo chưa đọc`);
      readAll.disabled = count === 0;
      list.innerHTML = data.items.length ? data.items.map(item => {
        const time = new Date(String(item.created_at).replace(' ', 'T') + '+07:00');
        const dateText = Number.isNaN(time.getTime()) ? esc(item.created_at) : esc(time.toLocaleString('vi-VN'));
        const safeLink = ['dat-san', 'chu-san'].some(prefix => String(item.link || '').startsWith(prefix))
          ? esc(item.link) : '';
        return `<button type="button" role="listitem" class="notification-item ${item.read_at ? '' : 'unread'}"
            data-notification-id="${Number(item.id)}" data-notification-link="${safeLink}">
          <span class="notification-item-title">${esc(item.title)}</span>
          <span class="notification-item-message">${esc(item.message)}</span>
          <time>${dateText}</time>
        </button>`;
      }).join('') : '<p class="empty">Bạn chưa có thông báo nào.</p>';
    }

    async function refresh() {
      if (loading) return;
      loading = true;
      try {
        render(await api('notifications'));
      } catch (error) {
        list.innerHTML = `<p class="error notification-error">${esc(error.message || 'Không thể tải thông báo.')}</p>`;
      } finally {
        loading = false;
      }
    }
    toggle.addEventListener('click', async () => {
      const opening = panel.hidden;
      panel.hidden = !opening;
      toggle.setAttribute('aria-expanded', String(opening));
      if (opening) await refresh();
    });
    readAll.addEventListener('click', async () => {
      readAll.disabled = true;
      try {
        await api('notifications/read-all', { method: 'POST', body: {} });
        await refresh();
      } catch (error) {
        list.innerHTML = `<p class="error notification-error">${esc(error.message || 'Không thể cập nhật thông báo.')}</p>`;
        readAll.disabled = false;
      }
    });
    list.addEventListener('click', async event => {
      const item = event.target.closest('[data-notification-id]');
      if (!item) return;
      item.disabled = true;
      try {
        await api('notifications/read', { method: 'POST', body: { id: Number(item.dataset.notificationId) } });
        const link = item.dataset.notificationLink;
        if (link) {
          location.href = link;
          return;
        }
        await refresh();
      } catch (error) {
        item.disabled = false;
        list.insertAdjacentHTML('afterbegin', `<p class="error notification-error">${esc(error.message || 'Không thể cập nhật thông báo.')}</p>`);
      }
    });
    document.addEventListener('click', event => {
      if (!center.contains(event.target) && !panel.hidden) {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
    refresh();
    window.setInterval(() => { if (!document.hidden) refresh(); }, 30000);
  }
  async function init() {
    let theme = localStorage.getItem('sannha-theme') === 'dark' ? 'dark' : 'light';
    document.documentElement.dataset.theme = theme;
    const themeButton = document.createElement('button');
    themeButton.type = 'button';
    themeButton.className = 'btn ghost light theme-toggle';
    const updateThemeButton = () => {
      themeButton.textContent = theme === 'dark' ? '☀ Giao diện sáng' : '☾ Giao diện tối';
      themeButton.setAttribute('aria-pressed', String(theme === 'dark'));
      themeButton.setAttribute('aria-label', theme === 'dark' ? 'Chuyển sang giao diện sáng' : 'Chuyển sang giao diện tối');
    };
    updateThemeButton();
    themeButton.onclick = () => {
      theme = theme === 'dark' ? 'light' : 'dark';
      localStorage.setItem('sannha-theme', theme);
      document.documentElement.dataset.theme = theme;
      updateThemeButton();
    };
    const controls = $('.who');
    if (controls) controls.prepend(themeButton);
    else {
      const dock = document.createElement('div');
      dock.className = 'theme-dock';
      dock.appendChild(themeButton);
      $('.top .wrap')?.appendChild(dock);
    }
    document.addEventListener('click', e => {
      const toggle = e.target.closest('[data-password-toggle]');
      if (!toggle) return;
      const input = document.getElementById(toggle.dataset.passwordToggle);
      if (!input || input.type !== 'password' && input.type !== 'text') return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      toggle.textContent = show ? 'Ẩn' : 'Hiện';
      toggle.setAttribute('aria-label', `${show ? 'Ẩn' : 'Hiện'} mật khẩu`);
      toggle.setAttribute('aria-pressed', String(show));
    });
    const r = await (await fetch('api/me', { credentials: 'same-origin' })).json();
    csrf = r.csrf;
    if ($('#whoami') && r.user) $('#whoami').textContent = r.user.name;
    setupNotifications(r.user);
    if ($('#logoutBtn')) {
      $('#logoutBtn').hidden = !r.user;
      if (r.user) $('#logoutBtn').onclick = async () => { await api('logout', { method: 'POST' }); location.href = 'login'; };
    }
    return r.user;
  }
  return { $, esc, money, pad, dmy, api, flash, init, setupProfile };
})();
