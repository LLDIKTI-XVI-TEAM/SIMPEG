const parseAuditLogs = (element) => {
    try {
        const logs = JSON.parse(element.dataset.auditLogs ?? '[]');

        return Array.isArray(logs) ? logs : [];
    } catch {
        return [];
    }
};

const registerAuditLogPage = () => {
    window.Alpine.data('auditLogPage', (element) => ({
        selectedLogId: null,
        showDrawer: false,
        logs: parseAuditLogs(element),

        getRingkasan(log) {
            if (!log) return '';
            if (log.event === 'LOGIN') return 'LOGIN: Login berhasil';
            if (log.event === 'LOGOUT') return 'LOGOUT: Logout dari sistem';
            if (log.event === 'SESSION_TIMEOUT') return 'SESSION_TIMEOUT: Sesi berakhir karena idle timeout';

            // Kosakata keputusan cuti lama dan baru diringkas sama karena baris audit lama memakai
            // APPROVE dan POSTPONE, sedangkan baris baru memakai istilah keputusan resmi.
            if (['APPROVE', 'POSTPONE', 'VERIFY', 'DECIDE', 'CHANGE_REQUESTED', 'DEFER', 'NOT_APPROVED'].includes(log.event)) {
                return `${log.event_label}: Mengubah status pengajuan cuti`;
            }

            if (log.event === 'CREATE') {
                return `CREATE: Membuat data ${log.modul} #${log.record_id}`;
            }

            if (log.event === 'UPDATE') {
                const fields = log.new_values ? Object.keys(log.new_values) : [];
                const fieldStr = fields.length > 0 ? fields.join(', ') : 'data';

                return `UPDATE: Mengubah ${fieldStr}`;
            }

            if (log.event === 'SOFT_DELETE') {
                return `SOFT_DELETE: Menonaktifkan data ${log.modul} #${log.record_id}`;
            }

            if (log.event === 'RESTORE') {
                return `RESTORE: Mengaktifkan kembali data ${log.modul} #${log.record_id}`;
            }

            return `${log.event_label}: pada ${log.modul} #${log.record_id}`;
        },

        get selectedLog() {
            return this.logs.find((log) => log.id === this.selectedLogId) ?? this.logs[0] ?? {};
        },

        getDiffFields(log) {
            if (!log) return [];

            const diffs = [];
            const oldValues = log.old_values || {};
            const newValues = log.new_values || {};
            const fields = new Set([...Object.keys(oldValues), ...Object.keys(newValues)]);

            for (const field of fields) {
                const oldValue = oldValues[field];
                const newValue = newValues[field];

                if (log.event === 'UPDATE' && JSON.stringify(oldValue) === JSON.stringify(newValue)) {
                    continue;
                }

                diffs.push({
                    field,
                    old: oldValue ?? '-',
                    new: newValue ?? '-',
                });
            }

            return diffs;
        },
    }));
};

if (window.Alpine) {
    registerAuditLogPage();
} else {
    document.addEventListener('alpine:init', registerAuditLogPage, { once: true });
}
