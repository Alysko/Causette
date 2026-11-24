const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { resolve } = require('node:path');
const { chromium } = require('playwright');

async function run() {
    const html = execFileSync(process.env.PHP_BINARY || 'php', [
        resolve(__dirname, 'chat-bootstrap.test.php'), '--render-dialogs',
    ], { encoding: 'utf8' });
    const browser = await chromium.launch();

    try {
        const page = await browser.newPage({
            viewport: { width: 390, height: 780 },
            isMobile: true,
            hasTouch: true,
        });
        await page.route('**/*', route => route.abort());
        await page.setContent('<meta name="viewport" content="width=device-width, initial-scale=1">' + html);
        for (const file of ['causette.css', 'layout.css']) {
            await page.addStyleTag({ path: resolve(__dirname, '../public_html/assets/css', file) });
        }

        // A virtual keyboard can resize the visual viewport without changing the layout viewport.
        const visibleHeight = 300;
        await page.evaluate(height => document.documentElement.style.setProperty('--app-vh', height + 'px'), visibleHeight);

        const failures = [];
        for (const [id, submitId] of [
            ['create-poll-modal', 'create-poll-submit'],
            ['message-settings-modal', 'message-settings-submit'],
        ]) {
            const overlay = page.locator('#' + id);
            const dialog = overlay.locator('[role=dialog]');
            await overlay.evaluate(element => {
                element.classList.remove('hidden');
                element.removeAttribute('aria-hidden');
            });
            const bounds = await dialog.boundingBox();
            if (bounds.y < 0 || bounds.y + bounds.height > visibleHeight) {
                failures.push(id + ' extends outside the visible viewport');
            }

            const submit = dialog.locator('#' + submitId);
            await submit.scrollIntoViewIfNeeded();
            const button = await submit.boundingBox();
            if (button.y < 0 || button.y + button.height > visibleHeight) {
                failures.push(id + ' submit is unreachable by scrolling inside the dialog');
            }
            await overlay.evaluate(element => element.classList.add('hidden'));
        }
        assert.deepEqual(failures, []);
    } finally {
        await browser.close();
    }
    console.log('Mobile chat dialogs fit the reduced viewport and their submit buttons are reachable.');
}

run().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
