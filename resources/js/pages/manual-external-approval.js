export const createManualExternalApprovalEditor = (
    initialSteps,
    preview,
    lookupUrl,
    editorId = 'manual-approval',
    initialFieldErrors = {},
) => {
    let nextClientKey = 0;

    const withClientState = (step, fieldErrors = {}) => ({
        ...step,
        clientKey: `manual-approval-step-${++nextClientKey}`,
        fieldErrors: { ...fieldErrors },
        lookupQuery: step.approver_label ?? '',
        lookupResults: [],
        lookupLoading: false,
        lookupState: 'idle',
        lookupError: '',
        lookupOpen: false,
        lookupActiveIndex: -1,
        lookupTimer: null,
        lookupController: null,
        lookupGeneration: 0,
    });

    return {
        steps: Array.isArray(initialSteps)
            ? initialSteps.map((step, index) => withClientState(step, initialFieldErrors[index] ?? {}))
            : [],
        preview,
        lookupUrl,
        editorId,
        editorStatus: '',
        previewTrigger: null,

        blankStep() {
            return withClientState({
                step_type: 'verifier',
                approver_source: 'simpeg_employee',
                approver_employee_id: '',
                approver_name: '',
                approver_label: '',
                approver_position: '',
                approver_institution: '',
                acted_on: '',
                decision_note: '',
            });
        },

        findStep(clientKey) {
            return this.steps.find((step) => step.clientKey === clientKey);
        },

        cancelLookup(step) {
            step.lookupGeneration += 1;
            if (step.lookupTimer !== null) window.clearTimeout(step.lookupTimer);
            step.lookupController?.abort();
            step.lookupTimer = null;
            step.lookupController = null;
            step.lookupLoading = false;
            step.lookupResults = [];
            step.lookupState = 'idle';
            step.lookupError = '';
            step.lookupOpen = false;
            step.lookupActiveIndex = -1;
        },

        announce(message) {
            // Reset pada tick terpisah memastikan pesan identik tetap dianggap perubahan oleh pembaca layar.
            if (this.editorStatus === message) {
                this.editorStatus = '';

                if (typeof this.$nextTick === 'function') {
                    this.$nextTick(() => {
                        this.editorStatus = message;
                    });

                    return;
                }
            }

            this.editorStatus = message;
        },

        focusFirstError(editorRoot, fallback) {
            if (typeof this.$nextTick !== 'function') return;

            this.$nextTick(() => {
                const invalidField = [...(editorRoot?.querySelectorAll('[aria-invalid="true"]') ?? [])]
                    .find((field) => !field.disabled && field.getClientRects().length > 0);

                (invalidField ?? fallback)?.focus();
            });
        },

        focusStep(clientKey, control = 'type') {
            if (typeof this.$nextTick !== 'function') return;

            this.$nextTick(() => {
                document.querySelector(
                    `[data-manual-approval-editor="${this.editorId}"] [data-step-key="${clientKey}"] [data-step-focus="${control}"]`,
                )?.focus();
            });
        },

        focusAddButton() {
            if (typeof this.$nextTick !== 'function') return;

            this.$nextTick(() => {
                document.querySelector(`[data-manual-approval-add="${this.editorId}"]`)?.focus();
            });
        },

        addStep() {
            if (this.steps.length >= 10) return;

            const step = this.blankStep();
            this.steps.push(step);
            this.announce(`Tahap ${this.steps.length} ditambahkan.`);
            this.focusStep(step.clientKey);
        },

        removeStep(index) {
            const step = this.steps[index];
            if (!step) return;

            this.cancelLookup(step);
            this.steps.splice(index, 1);
            this.announce(`Tahap ${index + 1} dihapus.`);

            const focusTarget = this.steps[Math.min(index, this.steps.length - 1)];
            if (focusTarget) {
                this.focusStep(focusTarget.clientKey);
            } else {
                this.focusAddButton();
            }
        },

        openPreview() {
            this.previewTrigger = document.activeElement;
            this.$refs.chainPreviewDialog.showModal();
            this.$nextTick(() => this.$refs.chainPreviewDialog.querySelector('button')?.focus());
        },

        trapPreviewFocus(event) {
            const dialog = this.$refs.chainPreviewDialog;
            if (!dialog) return;

            // Modal harus mempertahankan fokus pada kontrol yang benar-benar dapat dioperasikan.
            const focusableElements = [...dialog.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )].filter((element) => !element.disabled
                && !element.hidden
                && element.getAttribute('aria-hidden') !== 'true'
                && element.getAttribute('tabindex') !== '-1'
                && element.getClientRects().length > 0);
            if (focusableElements.length === 0) return;

            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];

            if (event.shiftKey && document.activeElement === firstElement) {
                event.preventDefault();
                lastElement.focus();

                return;
            }

            if (!event.shiftKey && document.activeElement === lastElement) {
                event.preventDefault();
                firstElement.focus();
            }
        },

        restorePreviewFocus() {
            this.previewTrigger?.focus();
        },

        copyPreview() {
            if (this.steps.length > 0 && !window.confirm('Timpa draf rangkaian yang sedang diedit?')) return;

            this.steps.forEach((step) => this.cancelLookup(step));
            this.steps = this.preview.steps.map((step) => withClientState({
                step_type: step.step_type,
                approver_source: 'simpeg_employee',
                approver_employee_id: step.approver?.id ?? '',
                approver_name: '',
                approver_label: step.approver?.nama_lengkap ?? '',
                approver_position: '',
                approver_institution: '',
                acted_on: '',
                decision_note: '',
            }));
            this.$refs.chainPreviewDialog.close();
        },

        clearInternalApprover(clientKey) {
            const step = this.findStep(clientKey);
            if (!step) return;

            step.approver_employee_id = '';
            step.approver_label = '';
            step.lookupQuery = '';
            step.lookupResults = [];
            step.lookupState = 'idle';
            step.lookupError = '';
            step.lookupOpen = false;
            step.lookupActiveIndex = -1;
            this.clearFieldError(clientKey, 'approver_employee_id');
        },

        chooseApprover(clientKey, approver) {
            const step = this.findStep(clientKey);
            if (!step) return;

            step.approver_employee_id = approver.id;
            step.approver_label = `${approver.nama_lengkap} - NIP ${approver.nip}`;
            step.lookupQuery = step.approver_label;
            step.lookupResults = [];
            step.lookupState = 'idle';
            step.lookupError = '';
            step.lookupOpen = false;
            step.lookupActiveIndex = -1;
            this.clearFieldError(clientKey, 'approver_employee_id');
        },

        clearFieldError(clientKey, field) {
            const step = this.findStep(clientKey);
            if (!step?.fieldErrors?.[field]) return;

            delete step.fieldErrors[field];
        },

        changeApproverSource(clientKey) {
            const step = this.findStep(clientKey);
            if (!step) return;

            this.clearFieldError(clientKey, 'approver_source');
            if (step.approver_source === 'simpeg_employee') {
                step.approver_name = '';
                step.approver_position = '';
                step.approver_institution = '';
                ['approver_name', 'approver_position', 'approver_institution']
                    .forEach((field) => this.clearFieldError(clientKey, field));

                return;
            }

            this.clearInternalApprover(clientKey);
        },

        scheduleLookup(clientKey, query) {
            const step = this.findStep(clientKey);
            if (!step) return;

            step.lookupQuery = query;
            // Teks pencarian baru membatalkan identitas tersembunyi agar UUID lama
            // tidak tersimpan sebagai approver yang berbeda dari pilihan pengguna.
            step.approver_employee_id = '';
            step.approver_label = '';
            this.clearFieldError(clientKey, 'approver_employee_id');
            this.cancelLookup(step);
            if ([...query.trim()].length < 2) return;

            step.lookupState = 'scheduled';
            step.lookupOpen = true;
            step.lookupTimer = window.setTimeout(() => this.lookup(clientKey, query.trim()), 300);
        },

        retryLookup(clientKey) {
            const step = this.findStep(clientKey);
            const query = step?.lookupQuery?.trim() ?? '';
            if (!step || [...query].length < 2) return Promise.resolve();

            this.cancelLookup(step);

            return this.lookup(clientKey, query);
        },

        lookupStatusMessage(step) {
            if (step.lookupLoading) return 'Mencari approver.';
            if (['error', 'session_expired'].includes(step.lookupState)) return step.lookupError;
            if (step.lookupState === 'empty') return 'Approver tidak ditemukan.';
            if (step.lookupState === 'results') return `${step.lookupResults.length} approver ditemukan.`;

            return '';
        },

        moveLookupActive(step, direction) {
            if (!step.lookupResults.length) return;

            step.lookupOpen = true;
            step.lookupActiveIndex = (step.lookupActiveIndex + direction + step.lookupResults.length)
                % step.lookupResults.length;
        },

        chooseActiveApprover(step, event) {
            const approver = step.lookupResults[step.lookupActiveIndex];
            if (!step.lookupOpen || !approver) return;

            event.preventDefault();
            this.chooseApprover(step.clientKey, approver);
        },

        closeLookup(step) {
            step.lookupOpen = false;
            step.lookupActiveIndex = -1;
        },

        async lookup(clientKey, query) {
            const step = this.findStep(clientKey);
            if (!step) return;

            if (step.lookupTimer !== null) window.clearTimeout(step.lookupTimer);
            step.lookupTimer = null;
            step.lookupController?.abort();
            const controller = new AbortController();
            const generation = step.lookupGeneration + 1;
            step.lookupGeneration = generation;
            step.lookupController = controller;
            step.lookupLoading = true;
            step.lookupState = 'loading';
            step.lookupError = '';
            step.lookupOpen = true;
            step.lookupActiveIndex = -1;

            try {
                const response = await fetch(`${this.lookupUrl}?q=${encodeURIComponent(query)}`, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                });
                if ([401, 419].includes(response.status) || response.redirected) {
                    const sessionError = new Error('Sesi berakhir.');
                    sessionError.name = 'SessionExpiredError';

                    throw sessionError;
                }
                if (!response.ok) throw new Error('Pencarian approver gagal.');

                const payload = await response.json();
                if (this.findStep(clientKey) === step
                    && step.lookupController === controller
                    && step.lookupGeneration === generation) {
                    step.lookupResults = Array.isArray(payload.data) ? payload.data.slice(0, 15) : [];
                    step.lookupState = step.lookupResults.length > 0 ? 'results' : 'empty';
                    step.lookupActiveIndex = step.lookupResults.length > 0 ? 0 : -1;
                }
            } catch (error) {
                if (error.name !== 'AbortError'
                    && this.findStep(clientKey) === step
                    && step.lookupController === controller
                    && step.lookupGeneration === generation) {
                    step.lookupResults = [];
                    const sessionExpired = error.name === 'SessionExpiredError';
                    step.lookupState = sessionExpired ? 'session_expired' : 'error';
                    step.lookupError = sessionExpired
                        ? 'Sesi Anda telah berakhir. Muat ulang halaman untuk masuk kembali.'
                        : 'Pencarian approver gagal dimuat. Coba lagi.';
                    step.lookupActiveIndex = -1;
                }
            } finally {
                if (this.findStep(clientKey) === step
                    && step.lookupController === controller
                    && step.lookupGeneration === generation) {
                    step.lookupLoading = false;
                    step.lookupController = null;
                }
            }
        },
    };
};

