/* Requires fixtures/admin-p09.php on its guarded, disposable SQLite database. */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = 'http://127.0.0.1:8050';
const fixture = JSON.parse(fs.readFileSync('/tmp/juthoor-admin-p09-fixture.json'));
const output = 'docs/screenshots/ux-p0.9';
fs.mkdirSync(output, {recursive: true});
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROME_PATH || '/opt/google/chrome/chrome', headless: true, args: ['--no-sandbox']});
    const results = [], errors = [];
    const contexts = [];
    const login = async role => {
        const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
        contexts.push(context);
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(`${role}: ${error.message}`));
        await page.goto(`${base}/login`);
        await page.locator('[name=email]').fill(`p09-${role}`);
        await page.locator('[name=password]').fill('password');
        await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('form button').click()]);
        return page;
    };
    const visit = async (page, path, name, status = 200) => {
        const response = await page.goto(base + path);
        assert.equal(response.status(), status, `${name}: ${await page.locator('body').innerText().then(s => s.slice(0, 160))}`);
        if (status === 200) {
            await page.waitForTimeout(80);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${name}: page overflow`);
            assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');
            await page.screenshot({path: `${output}/after-${name}.png`, fullPage: true});
        }
        results.push({journey: name, status});
    };
    const family = `/manage/families/${fixture.families.first}`;
    const superPage = await login('super');
    await visit(superPage, '/site-admin', 'platform');
    assert.equal(await superPage.locator('body').getAttribute('data-admin-environment'), 'platform');
    await superPage.screenshot({path: `${output}/platform-overview.png`});
    for (const resource of ['families', 'trees', 'users', 'memberships', 'invitations', 'audit']) await visit(superPage, `/site-admin/${resource}`, `platform-${resource}`);
    await visit(superPage, '/site-admin/families/1/edit', 'platform-family-edit');
    assert.equal(await superPage.locator('body').getAttribute('data-admin-environment'), 'platform');
    await visit(superPage, '/site-admin/domains', 'domains');
    const admin = await login('admin');
    await visit(admin, `${family}/dashboard`, 'family');
    assert.equal(await admin.locator('body').getAttribute('data-admin-environment'), 'family');
    assert.equal(await admin.locator('.management-sidebar a[href="'+base+'/site-admin"]').count(), 0);
    await admin.screenshot({path: `${output}/family-overview.png`});
    await admin.locator('#familySwitch').selectOption(`${base}/manage/families/${fixture.families.second}/dashboard`);
    await Promise.all([admin.waitForURL(`**/manage/families/${fixture.families.second}/dashboard`), admin.locator('[data-family-switch-form] button').click()]);
    assert.equal(new URL(admin.url()).search, '');
    assert.ok((await admin.locator('body').getAttribute('style')).includes('#5e2430'));
    assert.equal(await admin.locator('.management-sidebar').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(94, 36, 48)');
    assert.ok((await admin.locator('main').innerText()).includes('عائلة الأفق التجريبية'));
    assert.ok(!(await admin.locator('main').innerText()).includes('عشيرة التعاون التجريبية'));
    await admin.screenshot({path: `${output}/after-family-switch-second.png`, fullPage: true});
    results.push({journey: 'authorized-family-switch-and-brand', status: 200});
    await visit(admin, `${family}/pages/people`, 'people');
    await visit(admin, `${family}/people/${fixture.person}`, 'person');
    await visit(admin, `/families/${fixture.families.first}/tree?tree=main&private=1`, 'administration-tree');
    await admin.locator('.tree-node').first().waitFor();
    assert.equal(await admin.locator('[data-admin-return]').count(), 1);
    for (const mode of ['full','main_branches','sub_branches']) {
        await admin.locator('[data-display-mode]').selectOption(mode);
        assert.equal(await admin.locator('[data-display-mode]').inputValue(), mode);
    }
    results.push({journey:'three-existing-tree-display-modes',status:200});
    await visit(admin, `${family}/changes`, 'genealogy-review');
    await visit(admin, `${family}/changes/1`, 'genealogy-comparison');
    await visit(admin, `${family}/entities`, 'entity-hierarchy');
    await visit(admin, `${family}/portal`, 'portal-settings');
    await visit(admin, `${family}/entities/${fixture.entity}/content/history`, 'heritage');
    await visit(admin, `${family}/entities/${fixture.entity}/content/media`, 'media');
    await visit(admin, `${family}/entities/${fixture.entity}/content/news`, 'news');
    await visit(admin, `${family}/entities/${fixture.entity}/content/events`, 'events');
    await visit(admin, `${family}/directory/review`, 'directory');
    assert.ok(!(await admin.locator('body').innerText()).includes('PRIVATE-CONTACT-P09'));
    await visit(admin, `${family}/roles`, 'roles');
    await visit(admin, `${family}/pages/members`, 'membership-assignments');
    await visit(admin, `${family}/pages/invitations`, 'invitations');
    await visit(admin, `${family}/pages/audit`, 'family-audit');
    await visit(admin, `/manage/families/${fixture.families.outside}/dashboard`, 'cross-family-denied', 403);
    await visit(admin, '/site-admin', 'platform-denied-to-family-admin', 403);
    for (const role of ['genealogy-reviewer', 'genealogy-editor', 'news-editor', 'news-publisher', 'directory-moderator', 'entity-editor', 'reader']) {
        const page = await login(role);
        await visit(page, `${family}/dashboard`, role);
        if (role === 'news-editor' || role === 'directory-moderator') assert.equal(await page.locator('.management-sidebar a[href*="/records/people"]').count(), 0);
        if (role === 'genealogy-reviewer') await visit(page, `${family}/changes/1`, 'reviewer-comparison');
        if (role === 'genealogy-editor') await visit(page, `${family}/records/people/create`, 'genealogy-editor-create');
        if (role === 'news-editor') {
            await visit(page, `${family}/entities/${fixture.entity}/content/news/create`, 'news-editor-create');
            assert.equal(await page.locator('[name=is_featured]').count(), 0);
            assert.equal(await page.locator('nav[aria-label="أقسام محتوى الكيان"] a[href$="/media"]').count(), 0);
        }
        if (role === 'news-publisher') await visit(page, `${family}/entity-review`, 'publisher-queue');
        if (role === 'directory-moderator') {
            await visit(page, `${family}/directory/review`, 'moderator-queue');
            assert.ok(!(await page.locator('body').innerText()).includes('PRIVATE-CONTACT-P09'));
            await visit(page, `${family}/roles`, 'moderator-roles-denied', 403);
        }
        if (role === 'entity-editor') {
            await visit(page, `${family}/entities`, 'entity-editor-hierarchy');
            assert.ok(!(await page.locator('body').innerText()).includes('كيان شقيق خارج التحرير'));
            await visit(page, `${family}/entities/${fixture.child}/edit`, 'entity-editor-own');
            await visit(page, `${family}/entities/${fixture.sibling}/edit`, 'entity-editor-sibling-denied', 403);
        }
    }
    for (const [page, name, path] of [[admin,'family',`${family}/dashboard`], [superPage,'platform','/site-admin'], [admin,'roles',`${family}/roles`], [admin,'members',`${family}/pages/members`]]) {
        await page.setViewportSize({width:390,height:844});
        await visit(page,path,`mobile-${name}`);
        await page.locator('[data-sidebar-toggle]').click();
        await page.waitForTimeout(30);
        assert.equal(await page.locator('[data-sidebar-toggle]').getAttribute('aria-expanded'),'true');
        assert.equal(await page.locator('.management-main').evaluate(el=>el.inert),true);
        await page.screenshot({path:`${output}/mobile-${name}-navigation.png`,fullPage:true});
        await page.keyboard.press('Escape');
        await page.waitForTimeout(30);
        assert.equal(await page.locator('[data-sidebar-toggle]').getAttribute('aria-expanded'),'false');
        assert.equal(await page.locator('.management-sidebar').evaluate(el=>el.inert),true);
        assert.equal(await page.locator('[data-sidebar-toggle]').evaluate(el=>document.activeElement===el),true);
    }
    assert.deepEqual(errors,[]);
    for (const context of contexts) await context.close();
    await browser.close();
    fs.writeFileSync(`${output}/journeys.json`,JSON.stringify({results,errors},null,2));
    console.log(JSON.stringify({journeys:results.length,errors}));
})().catch(error=>{console.error(error);process.exit(1)});
