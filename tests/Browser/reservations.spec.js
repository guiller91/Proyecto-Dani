const { test, expect } = require('@playwright/test');
const { randomUUID } = require('node:crypto');
const AxeBuilder = require('@axe-core/playwright').default;

test('preserves page size when navigating a filtered shared URL', async ({ page, request }) => {
    const prefix = `Paginación ${randomUUID().slice(0, 8)}`;
    const first = await createByApi(request, { nombre_huesped: `${prefix} A` });
    const second = await createByApi(request, { nombre_huesped: `${prefix} B` });
    await page.goto(`/?q=${encodeURIComponent(prefix)}&per_page=1`);
    await expect(page.locator('#reservation-rows tr')).toHaveCount(1);
    await expect(page.getByRole('link', { name: `${prefix} B`, exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Página siguiente' }).click();
    await expect(page.getByRole('link', { name: `${prefix} A`, exact: true })).toHaveAttribute(
        'href',
        new RegExp(`/reservas/${first.id}$`)
    );
    await expect(page.locator('#page-number')).toHaveText('2 / 2');
    await expect(page.locator('#reservation-rows tr')).toHaveCount(1);
    await page.getByRole('button', { name: 'Página anterior' }).click();
    await expect(page.getByRole('link', { name: `${prefix} B`, exact: true })).toHaveAttribute(
        'href',
        new RegExp(`/reservas/${second.id}$`)
    );
});

async function createByApi(request, overrides = {}) {
    const { csrf_token: token } = await (await request.get('/api/session')).json();
    const response = await request.post('/api/reservas', {
        headers: { 'X-CSRF-Token': token },
        data: {
            nombre_huesped: `Test ${randomUUID().slice(0, 8)}`,
            email_huesped: 'browser@example.test',
            nombre_alojamiento: 'Casa del Olivo · Ronda',
            fecha_entrada: '2028-06-10',
            fecha_salida: '2028-06-13',
            importe: '345.00',
            notas: '',
            ...overrides,
        },
    });
    expect(response.status()).toBe(201);
    return (await response.json()).data;
}

test('creates, filters, cancels without navigation, and shows history', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Reservas', level: 1, exact: true })).toBeVisible();
    let navigations = 0;
    page.on('request', (request) => {
        if (request.isNavigationRequest() && request.frame() === page.mainFrame()) navigations++;
    });
    await page.getByRole('button', { name: 'Nueva reserva' }).click();
    const dialog = page.getByRole('dialog', { name: 'Nueva reserva', exact: true });
    await dialog.getByRole('button', { name: 'Crear reserva' }).click();
    await expect(dialog.locator('[aria-invalid="true"]').first()).toBeVisible();
    const name = `Lucía E2E ${randomUUID().slice(0, 8)}`;
    await dialog.getByLabel('Nombre del huésped').fill(name);
    await dialog.getByLabel('Email', { exact: true }).fill('lucia@example.test');
    await dialog.getByLabel('Alojamiento', { exact: true }).fill('Patio de la Luz · Córdoba');
    await dialog.getByLabel('Entrada', { exact: true }).fill('2028-06-10');
    await dialog.getByLabel('Salida', { exact: true }).fill('2028-06-13');
    await dialog.getByLabel('Importe total').fill('198.50');
    await dialog.getByLabel('Notas').fill('Llegada a las 18:00.');
    await dialog.getByRole('button', { name: 'Crear reserva' }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('link', { name, exact: true })).toBeVisible();
    await page.getByLabel('Buscar huésped').fill(name);
    await expect(page.locator('#result-count')).toHaveText('1');
    const link = page.getByRole('link', { name, exact: true });
    const detailUrl = await link.getAttribute('href');
    await page.getByRole('button', { name: `Cancelar reserva de ${name}`, exact: true }).click();
    const cancel = page.getByRole('dialog', { name: /¿Cancelar la reserva/ });
    await cancel.getByRole('button', { name: 'Volver' }).click();
    await expect(page.locator('#reservation-rows .badge')).toHaveText('Pendiente');
    await page.getByRole('button', { name: `Cancelar reserva de ${name}`, exact: true }).click();
    await cancel.getByRole('button', { name: 'Sí, cancelar reserva' }).click();
    await expect(page.locator('#reservation-rows .badge')).toHaveText('Cancelada');
    expect(navigations).toBe(0);
    await page.goto(detailUrl);
    await expect(page.locator('#event-timeline li')).toHaveCount(2);
    await expect(page.getByText('Reserva cancelada', { exact: true })).toBeVisible();
    await expect(page.getByText('Llegada a las 18:00.', { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('confirms then cancels from detail without reloading', async ({ page, request }) => {
    const reservation = await createByApi(request);
    await page.goto(`/reservas/${reservation.id}`);
    await page.getByRole('button', { name: 'Confirmar reserva', exact: false }).click();
    await expect(page.locator('#detail-status')).toHaveText('Confirmada');
    await expect(page.locator('#event-timeline li')).toHaveCount(2);
    await page.getByRole('button', { name: 'Cancelar reserva', exact: true }).click();
    await page.getByRole('button', { name: 'Sí, cancelar reserva' }).click();
    await expect(page.locator('#detail-status')).toHaveText('Cancelada');
    await expect(page.locator('#event-timeline li')).toHaveCount(3);
    await expect(page.locator('#detail-actions')).not.toBeVisible();
});

test('renders dangerous text safely after API filtering and in Twig detail', async ({ page, request }) => {
    const name = `<img src=x onerror=alert(1)> ${randomUUID().slice(0, 6)}`;
    const reservation = await createByApi(request, {
        nombre_huesped: name,
        notas: '<script>window.pwned=1</script>',
    });
    await page.goto('/');
    await page.getByLabel('Buscar huésped').fill(name);
    await expect(page.getByRole('link', { name, exact: true })).toBeVisible();
    await expect(page.locator('#reservation-rows img')).toHaveCount(0);
    await page.goto(`/reservas/${reservation.id}`);
    await expect(page.locator('.notes')).toHaveText('<script>window.pwned=1</script>');
    expect(await page.evaluate(() => window.pwned)).toBeUndefined();
});

test('shows server validation and network errors, then recovers', async ({ page }) => {
    await page.goto('/');
    await page.route('**/api/reservas?*', (route) =>
        route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: { message: 'Error de prueba del servidor.' } }),
        })
    );
    await page.getByLabel('Buscar huésped').fill('error');
    await expect(page.locator('#list-error')).toHaveText('Error de prueba del servidor.');
    await expect(page.locator('#loading-indicator')).not.toBeVisible();
    await page.unroute('**/api/reservas?*');
    await page.route('**/api/reservas?*', (route) => route.abort('failed'));
    await page.getByLabel('Buscar huésped').fill('sin red');
    await expect(page.locator('#list-error')).toContainText('conectar con el servidor');
    await page.unroute('**/api/reservas?*');
    await page.getByRole('button', { name: 'Limpiar filtros' }).click();
    await expect(page.locator('#list-error')).not.toBeVisible();
    await page.getByRole('button', { name: 'Nueva reserva' }).click();
    const dialog = page.locator('#create-dialog');
    await dialog.getByLabel('Nombre del huésped').fill('Prueba de validación');
    await dialog.getByLabel('Email', { exact: true }).fill('correct@example.test');
    await dialog.getByLabel('Alojamiento', { exact: true }).fill('Casa de prueba');
    await dialog.getByLabel('Entrada', { exact: true }).fill('2028-06-10');
    await dialog.getByLabel('Salida', { exact: true }).fill('2028-06-13');
    await dialog.getByLabel('Importe total').fill('100');
    await page.route('**/api/reservas', (route) =>
        route.fulfill({
            status: 400,
            contentType: 'application/json',
            body: JSON.stringify({
                error: {
                    message: 'Revisa los campos.',
                    fields: { email_huesped: 'Email rechazado por el servidor.' },
                },
            }),
        })
    );
    await dialog.getByRole('button', { name: 'Crear reserva' }).click();
    await expect(page.locator('#error-email_huesped')).toHaveText('Email rechazado por el servidor.');
    await expect(dialog).toBeVisible();
});

test('handles stale version conflict without losing visible state', async ({ page, request }) => {
    const reservation = await createByApi(request);
    await page.goto(`/reservas/${reservation.id}`);
    const { csrf_token: token } = await (await request.get('/api/session')).json();
    const changed = await request.patch(`/api/reservas/${reservation.id}/estado`, {
        headers: { 'X-CSRF-Token': token },
        data: { estado: 'confirmada', version: 1 },
    });
    expect(changed.ok()).toBeTruthy();
    await page.getByRole('button', { name: 'Confirmar reserva' }).click();
    await expect(page.locator('#notification')).toContainText('La reserva ha cambiado');
    await page.reload();
    await expect(page.locator('#detail-status')).toHaveText('Confirmada');
});

test('has accessible list, creation dialog and detail with no page overflow', async ({ page, request }) => {
    await createByApi(request);
    await page.goto('/');
    for (const state of ['list', 'create']) {
        if (state === 'create') await page.getByRole('button', { name: 'Nueva reserva' }).click();
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        expect(
            scan.violations.map((v) => ({
                id: v.id,
                nodes: v.nodes.map((n) => ({ target: n.target, summary: n.failureSummary })),
            }))
        ).toEqual([]);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= document.documentElement.clientWidth
            )
        ).toBeTruthy();
    }
    await page.keyboard.press('Escape');
    const reservation = await createByApi(request);
    await page.goto(`/reservas/${reservation.id}`);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(
        scan.violations.map((v) => ({
            id: v.id,
            nodes: v.nodes.map((n) => ({ target: n.target, summary: n.failureSummary })),
        }))
    ).toEqual([]);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= document.documentElement.clientWidth
        )
    ).toBeTruthy();
});
