// Open Storybook dev with the Tintype addon and check the Visual tests panel shows server data.
// Usage: node scripts/e2e-addon.mjs <storybookUrl> <expectText> [screenshot]
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(join(dirname(fileURLToPath(import.meta.url)), '../packages/capture/package.json'));
const { chromium } = require('playwright');
const [url, expectText, shot] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
try {
  await page.goto(`${url}/?path=/story/components-button--secondary`);
  await page.getByRole('tab', { name: 'Visual tests' }).click({ timeout: 60_000 });
  const panel = page.locator('#storybook-panel-root');
  await panel.getByText(expectText).first().waitFor({ timeout: 20_000 });
  // The snapshot image is served from a signed URL; make sure it actually loads.
  await page.waitForFunction(() => {
    const img = document.querySelector('#storybook-panel-root img');
    return img instanceof HTMLImageElement && img.complete && img.naturalWidth > 0;
  }, undefined, { timeout: 15_000 });
  if (shot) await page.screenshot({ path: shot });
  console.log(`Addon panel shows "${expectText}"`);
} catch (e) {
  if (shot) await page.screenshot({ path: shot });
  console.error(e.message, '\n', await page.locator('#storybook-panel-root').innerText().catch(() => ''));
  process.exitCode = 1;
} finally {
  await browser.close();
}
