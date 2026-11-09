(async () => {
  const { $, api, flash } = App; await App.init();
  const show = reg => {
    $('#loginForm').hidden = reg; $('#registerForm').hidden = !reg;
    $('#title').textContent = reg ? 'Tạo tài khoản' : 'Đăng nhập'; $('#flash').hidden = true;
  };
  $('#toRegister').onclick = e => { e.preventDefault(); show(true); };
  $('#toLogin').onclick = e => { e.preventDefault(); show(false); };
  $('#role').onchange = e => { $('#venueRow').hidden = e.target.value !== 'owner'; };
  const data = f => Object.fromEntries(new FormData(f));

  $('#loginForm').onsubmit = async e => {
    e.preventDefault();
    try { const r = await api('login', { method: 'POST', body: data(e.target) }); location.href = r.redirect; }
    catch (err) { flash(err.message, 'err'); }
  };
  $('#registerForm').onsubmit = async e => {
    e.preventDefault();
    try { const r = await api('register', { method: 'POST', body: data(e.target) }); e.target.reset(); show(false); flash(r.message); }
    catch (err) { flash(err.message, 'err'); }
  };
})();
