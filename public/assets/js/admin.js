(async () => {
  const { $, esc, money, pad, dmy, api, flash } = App;
  await App.init();
  const ROLE = { customer: 'Người đặt', owner: 'Chủ sân', admin: 'Admin' };
  const ST = { active: 'Hoạt động', pending: 'Chờ duyệt', locked: 'Đã khóa' };
  const TAG = { active: 'paid', pending: 'pending', locked: 'cancelled' };
  function filterAccounts() {
    const query = $('#accountSearch').value.trim().toLocaleLowerCase('vi');
    document.querySelectorAll('#userRows tr').forEach((row) => {
      row.hidden = !row.textContent.toLocaleLowerCase('vi').includes(query);
    });
  }
  $('#accountSearch').addEventListener('input', filterAccounts);

  async function load() {
    const d = await api('admin/overview'),
      s = d.stats;
    $('#stats').innerHTML = [
      [s.customers, 'Người đặt sân'],
      [s.owners, 'Chủ sân'],
      [s.pending_owners, 'Chủ sân chờ duyệt'],
      [s.paid, 'Đơn đã thanh toán'],
      [money(s.revenue), 'Tổng giá trị đơn'],
    ]
      .map(([n, l]) => `<div><b>${esc(n)}</b>${l}</div>`)
      .join('');
    $('#userRows').innerHTML = d.users
      .map((u) => {
        let act = '';
        if (u.role !== 'admin') {
          if (u.status === 'pending')
            act = `<button class="btn sm" data-act="approve" data-id="${+u.id}">Duyệt</button>`;
          if (u.status === 'active')
            act = `<button class="btn ghost sm" data-act="lock" data-id="${+u.id}">Khóa</button>`;
          if (u.status === 'locked')
            act = `<button class="btn ghost sm" data-act="unlock" data-id="${+u.id}">Mở khóa</button>`;
        }
        return `<tr><td>${esc(u.name)}${u.venue_name ? `<br><small>${esc(u.venue_name)}</small>` : ''}</td><td>${esc(u.email)}<br><small>${esc(u.phone)}</small></td>
        <td>${ROLE[u.role]}</td><td><span class="tag ${TAG[u.status]}">${ST[u.status]}</span></td><td>${act}</td></tr>`;
      })
      .join('');
    filterAccounts();
    $('#bookingRows').innerHTML = d.bookings
      .map(
        (
          b
        ) => `<tr><td>${esc(b.code)}</td><td>${esc(b.cname)}</td><td>${esc(b.wname)}</td><td>${esc(b.pitch)}</td>
      <td>${dmy(b.date)} ${pad(b.start_hour)}</td><td>${money(b.total)}</td>
      <td>${+b.discount > 0 ? `${esc(b.voucher_code)} · ${money(b.discount)}` : '—'}</td>
      <td><span class="tag ${esc(b.status)}">${esc(b.status_text)}</span></td></tr>`
      )
      .join('');
    $('#logRows').innerHTML = d.logs
      .map(
        (l) =>
          `<tr><td>${esc(l.created_at)}</td><td>${esc(l.email || '—')}</td><td>${esc(l.action)}</td><td>${esc(l.detail)}</td><td>${esc(l.ip)}</td></tr>`
      )
      .join('');
    $('#reviewRows').innerHTML =
      d.reviews
        .map(
          (r) => `<tr><td>${esc(r.venue_name)}</td><td>${esc(r.customer_name)}</td>
      <td>${'★'.repeat(Number(r.rating))}${'☆'.repeat(5 - Number(r.rating))}</td><td>${esc(r.comment)}</td>
      <td>${+r.visible ? 'Đang hiển thị' : 'Đã ẩn'}</td><td><button class="btn ghost sm" data-review-act="${+r.visible ? 'hide' : 'show'}" data-id="${+r.id}">${+r.visible ? 'Ẩn' : 'Hiện lại'}</button></td></tr>`
        )
        .join('') || '<tr><td colspan="6" class="empty">Chưa có đánh giá.</td></tr>';
    $('#voucherRows').innerHTML =
      d.vouchers
        .map(
          (v) => `<tr>
      <td><b>${esc(v.code)}</b></td>
      <td>${v.discount_type === 'percent' ? `${+v.discount_value}%` : money(v.discount_value)}</td>
      <td>${money(v.min_total)}</td>
      <td>${esc(v.starts_at)} – ${esc(v.ends_at)}</td>
      <td>${+v.usage_count}${v.usage_limit === null ? ' / không giới hạn' : ` / ${+v.usage_limit}`}</td>
      <td><span class="tag ${+v.active ? 'paid' : 'cancelled'}">${+v.active ? 'Đang bật' : 'Đã tắt'}</span></td>
      <td><button class="btn ghost sm" data-voucher-active="${+v.active ? 0 : 1}" data-id="${+v.id}">${+v.active ? 'Tắt mã' : 'Bật mã'}</button></td>
      </tr>`
        )
        .join('') || '<tr><td colspan="7" class="empty">Chưa có voucher.</td></tr>';
  }
  document.addEventListener('click', async (e) => {
    const reviewButton = e.target.closest('button[data-review-act]');
    if (reviewButton) {
      try {
        await api('admin/review', {
          method: 'POST',
          body: { id: +reviewButton.dataset.id, action: reviewButton.dataset.reviewAct },
        });
        flash('Đã cập nhật trạng thái đánh giá.');
        await load();
      } catch (err) {
        flash(err.message, 'err');
      }
      return;
    }
    const voucherButton = e.target.closest('button[data-voucher-active]');
    if (voucherButton) {
      voucherButton.disabled = true;
      try {
        await api('admin/voucher-toggle', {
          method: 'POST',
          body: {
            id: +voucherButton.dataset.id,
            active: voucherButton.dataset.voucherActive === '1',
          },
        });
        flash('Đã cập nhật trạng thái voucher.');
        await load();
      } catch (error) {
        flash(error.message, 'err');
        voucherButton.disabled = false;
      }
      return;
    }
    const b = e.target.closest('button[data-act]');
    if (!b) return;
    try {
      await api('admin/user', {
        method: 'POST',
        body: { id: +b.dataset.id, action: b.dataset.act },
      });
      flash('Đã cập nhật tài khoản.');
      await load();
    } catch (err) {
      flash(err.message, 'err');
    }
  });
  $('#voucherType').addEventListener('change', () => {
    const isPercent = $('#voucherType').value === 'percent';
    $('#voucherValue').max = isPercent ? '100' : '5000000';
    $('#voucherValue').value = '';
  });
  $('#voucherForm').onsubmit = async (event) => {
    event.preventDefault();
    const form = event.target;
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    const body = Object.fromEntries(new FormData(form));
    try {
      await api('admin/voucher', { method: 'POST', body });
      form.reset();
      $('#voucherType').dispatchEvent(new Event('change'));
      flash('Đã tạo voucher.');
      await load();
    } catch (error) {
      flash(error.message, 'err');
    } finally {
      submit.disabled = false;
    }
  };
  $('#voucherType').dispatchEvent(new Event('change'));
  load().catch((e) => flash(e.message, 'err'));
})().catch((error) => App.flash(error.message || 'Không thể tải trang quản trị.', 'err'));
