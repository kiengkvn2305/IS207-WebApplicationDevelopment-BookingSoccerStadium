(async () => {
  const { $, esc, money, pad, dmy, api, flash } = App; await App.init();
  const ROLE = { customer: 'Người đặt', owner: 'Chủ sân', admin: 'Admin' };
  const ST = { active: 'Hoạt động', pending: 'Chờ duyệt', locked: 'Đã khóa' };
  const TAG = { active: 'paid', pending: 'pending', locked: 'cancelled' };

  async function load() {
    const d = await api('admin/overview'), s = d.stats;
    $('#stats').innerHTML = [[s.customers, 'Người đặt sân'], [s.owners, 'Chủ sân'], [s.pending_owners, 'Chủ sân chờ duyệt'],
      [s.paid, 'Đơn đã thanh toán'], [money(s.revenue), 'Tổng giá trị đơn']].map(([n, l]) => `<div><b>${esc(n)}</b>${l}</div>`).join('');
    $('#userRows').innerHTML = d.users.map(u => {
      let act = '';
      if (u.role !== 'admin') {
        if (u.status === 'pending') act = `<button class="btn sm" data-act="approve" data-id="${+u.id}">Duyệt</button>`;
        if (u.status === 'active') act = `<button class="btn ghost sm" data-act="lock" data-id="${+u.id}">Khóa</button>`;
        if (u.status === 'locked') act = `<button class="btn ghost sm" data-act="unlock" data-id="${+u.id}">Mở khóa</button>`;
      }
      return `<tr><td>${esc(u.name)}${u.venue_name ? `<br><small>${esc(u.venue_name)}</small>` : ''}</td><td>${esc(u.email)}<br><small>${esc(u.phone)}</small></td>
        <td>${ROLE[u.role]}</td><td><span class="tag ${TAG[u.status]}">${ST[u.status]}</span></td><td>${act}</td></tr>`;
    }).join('');
    $('#bookingRows').innerHTML = d.bookings.map(b => `<tr><td>${esc(b.code)}</td><td>${esc(b.cname)}</td><td>${esc(b.wname)}</td><td>${esc(b.pitch)}</td>
      <td>${dmy(b.date)} ${pad(b.start_hour)}</td><td>${money(b.total)}</td><td><span class="tag ${esc(b.status)}">${esc(b.status_text)}</span></td></tr>`).join('');
    $('#logRows').innerHTML = d.logs.map(l => `<tr><td>${esc(l.created_at)}</td><td>${esc(l.email || '—')}</td><td>${esc(l.action)}</td><td>${esc(l.detail)}</td><td>${esc(l.ip)}</td></tr>`).join('');
    $('#reviewRows').innerHTML = d.reviews.map(r => `<tr><td>${esc(r.venue_name)}</td><td>${esc(r.customer_name)}</td>
      <td>${'★'.repeat(Number(r.rating))}${'☆'.repeat(5 - Number(r.rating))}</td><td>${esc(r.comment)}</td>
      <td>${+r.visible ? 'Đang hiển thị' : 'Đã ẩn'}</td><td><button class="btn ghost sm" data-review-act="${+r.visible ? 'hide' : 'show'}" data-id="${+r.id}">${+r.visible ? 'Ẩn' : 'Hiện lại'}</button></td></tr>`).join('')
      || '<tr><td colspan="6" class="empty">Chưa có đánh giá.</td></tr>';
  }
  document.addEventListener('click', async e => {
    const reviewButton = e.target.closest('button[data-review-act]');
    if (reviewButton) {
      try {
        await api('admin/review', { method: 'POST', body: { id: +reviewButton.dataset.id, action: reviewButton.dataset.reviewAct } });
        flash('Đã cập nhật trạng thái đánh giá.');
        await load();
      } catch (err) { flash(err.message, 'err'); }
      return;
    }
    const b = e.target.closest('button[data-act]'); if (!b) return;
    try { await api('admin/user', { method: 'POST', body: { id: +b.dataset.id, action: b.dataset.act } }); flash('Đã cập nhật tài khoản.'); await load(); }
    catch (err) { flash(err.message, 'err'); }
  });
  load().catch(e => flash(e.message, 'err'));
})();
