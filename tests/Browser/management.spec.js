const { test, expect } = require('@playwright/test');
const { randomUUID } = require('node:crypto');
const { readFile } = require('node:fs/promises');
const AxeBuilder = require('@axe-core/playwright').default;

async function create(request, overrides = {}) {
    const { csrf_token: token } = await (await request.get('/api/session')).json();
    const response = await request.post('/api/reservas', {
        headers: { 'X-CSRF-Token': token },
        data: {
            nombre_huesped: `Gestión ${randomUUID().slice(0, 8)}`,
            email_huesped: 'gestion@example.test',
            nombre_alojamiento: 'Casa Gestión',
            fecha_entrada: '2028-06-10',
            fecha_salida: '2028-06-13',
            importe: '320.00',
            notas: '',
            ...overrides,
        },
    });
    expect(response.status()).toBe(201);
    return (await response.json()).data;
}

test('edits a reservation from the quick view and preserves its history', async ({ page, request }) => {
    const reservation = await create(request);
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(`/?q=${encodeURIComponent(reservation.nombre_huesped)}`);
    await page
        .getByRole('link', { name: `Ver reserva de ${reservation.nombre_huesped}`, exact: true })
        .click();
    const preview = page.getByRole('dialog', { name: /Reserva #/ });
    await expect(preview.getByRole('heading', { name: reservation.nombre_huesped })).toBeVisible();
    await preview.getByRole('button', { name: 'Editar reserva' }).click();
    const form = page.getByRole('dialog', { name: /Editar reserva/ });
    await form.getByLabel('Importe total').fill('450.25');
    await form.getByLabel('Notas').fill('Llegada modificada por teléfono.');
    await form.getByRole('button', { name: 'Guardar cambios' }).click();
    await expect(form).not.toBeVisible();
    await expect(preview.locator('.preview-total')).toContainText('450,25');
    await expect(preview.getByText('Datos actualizados', { exact: true })).toBeVisible();
    await expect(preview.locator('.notes')).toHaveText('Llegada modificada por teléfono.');
    const updated = (await (await request.get(`/api/reservas/${reservation.id}`)).json()).data;
    expect(updated.version).toBe(2);
    expect(updated.eventos.map((event) => event.tipo)).toEqual(['creada', 'actualizada']);
    expect(errors).toEqual([]);
});

test('confirms and cancels a selection atomically from the table', async ({ page, request }) => {
    const prefix = `Lote ${randomUUID().slice(0, 8)}`;
    const a = await create(request, { nombre_huesped: `${prefix} A` });
    const b = await create(request, { nombre_huesped: `${prefix} B` });
    await page.goto(`/?q=${encodeURIComponent(prefix)}`);
    await page.getByLabel('Seleccionar reservas de esta página').check();
    await expect(page.locator('#selected-count')).toHaveText('2');
    await page.locator('#bulk-confirm').click();
    await page.getByRole('button', { name: 'Confirmar selección' }).click();
    await expect(page.locator('#reservation-rows .badge-confirmada')).toHaveCount(2);
    await expect(page.locator('#bulk-toolbar')).not.toBeVisible();
    await page.getByLabel('Seleccionar reservas de esta página').check();
    await page.locator('#bulk-cancel').click();
    await page.getByRole('button', { name: 'Cancelar selección' }).click();
    await expect(page.locator('#reservation-rows .badge-cancelada')).toHaveCount(2);
    await expect(page.getByLabel('Seleccionar reservas de esta página')).toBeDisabled();
    for (const id of [a.id, b.id]) {
        const data = (await (await request.get(`/api/reservas/${id}`)).json()).data;
        expect(data.eventos.map((event) => event.tipo)).toEqual(['creada', 'confirmada', 'cancelada']);
    }
});

test('exports every filtered row across pages and neutralizes CSV formulas', async ({ page, request }) => {
    const prefix = `=CSV${randomUUID().slice(0, 8)}`;
    await create(request, { nombre_huesped: `${prefix} A` });
    await create(request, { nombre_huesped: `${prefix} B` });
    const other = await create(request);
    await page.goto(`/?q=${encodeURIComponent(prefix)}&per_page=1`);
    await expect(page.locator('#reservation-rows tr')).toHaveCount(1);
    const downloadEvent = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Exportar CSV' }).click();
    const download = await downloadEvent;
    expect(download.suggestedFilename()).toMatch(/^reservas-\d{4}-\d{2}-\d{2}\.csv$/);
    const csv = await readFile(await download.path(), 'utf8');
    expect(csv).toContain(`'${prefix} A`);
    expect(csv).toContain(`'${prefix} B`);
    expect(csv).not.toContain(other.nombre_huesped);
});

test('shows arrival and departure movements and opens their quick view', async ({ page, request }) => {
    const reservation = await create(request);
    const cancelled = await create(request);
    const { csrf_token: token } = await (await request.get('/api/session')).json();
    await request.patch(`/api/reservas/${cancelled.id}/estado`, {
        headers: { 'X-CSRF-Token': token },
        data: { estado: 'cancelada', version: 1 },
    });
    await page.goto('/?vista=agenda');
    await page.getByLabel('Inicio de la agenda').fill('2028-06-10');
    await expect(
        page.locator('#agenda-content').getByRole('link', { name: reservation.nombre_huesped, exact: true })
    ).toHaveCount(2);
    await expect(
        page.locator('#agenda-content').getByRole('link', { name: cancelled.nombre_huesped, exact: true })
    ).toHaveCount(0);
    await page
        .locator('#agenda-content')
        .getByRole('link', { name: reservation.nombre_huesped, exact: true })
        .first()
        .click();
    await expect(
        page
            .getByRole('dialog', { name: /Reserva #/ })
            .getByRole('heading', { name: reservation.nombre_huesped })
    ).toBeVisible();
    await page.getByRole('button', { name: 'Cerrar vista rápida' }).click();
    await page.getByRole('button', { name: 'Lista', exact: true }).click();
    await expect(page.locator('#list-view')).toBeVisible();
});

test('property filtering, state tabs and ordering work together', async ({ page, request }) => {
    const property = `Propiedad ${randomUUID().slice(0, 8)}`;
    const low = await create(request, { nombre_alojamiento: property, importe: '10.00' });
    const high = await create(request, { nombre_alojamiento: property, importe: '999.00' });
    await page.goto('/');
    await page.getByRole('combobox', { name: 'Alojamiento', exact: true }).selectOption(property);
    await expect(page.locator('#result-count')).toHaveText('2');
    await page.getByRole('button', { name: 'Pendientes', exact: true }).click();
    await page.getByLabel('Ordenar por').selectOption('importe_desc');
    await expect(page.locator('#reservation-rows .guest-name').first()).toHaveText(high.nombre_huesped);
    await expect(page.locator('#reservation-rows .guest-name').last()).toHaveText(low.nombre_huesped);
    await page.getByRole('button', { name: 'Confirmadas', exact: true }).click();
    await expect(page.locator('#result-count')).toHaveText('0');
});

test('quick view, agenda and batch confirmation remain accessible', async ({ page, request }) => {
    const reservation = await create(request);
    await page.goto(`/?q=${encodeURIComponent(reservation.nombre_huesped)}`);
    await page.getByRole('link', { name: `Ver reserva de ${reservation.nombre_huesped}` }).click();
    let scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(scan.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) }))).toEqual([]);
    await page.getByRole('button', { name: 'Cerrar vista rápida' }).click();
    await page.getByLabel('Seleccionar reservas de esta página').check();
    await page.locator('#bulk-confirm').click();
    scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(scan.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) }))).toEqual([]);
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Agenda', exact: true }).click();
    await expect(page.locator('.agenda-day')).toHaveCount(7);
    scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(scan.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) }))).toEqual([]);
});
