/* No user-controlled content is inserted as HTML. All API text uses textContent. */
(() => {
    'use strict';
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const money = new Intl.NumberFormat('es-ES', {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 2,
    });
    const timezone = $('meta[name="app-timezone"]').content;
    const timestamp = new Intl.DateTimeFormat('es-ES', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: timezone,
    });
    const csrfToken = $('meta[name="csrf-token"]').content;
    let notificationTimer;
    const today = $('meta[name="app-today"]').content;
    const selected = new Map();
    let editingReservation = null;
    let previewReservation = null;
    let previewAbort;

    class ApiError extends Error {
        constructor(message, status = 0, fields = {}) {
            super(message);
            this.status = status;
            this.fields = fields;
        }
    }

    async function api(path, { method = 'GET', body, signal } = {}) {
        const controller = new AbortController();
        const abort = () => controller.abort();
        signal?.addEventListener('abort', abort, { once: true });
        if (signal?.aborted) controller.abort();
        const timeout = setTimeout(abort, 15000);
        try {
            const response = await fetch(path, {
                method,
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken } : {}),
                },
                ...(body ? { body: JSON.stringify(body) } : {}),
            });
            let result;
            try {
                result = await response.json();
            } catch {
                throw new ApiError(
                    'La respuesta del servidor no es válida. Inténtalo de nuevo.',
                    response.status
                );
            }
            if (!response.ok)
                throw new ApiError(
                    result.error?.message || 'No se ha podido completar la operación.',
                    response.status,
                    result.error?.fields || {}
                );
            return result;
        } catch (error) {
            if (signal?.aborted) throw error;
            if (error instanceof ApiError) throw error;
            throw new ApiError(
                method === 'GET'
                    ? 'No hemos podido conectar con el servidor. Comprueba tu conexión y vuelve a intentarlo.'
                    : 'No hemos podido confirmar el resultado. Actualiza las reservas antes de repetir la operación.'
            );
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
        notificationTimer = setTimeout(
            () => {
                box.hidden = true;
            },
            error ? 12000 : 7000
        );
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
        const cell = (className = '') => {
            const td = element('td', className);
            tr.append(td);
            return td;
        };
        const checkbox = element('input', 'row-select');
        checkbox.type = 'checkbox';
        checkbox.dataset.id = reservation.id;
        checkbox.dataset.version = reservation.version;
        checkbox.disabled = reservation.estado === 'cancelada';
        checkbox.setAttribute('aria-label', `Seleccionar reserva de ${reservation.nombre_huesped}`);
        cell('checkbox-cell').append(checkbox);
        const link = element('a', 'reservation-code', code(reservation.id));
        link.href = `/reservas/${reservation.id}`;
        cell().append(link);
        const guest = element('div', 'guest-cell');
        const info = element('div');
        const name = element('a', 'guest-name', reservation.nombre_huesped);
        name.href = link.href;
        info.append(name, element('span', 'cell-secondary', reservation.email_huesped));
        guest.append(info);
        cell().append(guest);
        cell().append(element('span', 'accommodation-name', reservation.nombre_alojamiento));
        cell().append(
            element(
                'span',
                'date-range',
                `${formatDate(reservation.fecha_entrada)} → ${formatDate(reservation.fecha_salida)}`
            )
        );
        cell().append(badge(reservation.estado));
        cell('amount').textContent = money.format(reservation.importe);
        const actions = cell('row-actions');
        const view = element('a', 'icon-button', 'Abrir');
        view.dataset.previewId = reservation.id;
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
    $$('dialog').forEach((dialog) => {
        dialog.addEventListener('cancel', (event) => {
            if (dialogBusy) event.preventDefault();
        });
        $$('[data-close-dialog]', dialog).forEach((button) =>
            button.addEventListener('click', () => {
                if (!dialogBusy) dialog.close();
            })
        );
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
        result.data.forEach((item) => fragment.append(row(item)));
        if (!result.data.length) {
            const tr = element('tr');
            const td = element('td');
            td.colSpan = 8;
            const empty = element('div', 'empty-state');
            empty.append(
                element('h3', '', 'No hay reservas que mostrar'),
                element('p', '', 'Prueba con otros filtros o crea tu primera reserva.')
            );
            td.append(empty);
            tr.append(td);
            fragment.append(tr);
        }
        tbody.replaceChildren(fragment);
        const { total, page, per_page: perPage, last_page: lastPage } = result.meta;
        currentPage = page;
        $('#result-count').textContent = total;
        $('#stat-total').textContent = result.summary.total;
        $('#stat-confirmed').textContent = result.summary.confirmadas;
        $('#stat-pending').textContent = result.summary.pendientes;
        $('#stat-amount').textContent = money.format(result.summary.importe_activo);
        $('#pagination-summary').textContent = total
            ? `Mostrando ${(page - 1) * perPage + 1}–${Math.min(page * perPage, total)} de ${total} reservas`
            : '0 reservas';
        $('#page-number').textContent = `${page} / ${lastPage}`;
        $('#previous-page').disabled = page <= 1;
        $('#next-page').disabled = page >= lastPage;
        selected.clear();
        syncSelection();
        syncFilters();
    }

    async function refreshList(page = 1, updateUrl = true) {
        if (!listPage) return true;
        clearTimeout(debounce);
        const params = new URLSearchParams(new FormData(filters));
        for (const [key, value] of [...params]) {
            if (!value.trim()) params.delete(key);
        }
        if (params.get('per_page') === '10') params.delete('per_page');
        if (params.get('orden') === 'recientes') params.delete('orden');
        if (page > 1) params.set('page', page);
        listAbort?.abort();
        const controller = new AbortController();
        listAbort = controller;
        const requestId = ++latestRequest;
        $('#loading-indicator').hidden = false;
        $('#list-error').hidden = true;
        listPage.setAttribute('aria-busy', 'true');
        try {
            const result = await api(`/api/reservas?${params}`, { signal: controller.signal });
            if (requestId !== latestRequest) return false;
            renderList(result);
            if (result.meta.page === 1) params.delete('page');
            else params.set('page', result.meta.page);
            if (!$('#agenda-view').hidden) {
                params.set('vista', 'agenda');
                params.set('inicio_agenda', $('#agenda-start').value);
            }
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
        filters.addEventListener('submit', (event) => {
            event.preventDefault();
            refreshList();
        });
        $('#search').addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => refreshList(), 300);
        });
        $$('select,input[type="date"]', filters).forEach((input) =>
            input.addEventListener('change', () => refreshList())
        );
        $('#reset-filters').addEventListener('click', () => {
            filters.reset();
            $$('input', filters).forEach((input) => {
                input.value = '';
            });
            $('#status').value = '';
            $('#property-filter').value = '';
            $('#sort-order').value = 'recientes';
            $('#page-size').value = '10';
            refreshList();
        });
        $('#previous-page').addEventListener('click', () => refreshList(currentPage - 1));
        $('#next-page').addEventListener('click', () => refreshList(currentPage + 1));
        $('#sort-order').addEventListener('change', () => refreshList());
        $('#page-size').addEventListener('change', () => refreshList());
        $$('[data-status]').forEach((button) =>
            button.addEventListener('click', () => {
                $('#status').value = button.dataset.status;
                refreshList();
            })
        );
        window.addEventListener('popstate', () => {
            const query = new URLSearchParams(location.search);
            $$('input,select', filters).forEach((input) => {
                input.value = query.get(input.name) || '';
            });
            $('#sort-order').value = query.get('orden') || 'recientes';
            $('#page-size').value = query.get('per_page') || '10';
            refreshList(Number(query.get('page')) || 1, false);
        });
    }

    const createForm = $('#create-form');
    if (createForm) {
        const dialog = $('#create-dialog');
        const button = $('#create-submit');
        const clearErrors = () => {
            $$('.field-error', createForm).forEach((node) => {
                node.textContent = '';
            });
            $$('[aria-invalid]', createForm).forEach((node) => node.removeAttribute('aria-invalid'));
            $('#create-error').hidden = true;
        };
        const showErrors = (fields) => {
            for (const [name, message] of Object.entries(fields)) {
                const input = createForm.elements.namedItem(name);
                const target = document.getElementById(`error-${name}`);
                if (input && target) {
                    target.textContent = message;
                    input.setAttribute('aria-invalid', 'true');
                } else {
                    $('#create-error').textContent = message;
                    $('#create-error').hidden = false;
                }
            }
            $('[aria-invalid]', createForm)?.focus();
        };
        $('#open-create')?.addEventListener('click', () => {
            editingReservation = null;
            createForm.reset();
            $('#create-title').textContent = 'Nueva reserva';
            $('#form-description').textContent = 'Se guardará con estado pendiente de confirmación.';
            button.textContent = 'Crear reserva';
            clearErrors();
            dialog.showModal();
        });
        document.addEventListener('click', async (event) => {
            const trigger = event.target.closest('[data-edit-id]');
            if (!trigger || dialogBusy) return;
            trigger.disabled = true;
            try {
                const { data } = await api(`/api/reservas/${trigger.dataset.editId}`);
                if (data.estado === 'cancelada')
                    throw new ApiError('Las reservas canceladas no se pueden editar.');
                editingReservation = data;
                clearErrors();
                for (const input of $$('input,textarea', createForm)) input.value = data[input.name] ?? '';
                $('#create-title').textContent = `Editar reserva ${code(data.id)}`;
                $('#form-description').textContent =
                    'Los cambios se guardarán en el historial de la reserva.';
                button.textContent = 'Guardar cambios';
                dialog.showModal();
            } catch (error) {
                notify(error.message, true);
            } finally {
                trigger.disabled = false;
            }
        });
        createForm.addEventListener('input', (event) => {
            const input = event.target;
            input.removeAttribute('aria-invalid');
            const target = document.getElementById(`error-${input.name}`);
            if (target) target.textContent = '';
        });
        createForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (dialogBusy) return;
            clearErrors();
            const fields = {};
            const values = Object.fromEntries(new FormData(createForm));
            for (const input of $$('input,textarea', createForm)) {
                if (!input.checkValidity() || (input.required && !input.value.trim()))
                    fields[input.name] = input.validationMessage || 'Este campo es obligatorio.';
            }
            if (values.fecha_salida && values.fecha_entrada && values.fecha_salida <= values.fecha_entrada)
                fields.fecha_salida = 'La salida debe ser posterior a la entrada.';
            if (Object.keys(fields).length) {
                showErrors(fields);
                return;
            }
            dialogBusy = true;
            button.disabled = true;
            button.textContent = editingReservation ? 'Guardando…' : 'Creando…';
            try {
                const editing = editingReservation;
                const result = await api(editing ? `/api/reservas/${editing.id}` : '/api/reservas', {
                    method: editing ? 'PATCH' : 'POST',
                    body: editing ? { ...values, version: editing.version } : values,
                });
                dialog.close();
                createForm.reset();
                if (!editing && filters) {
                    filters.reset();
                    $$('input', filters).forEach((input) => {
                        input.value = '';
                    });
                    $('#status').value = '';
                    $('#property-filter').value = '';
                    $('#sort-order').value = 'recientes';
                    $('#page-size').value = '10';
                }
                renderDetail(result.data);
                if (previewReservation?.id === result.data.id) renderPreview(result.data);
                notify(
                    `Reserva ${code(result.data.id)} ${editing ? 'actualizada' : 'creada'} correctamente.`
                );
                if (listPage) await refreshList(editing ? currentPage : 1);
                refreshOverview();
                if ($('#agenda-view') && !$('#agenda-view').hidden) loadAgenda();
            } catch (error) {
                if (Object.keys(error.fields || {}).length) showErrors(error.fields);
                else {
                    $('#create-error').textContent = error.message;
                    $('#create-error').hidden = false;
                }
            } finally {
                dialogBusy = false;
                button.disabled = false;
                button.textContent = editingReservation ? 'Guardar cambios' : 'Crear reserva';
            }
        });
    }

    const detailPage = $('#detail-page');
    function renderDetail(reservation) {
        if (!detailPage) return;
        detailPage.dataset.version = reservation.version;
        $('.stay-name', detailPage).textContent = reservation.nombre_alojamiento;
        const dates = $$('.stay-dates strong', detailPage);
        dates[0].textContent = reservation.fecha_entrada.split('-').reverse().join('/');
        dates[1].textContent = reservation.fecha_salida.split('-').reverse().join('/');
        $('.night-count', detailPage).textContent =
            `${nights(reservation)} ${nights(reservation) === 1 ? 'noche' : 'noches'}`;
        $('.detail-total strong', detailPage).textContent = money.format(reservation.importe);
        const guest = $$('.guest-details dd', detailPage);
        guest[0].textContent = reservation.nombre_huesped;
        const email = element('a', '', reservation.email_huesped);
        email.href = `mailto:${reservation.email_huesped}`;
        guest[1].replaceChildren(email);
        $('.notes', detailPage).textContent = reservation.notas || 'No hay notas para esta reserva.';
        $('.notes', detailPage).classList.toggle('muted', !reservation.notas);
        $$('[data-edit-id]', detailPage).forEach((button) => {
            button.hidden = reservation.estado === 'cancelada';
        });
        $('#detail-status').replaceChildren(badge(reservation.estado));
        $('#detail-actions').hidden = reservation.estado === 'cancelada';
        $('#confirm-reservation').hidden = reservation.estado !== 'pendiente';
        $$('[data-cancel-id]', detailPage).forEach((button) => {
            button.dataset.version = reservation.version;
            button.dataset.guest = reservation.nombre_huesped;
        });
        const events = reservation.eventos.map((event) => {
            const li = element('li', `timeline-${event.tipo}`);
            const dot = element('span', 'timeline-dot');
            dot.setAttribute('aria-hidden', 'true');
            const label = {
                creada: 'Reserva creada',
                confirmada: 'Reserva confirmada',
                cancelada: 'Reserva cancelada',
                actualizada: 'Datos actualizados',
            }[event.tipo];
            const time = element('time', '', timestamp.format(new Date(event.fecha_creacion)));
            time.dateTime = event.fecha_creacion;
            li.append(dot, element('strong', '', label), element('p', '', event.descripcion), time);
            return li;
        });
        $('#event-timeline').replaceChildren(...events);
    }

    async function changeStatus(id, version, status) {
        const result = await api(`/api/reservas/${id}/estado`, {
            method: 'PATCH',
            body: { estado: status, version: Number(version) },
        });
        renderDetail(result.data);
        if (previewReservation?.id === result.data.id) renderPreview(result.data);
        refreshOverview();
        if ($('#agenda-view') && !$('#agenda-view').hidden) loadAgenda();
        return result;
    }

    if (detailPage) {
        $('#confirm-reservation').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            if (dialogBusy) return;
            dialogBusy = true;
            button.disabled = true;
            button.textContent = 'Confirmando…';
            try {
                await changeStatus(detailPage.dataset.id, detailPage.dataset.version, 'confirmada');
                notify('Reserva confirmada correctamente.');
            } catch (error) {
                notify(error.message, true);
            } finally {
                dialogBusy = false;
                button.disabled = false;
                button.textContent = '✓ Confirmar reserva';
            }
        });
    }

    const cancelDialog = $('#confirm-dialog');
    let selectedReservation;
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-cancel-id]');
        if (!button || dialogBusy) return;
        selectedReservation = { id: button.dataset.cancelId, version: button.dataset.version };
        $('#confirm-title').textContent = `¿Cancelar la reserva ${code(selectedReservation.id)}?`;
        $('#confirm-description').textContent =
            `La reserva de ${button.dataset.guest} quedará cancelada. El cambio se guardará en su historial y no se puede deshacer.`;
        $('#confirm-error').hidden = true;
        cancelDialog.showModal();
    });
    $('#confirm-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (dialogBusy || !selectedReservation) return;
        dialogBusy = true;
        const button = $('#confirm-action');
        button.disabled = true;
        button.textContent = 'Cancelando…';
        $('#confirm-error').hidden = true;
        try {
            await changeStatus(selectedReservation.id, selectedReservation.version, 'cancelada');
            cancelDialog.close();
            notify(`Reserva ${code(selectedReservation.id)} cancelada.`);
            await refreshList(currentPage);
        } catch (error) {
            $('#confirm-error').textContent = error.message;
            $('#confirm-error').hidden = false;
        } finally {
            dialogBusy = false;
            button.disabled = false;
            button.textContent = 'Sí, cancelar reserva';
        }
    });
    function nights(reservation) {
        return Math.round(
            (Date.parse(reservation.fecha_salida) - Date.parse(reservation.fecha_entrada)) / 86400000
        );
    }

    async function refreshOverview() {
        if (!listPage) return;
        try {
            const { data, accommodations } = await api('/api/overview');
            const property = $('#property-filter');
            const current = property.value;
            const options = [element('option', '', 'Todos los alojamientos')];
            options[0].value = '';
            for (const name of accommodations) {
                const option = element('option', '', name);
                option.value = name;
                options.push(option);
            }
            if (current && !accommodations.includes(current)) {
                const option = element('option', '', `${current} (sin reservas)`);
                option.value = current;
                options.push(option);
            }
            property.replaceChildren(...options);
            property.value = current;
            for (const [id, key] of Object.entries({
                'overview-arrivals': 'llegadas',
                'overview-departures': 'salidas',
                'overview-pending': 'pendientes',
            })) {
                document.getElementById(id).textContent = data[key];
            }
            $('#overview-amount').textContent = money.format(data.importe_activo);
            $('#overview-inhouse').textContent =
                `${data.alojados} ${data.alojados === 1 ? 'estancia confirmada en curso' : 'estancias confirmadas en curso'}`;
        } catch {
            notify(
                'Los cambios se guardaron, pero el resumen no se ha podido actualizar. Recarga la página.',
                true
            );
        }
    }

    function syncFilters() {
        if (!filters) return;
        const values = Object.fromEntries(new FormData(filters));
        $$('[data-status]').forEach((button) =>
            button.setAttribute('aria-pressed', String(button.dataset.status === values.estado))
        );
        const names = {
            q: 'Huésped',
            alojamiento: 'Alojamiento',
            entrada: 'Llegada',
            salida: 'Salida',
            desde: 'Desde',
            hasta: 'Hasta',
        };
        const chips = [];
        for (const [key, label] of Object.entries(names)) {
            if (!values[key]) continue;
            const chip = element('button', 'filter-chip', `${label}: ${values[key]} ×`);
            chip.type = 'button';
            chip.dataset.clearFilter = key;
            chip.setAttribute('aria-label', `Quitar filtro ${label.toLowerCase()}`);
            chips.push(chip);
        }
        $('#active-filters').replaceChildren(...chips);
        $('#active-filters').hidden = !chips.length;
    }

    function syncSelection() {
        if (!listPage) return;
        const checkboxes = $$('.row-select:not(:disabled)');
        $('#select-all').disabled = !checkboxes.length;
        $('#select-all').checked = !!checkboxes.length && checkboxes.every((input) => input.checked);
        $('#select-all').indeterminate = selected.size > 0 && !$('#select-all').checked;
        $('#selected-count').textContent = selected.size;
        $('#bulk-toolbar').hidden = selected.size === 0;
    }

    let bulkTarget = 'confirmada';
    if (listPage) {
        syncSelection();
        syncFilters();
        document.addEventListener('change', (event) => {
            const input = event.target;
            if (!input.matches('.row-select')) return;
            if (input.checked && selected.size >= 50) {
                input.checked = false;
                notify('Puedes seleccionar hasta 50 reservas por operación.', true);
                return;
            }
            if (input.checked) selected.set(Number(input.dataset.id), Number(input.dataset.version));
            else selected.delete(Number(input.dataset.id));
            syncSelection();
        });
        $('#select-all').addEventListener('change', (event) => {
            selected.clear();
            $$('.row-select:not(:disabled)').forEach((input, index) => {
                input.checked = event.target.checked && index < 50;
                if (input.checked) selected.set(Number(input.dataset.id), Number(input.dataset.version));
            });
            syncSelection();
        });
        $('#clear-selection').addEventListener('click', () => {
            selected.clear();
            $$('.row-select').forEach((input) => {
                input.checked = false;
            });
            syncSelection();
        });
        $('#active-filters').addEventListener('click', (event) => {
            const button = event.target.closest('[data-clear-filter]');
            if (!button) return;
            filters.elements.namedItem(button.dataset.clearFilter).value = '';
            refreshList();
        });
        for (const [id, status] of [
            ['bulk-confirm', 'confirmada'],
            ['bulk-cancel', 'cancelada'],
        ]) {
            document.getElementById(id).addEventListener('click', () => {
                if (!selected.size || dialogBusy) return;
                bulkTarget = status;
                $('#bulk-title').textContent =
                    `${status === 'confirmada' ? 'Confirmar' : 'Cancelar'} ${selected.size} ${selected.size === 1 ? 'reserva' : 'reservas'}`;
                $('#bulk-description').textContent =
                    status === 'cancelada'
                        ? 'Se cancelarán las reservas seleccionadas. Esta acción no se puede deshacer. Si alguna ha cambiado, no se aplicará ningún cambio.'
                        : 'Se confirmarán las reservas seleccionadas. Cada cambio quedará registrado. Si alguna ha cambiado, no se aplicará ningún cambio.';
                $('#bulk-error').hidden = true;
                $('#bulk-submit').textContent =
                    status === 'confirmada' ? 'Confirmar selección' : 'Cancelar selección';
                $('#bulk-dialog').showModal();
            });
        }
        $('#bulk-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            if (dialogBusy || !selected.size) return;
            dialogBusy = true;
            $('#bulk-submit').disabled = true;
            try {
                const result = await api('/api/reservas/lote', {
                    method: 'POST',
                    body: {
                        estado: bulkTarget,
                        reservas: [...selected].map(([id, version]) => ({ id, version })),
                    },
                });
                $('#bulk-dialog').close();
                notify(
                    `${result.updated} ${result.updated === 1 ? 'reserva procesada' : 'reservas procesadas'} correctamente.`
                );
                await refreshList(currentPage);
                refreshOverview();
            } catch (error) {
                $('#bulk-error').textContent = error.message;
                $('#bulk-error').hidden = false;
            } finally {
                dialogBusy = false;
                $('#bulk-submit').disabled = false;
            }
        });

        $('#export-reservations').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const children = [...button.childNodes].map((node) => node.cloneNode(true));
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 30000);
            button.disabled = true;
            button.textContent = 'Preparando…';
            try {
                const response = await fetch(
                    `/api/reservas/exportar?${new URLSearchParams(new FormData(filters))}`,
                    { credentials: 'same-origin', signal: controller.signal }
                );
                if (!response.ok) {
                    let message = 'No se ha podido preparar la exportación.';
                    try {
                        message = (await response.json()).error.message;
                    } catch {
                        /* Keep the useful fallback. */
                    }
                    throw new ApiError(message);
                }
                const url = URL.createObjectURL(await response.blob());
                const link = element('a');
                link.href = url;
                link.download = `reservas-${today}.csv`;
                document.body.append(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
                notify('Exportación preparada con todas las reservas de los filtros activos.');
            } catch (error) {
                notify(
                    error instanceof ApiError
                        ? error.message
                        : 'No se ha podido descargar el CSV. Comprueba tu conexión.',
                    true
                );
            } finally {
                clearTimeout(timeout);
                button.disabled = false;
                button.replaceChildren(...children);
            }
        });
    }

    function timeline(reservation) {
        const list = element('ol', 'timeline');
        for (const event of reservation.eventos) {
            const li = element('li', `timeline-${event.tipo}`);
            const dot = element('span', 'timeline-dot');
            dot.setAttribute('aria-hidden', 'true');
            const label = {
                creada: 'Reserva creada',
                confirmada: 'Reserva confirmada',
                cancelada: 'Reserva cancelada',
                actualizada: 'Datos actualizados',
            }[event.tipo];
            const time = element('time', '', timestamp.format(new Date(event.fecha_creacion)));
            time.dateTime = event.fecha_creacion;
            li.append(dot, element('strong', '', label), element('p', '', event.descripcion), time);
            list.append(li);
        }
        return list;
    }

    function renderPreview(reservation) {
        previewReservation = reservation;
        $('#preview-title').textContent = `Reserva ${code(reservation.id)}`;
        const content = $('#preview-content');
        const header = element('div', 'preview-guest');
        header.append(badge(reservation.estado), element('h3', '', reservation.nombre_huesped));
        const email = element('a', 'preview-email', reservation.email_huesped);
        email.href = `mailto:${reservation.email_huesped}`;
        header.append(email);
        const stay = element('section', 'preview-section');
        stay.append(
            element('h3', '', 'Estancia'),
            element('p', 'preview-property', reservation.nombre_alojamiento)
        );
        const dates = element('div', 'preview-dates');
        for (const [title, date] of [
            ['Entrada', reservation.fecha_entrada],
            ['Salida', reservation.fecha_salida],
        ]) {
            const col = element('div');
            col.append(
                element('span', '', title),
                element('strong', '', date.split('-').reverse().join('/'))
            );
            dates.append(col);
        }
        stay.append(
            dates,
            element(
                'p',
                'preview-nights',
                `${nights(reservation)} ${nights(reservation) === 1 ? 'noche' : 'noches'}`
            )
        );
        const amount = element('div', 'preview-total');
        amount.append(
            element('span', '', 'Importe total'),
            element('strong', '', money.format(reservation.importe))
        );
        stay.append(amount);
        const notes = element('section', 'preview-section');
        notes.append(
            element('h3', '', 'Notas'),
            element('p', 'notes', reservation.notas || 'Sin notas para esta reserva.')
        );
        const actions = element('div', 'preview-actions');
        if (reservation.estado !== 'cancelada') {
            const edit = element('button', 'button button-secondary', 'Editar reserva');
            edit.type = 'button';
            edit.dataset.editId = reservation.id;
            actions.append(edit);
            if (reservation.estado === 'pendiente') {
                const confirm = element('button', 'button button-primary', 'Confirmar reserva');
                confirm.type = 'button';
                confirm.addEventListener('click', async () => {
                    if (dialogBusy) return;
                    dialogBusy = true;
                    confirm.disabled = true;
                    try {
                        await changeStatus(reservation.id, reservation.version, 'confirmada');
                        await refreshList(currentPage);
                        notify('Reserva confirmada correctamente.');
                    } catch (error) {
                        notify(error.message, true);
                    } finally {
                        dialogBusy = false;
                        confirm.disabled = false;
                    }
                });
                actions.append(confirm);
            }
            const cancel = element('button', 'button button-cancel', 'Cancelar reserva');
            cancel.type = 'button';
            cancel.dataset.cancelId = reservation.id;
            cancel.dataset.version = reservation.version;
            cancel.dataset.guest = reservation.nombre_huesped;
            actions.append(cancel);
        }
        const history = element('section', 'preview-section');
        history.append(element('h3', '', 'Actividad de la reserva'), timeline(reservation));
        const full = element('a', 'full-detail-link', 'Abrir ficha completa →');
        full.href = `/reservas/${reservation.id}`;
        content.replaceChildren(header, stay, actions, notes, history, full);
    }

    if ($('#preview-dialog')) {
        const dialog = $('#preview-dialog');
        dialog.addEventListener('close', () => {
            previewAbort?.abort();
        });
        document.addEventListener('click', async (event) => {
            const link = event.target.closest('[data-preview-id]');
            if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            previewAbort?.abort();
            const controller = new AbortController();
            previewAbort = controller;
            previewReservation = null;
            $('#preview-title').textContent = `Reserva ${code(link.dataset.previewId)}`;
            $('#preview-content').replaceChildren(element('p', 'drawer-loading', 'Cargando reserva…'));
            dialog.showModal();
            try {
                const { data } = await api(`/api/reservas/${link.dataset.previewId}`, {
                    signal: controller.signal,
                });
                if (!controller.signal.aborted && dialog.open) renderPreview(data);
            } catch (error) {
                if (!controller.signal.aborted) {
                    const message = element('p', 'form-error', error.message);
                    message.setAttribute('role', 'alert');
                    $('#preview-content').replaceChildren(message);
                }
            }
        });
    }

    let agendaAbort;
    function addDays(date, days) {
        const value = new Date(`${date}T00:00:00Z`);
        value.setUTCDate(value.getUTCDate() + days);
        return value.toISOString().slice(0, 10);
    }
    async function loadAgenda() {
        const from = $('#agenda-start').value;
        if (!from || from < '1000-01-01' || from > '9999-12-25') {
            $('#agenda-error').textContent = 'Selecciona una fecha válida entre 1000-01-01 y 9999-12-25.';
            $('#agenda-error').hidden = false;
            return;
        }
        agendaAbort?.abort();
        const controller = new AbortController();
        agendaAbort = controller;
        $('#agenda-content').setAttribute('aria-busy', 'true');
        $('#agenda-error').hidden = true;
        try {
            const result = await api(`/api/agenda?desde=${from}&hasta=${addDays(from, 6)}`, {
                signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            const fragment = document.createDocumentFragment();
            const dayFormat = new Intl.DateTimeFormat('es-ES', {
                weekday: 'long',
                day: 'numeric',
                month: 'short',
                timeZone: 'UTC',
            });
            for (let index = 0; index < 7; index++) {
                const date = addDays(from, index);
                const day = element('section', 'agenda-day');
                const records = result.data.filter((item) => item.dia === date);
                const heading = element('div', 'agenda-day-heading');
                heading.append(
                    element('h4', '', dayFormat.format(new Date(`${date}T00:00:00Z`))),
                    element(
                        'span',
                        '',
                        `${records.length} ${records.length === 1 ? 'movimiento' : 'movimientos'}${result.meta.truncated ? (records.length === 1 ? ' mostrado' : ' mostrados') : ''}`
                    )
                );
                day.append(heading);
                if (!records.length)
                    day.append(element('p', 'agenda-empty', 'Sin llegadas ni salidas registradas.'));
                for (const reservation of records) {
                    const card = element('article', 'agenda-reservation');
                    const type = element(
                        'span',
                        `movement movement-${reservation.movimiento}`,
                        reservation.movimiento === 'entrada' ? '↘ Entrada' : '↗ Salida'
                    );
                    const guest = element('div', 'agenda-guest');
                    const name = element('a', 'guest-name', reservation.nombre_huesped);
                    name.href = `/reservas/${reservation.id}`;
                    name.dataset.previewId = reservation.id;
                    guest.append(name, element('span', 'cell-secondary', reservation.nombre_alojamiento));
                    const reference = element('span', 'reservation-code', code(reservation.id));
                    card.append(type, guest, reference, badge(reservation.estado));
                    day.append(card);
                }
                fragment.append(day);
            }
            $('#agenda-content').replaceChildren(fragment);
            const url = new URL(location.href);
            if (!$('#agenda-view').hidden) {
                url.searchParams.set('vista', 'agenda');
                url.searchParams.set('inicio_agenda', from);
                history.replaceState({}, '', url.pathname + url.search);
            }
            if (result.meta.truncated) {
                $('#agenda-error').textContent =
                    'Se muestran los primeros 500 movimientos. Acota las fechas consultando cada día mediante los filtros de reservas.';
                $('#agenda-error').hidden = false;
            }
        } catch (error) {
            if (!controller.signal.aborted) {
                $('#agenda-error').textContent = error.message;
                $('#agenda-error').hidden = false;
            }
        } finally {
            if (!controller.signal.aborted) $('#agenda-content').setAttribute('aria-busy', 'false');
        }
    }

    if (listPage) {
        function switchView(view, changeUrl = true) {
            const agenda = view === 'agenda';
            $('#list-view').hidden = agenda;
            $('#agenda-view').hidden = !agenda;
            $('#export-reservations').hidden = agenda;
            $('#panel-title').textContent = agenda ? 'Agenda de estancias' : 'Registro de reservas';
            $('#result-count').hidden = agenda;
            $$('button[data-view]').forEach((button) =>
                button.setAttribute('aria-pressed', String(button.dataset.view === view))
            );
            $$('[data-nav-view]').forEach((link) => {
                link.classList.toggle('active', link.dataset.navView === view);
                if (link.dataset.navView === view) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            });
            if (changeUrl) {
                const url = new URL(location.href);
                if (agenda) url.searchParams.set('vista', 'agenda');
                else {
                    url.searchParams.delete('vista');
                    url.searchParams.delete('inicio_agenda');
                }
                history.replaceState({}, '', url.pathname + url.search);
            }
            if (agenda) loadAgenda();
        }
        $$('button[data-view]').forEach((button) =>
            button.addEventListener('click', () => switchView(button.dataset.view))
        );
        $$('[data-nav-view]').forEach((link) =>
            link.addEventListener('click', (event) => {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                switchView(link.dataset.navView);
                $('#sidebar').classList.remove('is-open');
                $('#toggle-sidebar').setAttribute('aria-expanded', 'false');
                syncSidebar();
            })
        );
        $('#agenda-start').addEventListener('change', loadAgenda);
        for (const [id, days] of [
            ['agenda-previous', -7],
            ['agenda-next', 7],
        ])
            document.getElementById(id).addEventListener('click', () => {
                const date = addDays($('#agenda-start').value || today, days);
                if (date < '1000-01-01' || date > '9999-12-25') return;
                $('#agenda-start').value = date;
                loadAgenda();
            });
        const sharedStart = new URLSearchParams(location.search).get('inicio_agenda');
        if (sharedStart && /^\d{4}-\d{2}-\d{2}$/.test(sharedStart)) $('#agenda-start').value = sharedStart;
        switchView(listPage.dataset.view, false);
    } else {
        $('[data-nav-view="list"]').classList.add('active');
    }
    const mobileSidebar = matchMedia('(max-width: 760px)');
    const syncSidebar = () => {
        $('#sidebar').inert = mobileSidebar.matches && !$('#sidebar').classList.contains('is-open');
    };
    mobileSidebar.addEventListener('change', syncSidebar);
    syncSidebar();
    $('#page-size')?.setAttribute('aria-label', 'Reservas por página');
    $('#toggle-sidebar').addEventListener('click', () => {
        const open = $('#sidebar').classList.toggle('is-open');
        $('#toggle-sidebar').setAttribute('aria-expanded', String(open));
        syncSidebar();
        if (open) $('.nav-link', $('#sidebar')).focus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const wasOpen = $('#sidebar').classList.contains('is-open');
            $('#sidebar').classList.remove('is-open');
            $('#toggle-sidebar').setAttribute('aria-expanded', 'false');
            syncSidebar();
            if (wasOpen) $('#toggle-sidebar').focus();
        }
    });
})();
