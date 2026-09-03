// Booth JS: handle barcode input, send to scan.php, and update UI.
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('barcodeInput');
    const loader = document.getElementById('loader');
    const alertArea = document.getElementById('alertArea');
    const reservationCard = document.getElementById('reservationCard');
    const paymentCard = document.getElementById('paymentCard');
    const clearBtn = document.getElementById('clearBtn');

    function showLoader(show) {
        loader.classList.toggle('d-none', !show);
    }

    function showAlert(message, type = 'danger') {
        alertArea.innerHTML = `<div class="alert alert-${type} py-2">${message}</div>`;
    }

    function clearAlert() { alertArea.innerHTML = ''; }

    function resetCards() {
        reservationCard.classList.add('d-none');
        paymentCard.classList.add('d-none');
    }

    clearBtn.addEventListener('click', function () {
        input.value = '';
        input.focus();
        resetCards();
        clearAlert();
    });

    input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            const raw = input.value.trim();
            if (!raw) return;
            clearAlert();
            processBarcode(raw);
        }
    });

    async function processBarcode(barcode) {
        showLoader(true);
        try {
            const resp = await fetch('scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ barcode })
            });

            const data = await resp.json();
            if (!resp.ok || !data) {
                showAlert('Server error while processing barcode.', 'danger');
                return;
            }

            if (!data.success) {
                showAlert(data.message || 'Invalid barcode', 'danger');
                return;
            }

            // Update UI with reservation and payment data
            updateReservationUI(data.data || {});
        } catch (ex) {
            showAlert('Network error: ' + ex.message, 'danger');
        } finally {
            showLoader(false);
            input.value = '';
            input.focus();
        }
    }

    function formatMoney(n) { return '₱' + Number(n || 0).toFixed(2); }

    function mapStatusForBadge(s) {
        if (!s) return ['Invalid', 'badge-status-invalid'];
        const st = String(s).toLowerCase();
        if (st === 'reserved') return ['Reserved', 'badge-status-reserved'];
        if (st === 'parked' || st === 'active') return ['Active', 'badge-status-active'];
        if (st === 'completed' || st === 'exited') return ['Completed', 'badge-status-completed'];
        return [s, 'badge-status-invalid'];
    }

    function updateReservationUI(payload) {
        if (!payload.reservation) return;
        const r = payload.reservation;
        document.getElementById('resId').textContent = r.barcode_value || r.reservation_id || ('#' + r.id);
        document.getElementById('customerName').textContent = r.full_name || r.customer_name || '—';
        document.getElementById('location').textContent = (r.parking_floor || '') + ' — ' + (r.parking_slot || '');
        document.getElementById('scheduled').textContent = (r.reservation_date || '') + ' ' + (r.reserved_time_in || '');
        document.getElementById('times').textContent = (r.actual_time_in || '-') + ' / ' + (r.actual_time_out || '-');

        const [label, cls] = mapStatusForBadge(r.status || payload.status || 'Invalid');
        const statusBadge = document.getElementById('statusBadge');
        statusBadge.textContent = label;
        statusBadge.className = 'badge rounded-pill py-2 ' + cls;

        reservationCard.classList.remove('d-none');

        if (payload.transaction) {
            paymentCard.classList.remove('d-none');
            document.getElementById('duration').textContent = (payload.transaction.duration_display || '-');
            document.getElementById('baseRate').textContent = formatMoney(payload.transaction.base_rate || 0);
            document.getElementById('overtime').textContent = formatMoney(payload.transaction.overtime_amount || 0);
            document.getElementById('totalAmount').textContent = formatMoney(payload.transaction.total_payment || 0);
        } else {
            paymentCard.classList.add('d-none');
        }
    }

    // Keep focus on input
    setInterval(() => { if (document.activeElement !== input) input.focus(); }, 1000);
});
