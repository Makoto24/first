// レンタカー管理システム
class RentalCarManager {
    constructor() {
        this.vehicles = JSON.parse(localStorage.getItem('vehicles')) || [];
        this.reservations = JSON.parse(localStorage.getItem('reservations')) || [];
        this.currentEditingId = null;
        this.startDate = new Date();
        this.endDate = new Date(this.startDate.getTime() + 30 * 24 * 60 * 60 * 1000);

        this.initializeUI();
        this.addEventListeners();
        this.render();
    }

    initializeUI() {
        const today = new Date().toISOString().split('T')[0];
        const thirtyDaysLater = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

        document.getElementById('startDate').value = today;
        document.getElementById('endDate').value = thirtyDaysLater;
    }

    addEventListeners() {
        document.getElementById('updateBtn').addEventListener('click', () => this.updateDateRange());
        document.getElementById('addVehicleBtn').addEventListener('click', () => this.addVehicle());
        document.getElementById('addReservationBtn').addEventListener('click', () => this.addReservation());
        document.getElementById('saveEditBtn').addEventListener('click', () => this.saveEdit());
        document.getElementById('deleteReservationBtn').addEventListener('click', () => this.deleteReservation());

        document.querySelector('.close').addEventListener('click', () => this.closeModal());
        window.addEventListener('click', (e) => {
            const modal = document.getElementById('editModal');
            if (e.target === modal) this.closeModal();
        });
    }

    updateDateRange() {
        this.startDate = new Date(document.getElementById('startDate').value);
        this.endDate = new Date(document.getElementById('endDate').value);
        this.render();
    }

    addVehicle() {
        const name = document.getElementById('vehicleName').value.trim();
        if (!name) {
            alert('車名を入力してください');
            return;
        }

        const vehicle = {
            id: Date.now(),
            name: name
        };

        this.vehicles.push(vehicle);
        this.saveData();
        document.getElementById('vehicleName').value = '';
        this.render();
    }

    addReservation() {
        const vehicleId = document.getElementById('vehicleSelect').value;
        const customerName = document.getElementById('customerName').value.trim();
        const startStr = document.getElementById('reservationStart').value;
        const endStr = document.getElementById('reservationEnd').value;

        if (!vehicleId || !customerName || !startStr || !endStr) {
            alert('すべての項目を入力してください');
            return;
        }

        const start = new Date(startStr);
        const end = new Date(endStr);

        if (end <= start) {
            alert('終了日時は開始日時より後にしてください');
            return;
        }

        // 予約重複チェック
        if (this.hasConflict(parseInt(vehicleId), start, end)) {
            alert('この時間は既に予約されています');
            return;
        }

        const reservation = {
            id: Date.now(),
            vehicleId: parseInt(vehicleId),
            customerName: customerName,
            start: start.toISOString(),
            end: end.toISOString()
        };

        this.reservations.push(reservation);
        this.saveData();
        this.clearReservationForm();
        this.render();
    }

    hasConflict(vehicleId, start, end) {
        return this.reservations.some(res => {
            if (res.vehicleId !== vehicleId) return false;
            const resStart = new Date(res.start);
            const resEnd = new Date(res.end);
            return (start < resEnd && end > resStart);
        });
    }

    openEditModal(reservationId) {
        const res = this.reservations.find(r => r.id === reservationId);
        if (!res) return;

        this.currentEditingId = reservationId;
        document.getElementById('editCustomerName').value = res.customerName;
        document.getElementById('editStart').value = new Date(res.start).toISOString().slice(0, 16);
        document.getElementById('editEnd').value = new Date(res.end).toISOString().slice(0, 16);
        document.getElementById('editModal').style.display = 'block';
    }

    saveEdit() {
        const res = this.reservations.find(r => r.id === this.currentEditingId);
        if (!res) return;

        res.customerName = document.getElementById('editCustomerName').value.trim();
        const newStart = new Date(document.getElementById('editStart').value);
        const newEnd = new Date(document.getElementById('editEnd').value);

        if (newEnd <= newStart) {
            alert('終了日時は開始日時より後にしてください');
            return;
        }

        // 他の予約との重複チェック（自分自身は除外）
        const hasConflict = this.reservations.some(r => {
            if (r.id === this.currentEditingId || r.vehicleId !== res.vehicleId) return false;
            const rStart = new Date(r.start);
            const rEnd = new Date(r.end);
            return (newStart < rEnd && newEnd > rStart);
        });

        if (hasConflict) {
            alert('この時間は他の予約と重複しています');
            return;
        }

        res.start = newStart.toISOString();
        res.end = newEnd.toISOString();

        this.saveData();
        this.closeModal();
        this.render();
    }

    deleteReservation() {
        if (confirm('この予約を削除してもよろしいですか？')) {
            this.reservations = this.reservations.filter(r => r.id !== this.currentEditingId);
            this.saveData();
            this.closeModal();
            this.render();
        }
    }

    closeModal() {
        document.getElementById('editModal').style.display = 'none';
        this.currentEditingId = null;
    }

    clearReservationForm() {
        document.getElementById('vehicleSelect').value = '';
        document.getElementById('customerName').value = '';
        document.getElementById('reservationStart').value = '';
        document.getElementById('reservationEnd').value = '';
    }

