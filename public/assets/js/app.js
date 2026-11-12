// Tiện ích dùng chung cho mọi trang (View gọi API qua App.api)
const App = (() => {
  let csrf = '';
  const $ = (s) => document.querySelector(s);
  const esc = (s) =>
    String(s ?? '').replace(
      /[&<>"']/g,
      (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
    );
  const money = (n) => Number(n).toLocaleString('vi-VN') + 'đ';
  const pad = (h) => String(h).padStart(2, '0') + ':00';
  const dmy = (s) => String(s).split('-').reverse().join('/');

  async function api(path, { method = 'GET', body } = {}) {
    const formData = body instanceof FormData;
    const res = await fetch('api/' + path, {
      method,
      credentials: 'same-origin',
      headers: {
        ...(formData ? {} : { 'Content-Type': 'application/json' }),
        'X-CSRF-Token': csrf,
      },
      body: body ? (formData ? body : JSON.stringify(body)) : undefined,
    });
    let d = {};
    try {
      d = await res.json();
    } catch (e) {}
    if (res.status === 401) {
      location.href = 'login';
      throw new Error('Cần đăng nhập');
    }
    if (!res.ok) {
      const error = new Error(d.error || 'Có lỗi xảy ra.');
      error.status = res.status;
      throw error;
    }
    return d;
  }
  function flash(msg, type = 'ok') {
    const el = $('#flash');
    if (!el) return alert(msg);
    el.className = 'flash ' + type;
    el.textContent = msg;
    el.hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function setupProfile(user) {
    const form = $('#profileForm');
    if (!form || !user) return;
    const roles = { customer: 'Khách hàng', owner: 'Chủ sân', admin: 'Quản trị viên' };
    const resetPasswordFields = () => {
      ['profileCurrentPassword', 'profileNewPassword', 'profileConfirmPassword'].forEach((id) => {
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
      form.elements.profile_version.value = user.profile_version || 1;
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
    form.onsubmit = async (e) => {
      e.preventDefault();
      const save = $('#profileSave');
      const message = $('#profileMessage');
      save.disabled = true;
      message.textContent = '';
      message.className = 'profile-message';
      try {
        const { user: updated } = await api('profile', {
          method: 'POST',
          body: {
            name: $('#profileName').value,
            email: $('#profileEmail').value,
            phone: $('#profilePhone').value,
            profile_version: Number(form.elements.profile_version.value),
            current_password: $('#profileCurrentPassword').value,
            new_password: $('#profileNewPassword').value,
            confirm_password: $('#profileConfirmPassword').value,
          },
        });
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
      } finally {
        save.disabled = false;
      }
    };
  }
  async function init() {
    let theme = 'dark';
    try {
      theme = localStorage.getItem('sannha-theme') === 'light' ? 'light' : 'dark';
    } catch (_) {}
    document.documentElement.dataset.theme = theme;
    const themeButton = document.createElement('button');
    themeButton.type = 'button';
    themeButton.className = 'btn ghost light theme-toggle';
    const updateThemeButton = () => {
      themeButton.textContent = theme === 'dark' ? '☀ Giao diện sáng' : '☾ Giao diện tối';
      themeButton.setAttribute('aria-pressed', String(theme === 'dark'));
      themeButton.setAttribute(
        'aria-label',
        theme === 'dark' ? 'Chuyển sang giao diện sáng' : 'Chuyển sang giao diện tối'
      );
    };
    updateThemeButton();
    themeButton.onclick = () => {
      theme = theme === 'dark' ? 'light' : 'dark';
      try {
        localStorage.setItem('sannha-theme', theme);
      } catch (_) {}
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
    document.addEventListener('click', (e) => {
      const toggle = e.target.closest('[data-password-toggle]');
      if (!toggle) return;
      const input = document.getElementById(toggle.dataset.passwordToggle);
      if (!input || (input.type !== 'password' && input.type !== 'text')) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      toggle.textContent = show ? 'Ẩn' : 'Hiện';
      toggle.setAttribute('aria-label', `${show ? 'Ẩn' : 'Hiện'} mật khẩu`);
      toggle.setAttribute('aria-pressed', String(show));
    });
    const r = await api('me');
    csrf = r.csrf;
    if ($('#whoami') && r.user) $('#whoami').textContent = r.user.name;
    NotificationCenter.setup({ user: r.user, controls: $('.who'), api, esc });
    if ($('#logoutBtn')) {
      $('#logoutBtn').hidden = !r.user;
      if (r.user)
        $('#logoutBtn').onclick = async () => {
          await api('logout', { method: 'POST' });
          location.href = 'login';
        };
    }
    return r.user;
  }
  return { $, esc, money, pad, dmy, api, flash, init, setupProfile };
})();
