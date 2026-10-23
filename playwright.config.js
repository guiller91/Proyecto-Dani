const { defineConfig, devices } = require('@playwright/test');
const { existsSync } = require('node:fs');
require('dotenv').config({ path: '.env.test', quiet: true });

if (process.env.APP_ENV !== 'test' || !process.env.DB_DATABASE?.endsWith('_test')) {
    throw new Error('Browser tests require APP_ENV=test and a dedicated DB_DATABASE ending in _test.');
}
const php = process.env.PHP_BINARY || (existsSync('.tools/php/php.exe') ? '.tools/php/php.exe' : 'php');

module.exports = defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: { baseURL: 'http://127.0.0.1:8081', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['iPhone 13'], defaultBrowserType: 'chromium' } },
    ],
    webServer: {
        command: `"${php}" -S 127.0.0.1:8081 -t public public/router.php`,
        url: 'http://127.0.0.1:8081',
        reuseExistingServer: false,
        timeout: 20000,
        env: { APP_ENV: 'test', DB_DATABASE: process.env.DB_DATABASE },
    },
});