    saveData() {
        localStorage.setItem('vehicles', JSON.stringify(this.vehicles));
        localStorage.setItem('reservations', JSON.stringify(this.reservations));
    }

    render() {
        this.updateVehicleSelect();
        this.renderGanttChart();
        this.renderReservationsList();
    }

    updateVehicleSelect() {
        const select = document.getElementById('vehicleSelect');
        const currentValue = select.value;
        select.innerHTML = '<option value="">車を選択...</option>';

        this.vehicles.forEach(vehicle => {
            const option = document.createElement('option');
            option.value = vehicle.id;
            option.textContent = vehicle.name;
            select.appendChild(option);
        });

        select.value = currentValue;
    }

    renderGanttChart() {
        const vehiclesList = document.getElementById('vehiclesList');
        const ganttChart = document.getElementById('ganttChart');

        vehiclesList.innerHTML = '';
        ganttChart.innerHTML = '';

        if (this.vehicles.length === 0) {
            vehiclesList.innerHTML = '<div style="padding: 20px; text-align: center; color: #999;">車がまだ登録されていません</div>';
            ganttChart.innerHTML = '<div style="padding: 20px; text-align: center; color: #999;">車を登録してください</div>';
            return;
        }

        const dayWidth = 100; // ピクセル
        const daysCount = Math.ceil((this.endDate - this.startDate) / (24 * 60 * 60 * 1000)) + 1;
        const totalWidth = dayWidth * daysCount;

        // タイムスケール
        const timelineHtml = this.createTimeline(dayWidth);
        ganttChart.innerHTML = timelineHtml;

        // 各車両のガントチャート
        this.vehicles.forEach(vehicle => {
            const vehicleItem = document.createElement('div');
            vehicleItem.className = 'vehicle-item';
            vehicleItem.textContent = vehicle.name;
            vehiclesList.appendChild(vehicleItem);

            const ganttRow = document.createElement('div');
            ganttRow.className = 'gantt-row';

            const barsContainer = document.createElement('div');
            barsContainer.className = 'gantt-bars';
            barsContainer.style.width = totalWidth + 'px';

            // この車両の予約
            const vehicleReservations = this.reservations.filter(r => r.vehicleId === vehicle.id);

            vehicleReservations.forEach(res => {
                const resStart = new Date(res.start);
                const resEnd = new Date(res.end);

                // 表示範囲内かチェック
                if (resEnd > this.startDate && resStart < this.endDate) {
                    const bar = document.createElement('div');
                    bar.className = 'gantt-bar';
                    bar.textContent = res.customerName;

                    const displayStart = Math.max(resStart, this.startDate);
                    const displayEnd = Math.min(resEnd, this.endDate);

                    const leftOffset = (displayStart - this.startDate) / (24 * 60 * 60 * 1000) * dayWidth;
                    const width = (displayEnd - displayStart) / (24 * 60 * 60 * 1000) * dayWidth;

                    bar.style.left = leftOffset + 'px';
                    bar.style.width = Math.max(width, 50) + 'px'; // 最小幅50px

                    bar.addEventListener('click', () => this.openEditModal(res.id));

                    barsContainer.appendChild(bar);
                }
            });

            ganttRow.appendChild(barsContainer);
            ganttChart.appendChild(ganttRow);
        });

        ganttChart.style.width = totalWidth + 'px';
    }

    createTimeline(dayWidth) {
        let html = '<div class="gantt-row" style="background: #e8eef7; font-weight: 600; position: sticky; top: 0; z-index: 10;">';
        html += '<div class="gantt-bars" style="display: flex;">';

        let current = new Date(this.startDate);
        while (current <= this.endDate) {
            const dateStr = current.toLocaleDateString('ja-JP', { month: '2-digit', day: '2-digit' });
            html += `<div style="width: ${dayWidth}px; border-right: 1px solid #ddd; padding: 5px; text-align: center; font-size: 0.85em;">${dateStr}</div>`;
            current.setDate(current.getDate() + 1);
        }

        html += '</div></div>';
        return html;
    }

    renderReservationsList() {
        const list = document.getElementById('reservationsList');

        if (this.reservations.length === 0) {
            list.innerHTML = '<p style="text-align: center; color: #999;">予約がありません</p>';
            return;
        }

        const sortedReservations = [...this.reservations].sort((a, b) =>
            new Date(a.start) - new Date(b.start)
        );

        list.innerHTML = sortedReservations.map(res => {
            const vehicle = this.vehicles.find(v => v.id === res.vehicleId);
            const start = new Date(res.start);
            const end = new Date(res.end);
            const startStr = start.toLocaleString('ja-JP');
            const endStr = end.toLocaleString('ja-JP');

            return `
                <div class="reservation-item">
                    <div class="reservation-info">
                        <h3>${res.customerName}</h3>
                        <p><strong>車:</strong> ${vehicle ? vehicle.name : '削除された車'}</p>
                        <p><strong>期間:</strong> ${startStr} ～ ${endStr}</p>
                    </div>
                    <div class="reservation-actions">
                        <button onclick="manager.openEditModal(${res.id})">編集</button>
                    </div>
                </div>
            `;
        }).join('');
    }
}

// アプリケーション初期化
let manager;
document.addEventListener('DOMContentLoaded', () => {
    manager = new RentalCarManager();
});
