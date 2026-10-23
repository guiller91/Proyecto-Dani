/* No user-controlled content is inserted as HTML. All API text uses textContent. */
(() => {
    'use strict';
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const money = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2 });
    const timezone = $('meta[name="app-timezone"]').content;
    const timestamp = new Intl.DateTimeFormat('es-ES', { dateStyle: 'short', timeStyle: 'short', timeZone: timezone });
    const csrfToken = $('meta[name="csrf-token"]').content;
    let notificationTimer;

    class ApiError extends Error {
        constructor(message, status = 0, fields = {}) { super(message); this.status = status; this.fields = fields; }
    }

    async function api(path, { method = 'GET', body, signal } = {}) {
        const controller = new AbortController();
        const abort = () => controller.abort();
        signal?.addEventListener('abort', abort, { once: true });
        if (signal?.aborted) controller.abort();
        const timeout = setTimeout(abort, 15000);
        try {
            const response = await fetch(path, {
                method, credentials: 'same-origin', signal: controller.signal,
                headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken } : {}) },
                ...(body ? { body: JSON.stringify(body) } : {}),
            });
            let result;
            try { result = await response.json(); }
            catch { throw new ApiError('La respuesta del servidor no es válida. Inténtalo de nuevo.', response.status); }
            if (!response.ok) throw new ApiError(result.error?.message || 'No se ha podido completar la operación.', response.status, result.error?.fields || {});
            return result;
        } catch (error) {
            if (signal?.aborted) throw error;
            if (error instanceof ApiError) throw error;
            throw new ApiError(method === 'GET'
                ? 'No hemos podido conectar con el servidor. Comprueba tu conexión y vuelve a intentarlo.'
                : 'No hemos podido confirmar el resultado. Actualiza las reservas antes de repetir la operación.');
        } finally {
            clearTimeout(timeout);
            signal?.removeEventListener('abort', abort);
        }
    }

    function notify(message, error = false) {
        const box = $('#notification');
        clearTimeout(notificationTimer);
        box.textContent = message;
        box.classList.toggle('is-error', error);
        box.setAttribute('role', error ? 'alert' : 'status');
        box.hidden = false;
        notificationTimer = setTimeout(() => { box.hidden = true; }, error ? 12000 : 7000);
    }

    function element(tag, className = '', text = '') {
        const node = document.createElement(tag);
        node.className = className;
        node.textContent = text;
        return node;
    }

    function badge(status) {
        const allowed = ['pendiente', 'confirmada', 'cancelada'];
        if (!allowed.includes(status)) return element('span', 'badge', 'Desconocido');
        const span = element('span', `badge badge-${status}`);
        const dot = element('span', 'badge-dot');
        dot.setAttribute('aria-hidden', 'true');
        span.append(dot, document.createTextNode(status[0].toUpperCase() + status.slice(1)));
        return span;
    }

    const formatDate = (date) => `${date.slice(8, 10)}/${date.slice(5, 7)}/${date.slice(2, 4)}`;
    const code = (id) => '#' + String(id).padStart(4, '0');

    function row(reservation) {
        const tr = element('tr');
        const cell = (className = '') => { const td = element('td', className); tr.append(td); return td; };
        const link = element('a', 'reservation-code', code(reservation.id));
        link.href = `/reservas/${reservation.id}`;
        cell().append(link);
        const guest = element('div', 'guest-cell');
        const avatar = element('span', `avatar avatar-${reservation.id % 4}`, [...reservation.nombre_huesped][0].toUpperCase());
        avatar.setAttribute('aria-hidden', 'true');
        const info = element('div');
        const name = element('a', 'guest-name', reservation.nombre_huesped);
        name.href = link.href;
        info.append(name, element('span', 'cell-secondary', reservation.email_huesped));
        guest.append(avatar, info);
        cell().append(guest);
        cell().append(element('span', 'accommodation-name', reservation.nombre_alojamiento));
        cell().append(element('span', 'date-range', `${formatDate(reservation.fecha_entrada)} → ${formatDate(reservation.fecha_salida)}`));
        cell().append(badge(reservation.estado));
        cell('amount').textContent = money.format(reservation.importe);
        const actions = cell('row-actions');
        const view = element('a', 'icon-button', '↗');
        view.href = link.href;
        view.title = 'Ver detalle';
        view.setAttribute('aria-label', `Ver reserva de ${reservation.nombre_huesped}`);
        actions.append(view);
        if (reservation.estado !== 'cancelada') {
            const cancel = element('button', 'icon-button cancel-button', '×');
            cancel.type = 'button';
            cancel.dataset.cancelId = reservation.id;
            cancel.dataset.version = reservation.version;
            cancel.dataset.guest = reservation.nombre_huesped;
            cancel.title = 'Cancelar reserva';
            cancel.setAttribute('aria-label', `Cancelar reserva de ${reservation.nombre_huesped}`);
            actions.append(cancel);
        }
        return tr;
    }

    let dialogBusy = false;
    $$('dialog').forEach(dialog => {
        dialog.addEventListener('cancel', event => { if (dialogBusy) event.preventDefault(); });
        $$('[data-close-dialog]', dialog).forEach(button => button.addEventListener('click', () => { if (!dialogBusy) dialog.close(); }));
    });

    const listPage = $('#reservations-page');
    const filters = $('#filters');
    let currentPage = Number($('#page-number')?.dataset.page || 1);
    let latestRequest = 0;
    let listAbort;
    let debounce;

    function renderList(result) {
        const tbody = $('#reservation-rows');
        const fragment = document.createDocumentFragment();
        result.data.forEach(item => fragment.append(row(item)));
        if (!result.data.length) {
            const tr = element('tr'); const td = element('td'); td.colSpan = 7;
            const empty = element('div', 'empty-state');
            empty.append(element('span', '', '⌕'), element('h3', '', 'No hay reservas que mostrar'), element('p', '', 'Prueba con otros filtros o crea tu primera reserva.'));
            td.append(empty); tr.append(td); fragment.append(tr);
        }
        tbody.replaceChildren(fragment);
        const { total, page, per_page: perPage, last_page: lastPage } = result.meta;
        currentPage = page;
        $('#result-count').textContent = total;
        $('#stat-total').textContent = result.summary.total;
        $('#stat-confirmed').textContent = result.summary.confirmadas;
        $('#stat-pending').textContent = result.summary.pendientes;
        $('#stat-amount').textContent = money.format(result.summary.importe_activo);
        $('#pagination-summary').textContent = total ? `Mostrando ${(page - 1) * perPage + 1}–${Math.min(page * perPage, total)} de ${total} reservas` : '0 reservas';
        $('#page-number').textContent = `${page} / ${lastPage}`;
        $('#previous-page').disabled = page <= 1;
        $('#next-page').disabled = page >= lastPage;
    }

    async function refreshList(page = 1, updateUrl = true) {
        if (!listPage) return true;
        clearTimeout(debounce);
        const params = new URLSearchParams(new FormData(filters));
        for (const [key, value] of [...params]) { if (!value.trim()) params.delete(key); }
        if (page > 1) params.set('page', page);
        listAbort?.abort();
        const controller = new AbortController(); listAbort = controller;
        const requestId = ++latestRequest;
        $('#loading-indicator').hidden = false;
        $('#list-error').hidden = true;
        listPage.setAttribute('aria-busy', 'true');
        try {
            const result = await api(`/api/reservas?${params}`, { signal: controller.signal });
            if (requestId !== latestRequest) return false;
            renderList(result);
            if (result.meta.page === 1) params.delete('page'); else params.set('page', result.meta.page);
            if (updateUrl) history.replaceState({}, '', params.size ? `/?${params}` : '/');
            return true;
        } catch (error) {
            if (!controller.signal.aborted && requestId === latestRequest) {
                const messages = Object.values(error.fields || {});
                $('#list-error').textContent = messages.length ? messages.join(' ') : error.message;
                $('#list-error').hidden = false;
            }
            return false;
        } finally {
            if (requestId === latestRequest) {
                $('#loading-indicator').hidden = true;
                listPage.setAttribute('aria-busy', 'false');
            }
        }
    }

    if (listPage) {
        filters.addEventListener('submit', event => { event.preventDefault(); refreshList(); });
        $('#search').addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(() => refreshList(), 300); });
        $$('select,input[type="date"]', filters).forEach(input => input.addEventListener('change', () => refreshList()));
        $('#reset-filters').addEventListener('click', () => { filters.reset(); $$('input', filters).forEach(input => { input.value = ''; }); $('#status').value = ''; refreshList(); });
        $('#previous-page').addEventListener('click', () => refreshList(currentPage - 1));
        $('#next-page').addEventListener('click', () => refreshList(currentPage + 1));
        window.addEventListener('popstate', () => {
            const query = new URLSearchParams(location.search);
            $$('input,select', filters).forEach(input => { input.value = query.get(input.name) || ''; });
            refreshList(Number(query.get('page')) || 1, false);
        });
    }

    const createForm = $('#create-form');
    if (createForm) {
        const dialog = $('#create-dialog');
        const button = $('#create-submit');
        const clearErrors = () => {
            $$('.field-error', createForm).forEach(node => { node.textContent = ''; });
            $$('[aria-invalid]', createForm).forEach(node => node.removeAttribute('aria-invalid'));
            $('#create-error').hidden = true;
        };
        const showErrors = (fields) => {
            for (const [name, message] of Object.entries(fields)) {
                const input = createForm.elements.namedItem(name);
                const target = document.getElementById(`error-${name}`);
                if (input && target) { target.textContent = message; input.setAttribute('aria-invalid', 'true'); }
                else { $('#create-error').textContent = message; $('#create-error').hidden = false; }
            }
            $('[aria-invalid]', createForm)?.focus();
        };
        $('#open-create').addEventListener('click', () => { clearErrors(); dialog.showModal(); });
        createForm.addEventListener('input', event => {
            const input = event.target;
            input.removeAttribute('aria-invalid');
            const target = document.getElementById(`error-${input.name}`);
            if (target) target.textContent = '';
        });
        createForm.addEventListener('submit', async event => {
            event.preventDefault();
            if (dialogBusy) return;
            clearErrors();
            const fields = {};
            const values = Object.fromEntries(new FormData(createForm));
            for (const input of $$('input,textarea', createForm)) {
                if (!input.checkValidity() || (input.required && !input.value.trim())) fields[input.name] = input.validationMessage || 'Este campo es obligatorio.';
            }
            if (values.fecha_salida && values.fecha_entrada && values.fecha_salida <= values.fecha_entrada) fields.fecha_salida = 'La salida debe ser posterior a la entrada.';
            if (Object.keys(fields).length) { showErrors(fields); return; }
            dialogBusy = true; button.disabled = true; button.textContent = 'Creando…';
            try {
                const result = await api('/api/reservas', { method: 'POST', body: values });
                dialog.close(); createForm.reset();
                $$('input', filters).forEach(input => { input.value = ''; }); $('#status').value = '';
                notify(`Reserva ${code(result.data.id)} creada correctamente.`);
                await refreshList();
            } catch (error) {
                if (Object.keys(error.fields || {}).length) showErrors(error.fields);
                else { $('#create-error').textContent = error.message; $('#create-error').hidden = false; }
            } finally {
                dialogBusy = false; button.disabled = false; button.textContent = 'Crear reserva →';
            }
        });
    }

    const detailPage = $('#detail-page');
    function renderDetail(reservation) {
        detailPage.dataset.version = reservation.version;
        $('#detail-status').replaceChildren(badge(reservation.estado));
        $('#detail-actions').hidden = reservation.estado === 'cancelada';
        $('#confirm-reservation').hidden = reservation.estado !== 'pendiente';
        $$('[data-cancel-id]', detailPage).forEach(button => { button.dataset.version = reservation.version; });
        const events = reservation.eventos.map(event => {
            const li = element('li', `timeline-${event.tipo}`);
            const dot = element('span', 'timeline-dot'); dot.setAttribute('aria-hidden', 'true');
            const label = { creada: 'Reserva creada', confirmada: 'Reserva confirmada', cancelada: 'Reserva cancelada' }[event.tipo];
            const time = element('time', '', timestamp.format(new Date(event.fecha_creacion))); time.dateTime = event.fecha_creacion;
            li.append(dot, element('strong', '', label), element('p', '', event.descripcion), time);
            return li;
        });
        $('#event-timeline').replaceChildren(...events);
    }

    async function changeStatus(id, version, status) {
        const result = await api(`/api/reservas/${id}/estado`, { method: 'PATCH', body: { estado: status, version: Number(version) } });
        if (detailPage) renderDetail(result.data);
        return result;
    }

    if (detailPage) {
        $('#confirm-reservation').addEventListener('click', async event => {
            const button = event.currentTarget;
            if (dialogBusy) return;
            dialogBusy = true; button.disabled = true; button.textContent = 'Confirmando…';
            try {
                await changeStatus(detailPage.dataset.id, detailPage.dataset.version, 'confirmada');
                notify('Reserva confirmada. Todo listo para su llegada.');
            } catch (error) { notify(error.message, true); }
            finally { dialogBusy = false; button.disabled = false; button.textContent = '✓ Confirmar reserva'; }
        });
    }

    const cancelDialog = $('#confirm-dialog');
    let selectedReservation;
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-cancel-id]');
        if (!button || dialogBusy) return;
        selectedReservation = { id: button.dataset.cancelId, version: button.dataset.version };
        $('#confirm-title').textContent = `¿Cancelar la reserva ${code(selectedReservation.id)}?`;
        $('#confirm-description').textContent = `La reserva de ${button.dataset.guest} quedará cancelada. El cambio se guardará en su historial y no se puede deshacer.`;
        $('#confirm-error').hidden = true;
        cancelDialog.showModal();
    });
    $('#confirm-form').addEventListener('submit', async event => {
        event.preventDefault();
        if (dialogBusy || !selectedReservation) return;
        dialogBusy = true;
        const button = $('#confirm-action'); button.disabled = true; button.textContent = 'Cancelando…';
        $('#confirm-error').hidden = true;
        try {
            await changeStatus(selectedReservation.id, selectedReservation.version, 'cancelada');
            cancelDialog.close();
            notify(`Reserva ${code(selectedReservation.id)} cancelada.`);
            await refreshList(currentPage);
        } catch (error) {
            $('#confirm-error').textContent = error.message; $('#confirm-error').hidden = false;
        } finally {
            dialogBusy = false; button.disabled = false; button.textContent = 'Sí, cancelar reserva';
        }
    });
})();
