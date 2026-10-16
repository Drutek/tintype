// Drive the review UI with Playwright: log in, open a build, accept all changes.
// Usage: node scripts/e2e-review.mjs <baseUrl> <email> <password> <org> <project> <buildNumber> [screenshotDir]
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const require = createRequire(join(dirname(fileURLToPath(import.meta.url)), '../packages/capture/package.json'));
const { chromium } = require('playwright');

const [base, email, password, org, project, build, shots] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const shot = async (name) => shots && page.screenshot({ path: join(shots, `${name}.png`), fullPage: false });

try {
  await page.goto(`${base}/login`);
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: /log in/i }).click();
  await page.waitForURL('**/dashboard');
  await shot('dashboard');

  await page.goto(`${base}/orgs/${org}/projects/${project}`);
  await page.getByText(`#${build}`).first().waitFor();
  await shot('builds');

  await page.goto(`${base}/orgs/${org}/projects/${project}/builds/${build}`);
  await page.getByTestId('snapshot-item').first().waitFor();
  await page.getByTestId('snapshot-new').first().waitFor();
  await shot('build-diff');
  await page.keyboard.press('2');
  await shot('build-side-by-side');
  await page.keyboard.press('1');

  // Accept the first one with the keyboard, the rest with "Accept all".
  const first = await page.getByTestId('current-story').textContent();
  await page.keyboard.press('a');
  await page.waitForTimeout(500);
  const acceptAll = page.getByTestId('accept-all');
  if (await acceptAll.count()) await acceptAll.click();
  await page.locator('[data-status="accepted"]').first().waitFor({ timeout: 10_000 });
  await shot('build-accepted');
  console.log(`Accepted build ${build} in the UI (first reviewed: ${first?.trim()})`);

  await page.goto(`${base}/orgs/${org}/projects/${project}/library`);
  await shot('library');
} catch (e) {
  await shot('failure');
  console.error(e);
  process.exitCode = 1;
} finally {
  await browser.close();
}