export const resetLeaveAdministrationWorkspaceScroll = (
    browserWindow = window,
    browserDocument = document,
) => {
    if (typeof browserDocument.querySelector !== 'function'
        || !browserDocument.querySelector('[data-leave-usage-workspace="true"]')
        || browserWindow.location.hash) {
        return;
    }

    const resetScroll = () => {
        browserWindow.scrollTo({
            top: 0,
            left: 0,
            behavior: 'instant',
        });
    };
    const resetAfterNativeRestoration = () => {
        // Chrome memulihkan scroll setelah event load pada navigasi dengan path yang sama.
        // Tunda satu task agar reset aplikasi menjadi posisi akhir yang terlihat pengguna.
        browserWindow.setTimeout(resetScroll, 0);
    };

    if (browserDocument.readyState === 'complete') {
        resetAfterNativeRestoration();

        return;
    }

    browserWindow.addEventListener('load', resetAfterNativeRestoration, { once: true });
};

const registerManualExternalApprovalEditor = () => {
    window.Alpine.data('manualExternalApprovalEditor', createManualExternalApprovalEditor);
};

if (window.Alpine) {
    registerManualExternalApprovalEditor();
} else {
    document.addEventListener('alpine:init', registerManualExternalApprovalEditor, { once: true });
}

resetLeaveAdministrationWorkspaceScroll();
