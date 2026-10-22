const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.BROWSER_BASE_URL || 'http://127.0.0.1:8000';
const output = process.env.BROWSER_ARTIFACTS || '/tmp/juthoor-browser-artifacts';
fs.mkdirSync(output, {recursive:true});
const fixture = JSON.parse(fs.readFileSync(process.env.BROWSER_FIXTURE || path.join(output, 'fixture.json')));
const checks = [], errors = [], failures = [];
let browser;
async function check(name, action) { await action(); checks.push(name); console.log('PASS', name); }
async function settle(page) { await page.waitForLoadState('networkidle'); }
async function ready(page) {
    await page.waitForFunction(() => document.querySelector('[data-tree-loading]')?.hidden && document.querySelectorAll('.tree-svg-node').length > 0);
    assert.equal(await page.locator('[data-tree-error]').isVisible(), false);
}
async function screenshot(page, name) { await page.screenshot({path:path.join(output, `${name}.png`), fullPage:true}); }
async function overflow(page) {
    const widths = await page.evaluate(() => ({page:document.documentElement.scrollWidth, viewport:innerWidth, rtl:document.documentElement.dir}));
    assert.equal(widths.rtl, 'rtl'); assert.ok(widths.page <= page.viewportSize().width+1, JSON.stringify(widths));
}
async function submit(page, form) {
    const [response] = await Promise.all([page.waitForResponse(response => response.request().method() === 'POST' && response.request().isNavigationRequest()), form.locator('button[type="submit"], button:not([type])').last().click()]);
    assert.ok(response.status()<400, `Form failed with ${response.status()}: ${response.url()}`);
    await settle(page);
    assert.equal(await page.locator('.alert-danger').count(), 0, await page.locator('.alert-danger').allTextContents());
    assert.ok(await page.locator('.alert-success').count() <= 1, 'A form result must be displayed once');
}
async function login(page) {
    await page.goto(`${base}/login`);
    await page.locator('[name=email]').fill('browser-admin@example.test');
    await page.locator('[name=password]').fill('browser-test-password');
    await submit(page, page.locator('form'));
}
(async () => {
    browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless:true, args:['--no-sandbox']});
    const desktop = await browser.newContext({viewport:{width:1440,height:1000}});
    const page = await desktop.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('requestfailed', request => { if (!request.failure().errorText.includes('ERR_ABORTED')) failures.push({url:request.url(),error:request.failure().errorText}); });
    await check('desktop RTL and locally served styles/fonts', async () => {
        await page.goto(base); await settle(page); await overflow(page);
        const font = await page.evaluate(() => document.fonts.check('700 16px Cairo'));
        assert.equal(font, true); assert.equal(await page.locator('a[href*="families/synthetic/tree"]').count(), 3);
        await screenshot(page, 'home-desktop');
    });
    await page.goto(`${base}/families/synthetic/tree?tree=main`); await ready(page);
    await page.locator('[data-branch-select]').selectOption('small'); await ready(page);
    await check('tree names and nodes never overlap in SVG layout', async () => {
        const result = await page.evaluate(() => {
            const groups = [...document.querySelectorAll('.tree-svg-node')];
            const boxes = groups.map(group => { const r=group.querySelector('rect').getBBox();const m=group.transform.baseVal.consolidate().matrix;return {x:m.e,y:m.f,w:r.width,h:r.height}; });
            let overlapping = false;
            for (let i=0;i<boxes.length;i++) for(let j=i+1;j<boxes.length;j++) { const a=boxes[i],b=boxes[j]; if(a.x<b.x+b.w && a.x+a.w>b.x && a.y<b.y+b.h && a.y+a.h>b.y) overlapping=true; }
            const clipped = groups.some(group => [...group.querySelectorAll('text:not(.tree-node-meta)')].some(text => text.getComputedTextLength()>220));
            return {overlapping,clipped};
        });
        assert.deepEqual(result, {overlapping:false,clipped:false}); await overflow(page); await screenshot(page, 'tree-desktop');
    });
    await check('mouse zoom/drag and repeated collapse restore the viewport', async () => {
        const before = await page.locator('[data-tree-viewport]').getAttribute('transform');
        await page.locator('[data-tree-action=zoom-in]').click();
        assert.notEqual(await page.locator('[data-tree-viewport]').getAttribute('transform'), before);
        const box = await page.locator('[data-tree-svg]').boundingBox();
        await page.mouse.move(box.x+40,box.y+80); await page.mouse.down(); await page.mouse.move(box.x+120,box.y+120,{steps:8});await page.mouse.up();
        const camera = await page.locator('[data-tree-viewport]').getAttribute('transform');
        // Keyboard activation works even if the node is outside the current camera.
        await page.locator(`.tree-svg-node[data-id="${fixture.small}"]`).press('Enter');
        const count = await page.locator('.tree-svg-node').count();
        for(let i=0;i<3;i++) {await page.locator('[data-person-toggle]').click(); assert.ok(await page.locator('.tree-svg-node').count()<count); await page.locator('[data-person-toggle]').click(); assert.equal(await page.locator('.tree-svg-node').count(),count);}
        assert.equal(await page.locator('[data-tree-viewport]').getAttribute('transform'), camera);
    });
    await check('branch changes discard late responses and restore saved folding/camera', async () => {
        await page.locator('[data-person-toggle]').click();
        const foldedCount=await page.locator('.tree-svg-node').count(), camera=await page.locator('[data-tree-viewport]').getAttribute('transform');
        await page.route('**/families/synthetic/graph?**', async route => {
            if(new URL(route.request().url()).searchParams.get('branch')==='wide') await new Promise(resolve=>setTimeout(resolve,300));
            await route.continue().catch(()=>{});
        });
        await page.locator('[data-branch-select]').selectOption('wide');
        await page.locator(`.tree-svg-node[data-id="${fixture.small}"]`).press('Enter');
        assert.equal(await page.locator('[data-person-panel]').isVisible(), false, 'Old branch nodes must not open while the new branch is loading');
        await page.locator('[data-branch-select]').selectOption('small');
        await ready(page); await page.waitForTimeout(450);
        assert.equal(await page.locator('.tree-svg-node').count(),foldedCount);
        assert.equal(await page.locator('[data-person-load]').isEnabled(), true, 'Cached branch must permit loading after cancelling another request');
        assert.equal(await page.locator('[data-tree-viewport]').getAttribute('transform'),camera);
        await page.unroute('**/families/synthetic/graph?**');
        await page.locator('[data-branch-select]').selectOption('wide'); await ready(page);
        assert.equal(await page.locator(`.tree-svg-node--branch_root[data-id="${fixture.wide}"]`).count(), 1);
        await page.locator('[data-branch-select]').selectOption('small'); await ready(page);
        assert.equal(await page.locator('.tree-svg-node').count(), foldedCount);
        await page.locator('[data-person-toggle]').click();
    });
    await check('consecutive large child batches merge without duplicates and preserve anchored position', async () => {
        await page.locator('[data-focus-mode]').selectOption('isolated');await ready(page);
        await page.locator('[data-branch-select]').selectOption('wide');await ready(page);
        await page.locator(`.tree-svg-node[data-id="${fixture.wide}"]`).press('Enter');
        const position = () => page.locator(`.tree-svg-node[data-id="${fixture.wide}"] rect`).evaluate(el=>{ const b=el.getBoundingClientRect();return {x:b.x,y:b.y}; });
        const anchor=await position();
        for(let i=0;i<2;i++) { await page.locator('[data-person-load]').click(); await ready(page); }
        const ids=await page.locator('.tree-svg-node').evaluateAll(nodes=>nodes.map(node=>node.dataset.id));
        assert.equal(ids.length,362);assert.equal(new Set(ids).size,ids.length);
        assert.equal(await page.locator('.tree-edge').count(),361);
        assert.equal(await page.locator('[data-person-load]').isVisible(),false);
        const current=await position(); assert.ok(Math.abs(anchor.x-current.x)<2 && Math.abs(anchor.y-current.y)<2, JSON.stringify({anchor,current}));
        await screenshot(page,'tree-large-desktop');
    });
    await check('search focuses an unloaded long-name person and displays the full name', async () => {
        await page.locator('[data-branch-select]').selectOption('small'); await ready(page);
        assert.equal(await page.locator(`.tree-svg-node[data-id="${fixture.far}"]`).count(),0);
        await page.locator('[data-tree-search]').fill('شخص بعيد');
        await page.locator('[data-tree-search-results] button').first().click();await ready(page);
        assert.equal(await page.locator('[data-person-panel]').isVisible(),true);
        assert.equal(await page.locator(`[data-id="${fixture.far}"]`).count(),1);
        const name=await page.locator('[data-person-name]').textContent();assert.ok(name.endsWith('الاختبار الاصطناعي'));
        const svgName=await page.locator(`[data-id="${fixture.far}"] text:not(.tree-node-meta)`).allTextContents();assert.equal(svgName.join(' '),name);
        await screenshot(page,'person-search-desktop');
    });
    await check('public tree selector and person link retain the second tree', async () => {
        await page.locator('select[aria-label="اختيار الشجرة"]').selectOption({label:'شجرة مستقلة ثانية'});await ready(page);
        assert.equal(await page.locator('.tree-svg-node').count(),1);
        await page.locator('.tree-svg-node').click();
        const link=page.locator('[data-person-link]');assert.match(await link.getAttribute('href'),/tree=second/);
        await link.click();await settle(page);assert.match(page.url(),/tree=second/);await screenshot(page,'person-desktop');
    });
    await login(page);
    await check('private preview shows the correct pending verification and remains inaccessible publicly', async () => {
        await page.goto(`${base}/families/synthetic/tree?tree=private&private=1`); await ready(page);
        await page.locator('.tree-svg-node').press('Enter');
        assert.equal(await page.locator('[data-person-status]').textContent(), 'قيد المراجعة');
        assert.equal(await page.locator('.tree-svg-node').count(), 1);
        assert.match(await page.locator('[data-person-link]').getAttribute('href'), /private=1/);
        await screenshot(page, 'private-preview-desktop');
        const response = await page.goto(`${base}/families/synthetic/graph?tree=private`);
        assert.equal(response.status(), 404);
    });
    await check('large management lists paginate, filters retain tree scope and remote options load successive pages', async () => {
        await page.goto(`${base}/manage/families/synthetic?tree=main`); await settle(page); await overflow(page);
        assert.equal(await page.locator('#people .management-list article').count(), 15);
        await Promise.all([page.waitForURL(/people_page=2/),page.locator('#people a[href*="people_page=2"]:visible').first().click()]);
        await settle(page); assert.match(page.url(), /tree=main/); await overflow(page);
        const lookup=page.locator('#relationships [name=person_id]');
        const options=await lookup.locator('option').count();
        await lookup.locator('..').getByText('تحميل خيارات أخرى',{exact:true}).click();
        await page.waitForFunction(() => document.querySelector('#relationships [name=person_id]').options.length > 21);
        assert.ok(await lookup.locator('option').count()>options);
        await screenshot(page, 'management-large-desktop');
    });
    await check('super admin creates an empty family and assigns a new manager through invitation', async () => {
        await page.goto(`${base}/site-admin`);await settle(page);
        await page.getByText('إنشاء عائلة وشجرتها',{exact:true}).click();
        const form=page.locator('form[action$="/site-admin/families"]');
        await form.locator('[name=name_ar]').fill('عائلة جديدة من المتصفح');await form.locator('[name=slug]').fill('browser-new');
        await form.locator('[name=tree_name_ar]').fill('شجرة جديدة');await form.locator('[name=manager_email]').fill('new-browser-manager@example.test');
        await submit(page,form);assert.ok(await page.getByText('عائلة جديدة من المتصفح',{exact:true}).count());
        assert.ok(await page.getByText('new-browser-manager@example.test',{exact:true}).count());await screenshot(page,'site-admin-desktop');
    });
    await check('empty-family UI creates root, child, relationship and branch then displays its tree', async () => {
        await page.goto(`${base}/manage/families/browser-new?tree=main`);await settle(page);
        for(const name of ['جذر جديد','ابن جديد']) {
            const form=page.locator('form[action*="/browser-new/people"]').first();
            const details=form.locator('..');if(!await form.isVisible())await details.locator('summary').click();
            await form.locator('[name=first_name_ar]').fill(name);await form.locator('[name=full_name_ar]').fill(name);
            await form.locator('[name=verification_status]').selectOption('verified');await submit(page,form);
        }
        const rootForm=page.locator('form[action*="/trees/"]');
        await rootForm.locator('[name=root_person_id]').selectOption({label:'جذر جديد'});await submit(page,rootForm);
        const relationship=page.locator('form[action$="/relationships?tree=main"]');
        await relationship.locator('[name=person_id]').selectOption({label:'ابن جديد'});await relationship.locator('[name=father_id]').selectOption({label:'جذر جديد'});
        await relationship.locator('[name=verification_status]').selectOption('verified');await submit(page,relationship);
        const branchForm=page.locator('form[action$="/branches?tree=main"]');
        await branchForm.locator('..').locator('summary').click();await branchForm.locator('[name=name_ar]').fill('فرع جديد');await branchForm.locator('[name=slug]').fill('new-branch');
        await branchForm.locator('[name=root_person_id]').selectOption({label:'ابن جديد'});await branchForm.locator('[name=verification_status]').selectOption('verified');await branchForm.locator('[name=is_active]').check();await submit(page,branchForm);
        await overflow(page);await screenshot(page,'management-desktop');
        await page.goto(`${base}/families/browser-new/tree?tree=main`);await ready(page);assert.equal(await page.locator('.tree-svg-node').count(),2);await screenshot(page,'new-family-tree-desktop');
    });
    const mobile=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:1});
    const phone=await mobile.newPage();phone.on('pageerror',error=>errors.push(error.message));
    await check('mobile RTL, management sidebar/backdrop and family switch', async () => {
        await phone.goto(base);await settle(phone);await overflow(phone);await screenshot(phone,'home-mobile');
        await login(phone);await phone.goto(`${base}/manage/families/synthetic?tree=second`);await settle(phone);await overflow(phone);
        await phone.locator('[data-sidebar-toggle]').tap();assert.equal(await phone.locator('.management-sidebar').evaluate(el=>el.classList.contains('is-open')),true);
        await screenshot(phone,'sidebar-mobile');
        await phone.keyboard.press('Escape');assert.equal(await phone.locator('[data-sidebar-toggle]').getAttribute('aria-expanded'),'false');
        await phone.locator('[data-sidebar-toggle]').tap();await Promise.all([phone.waitForURL('**/manage/families/browser-new/dashboard'),phone.locator('#familySwitch').selectOption(`${base}/manage/families/browser-new/dashboard`)]);await settle(phone);
        assert.match(phone.url(),/browser-new/);await overflow(phone);await screenshot(phone,'management-mobile');
    });
    await check('touch pan/pinch, cancellation and mobile tree forms', async () => {
        await phone.goto(`${base}/families/browser-new/tree?tree=main`);await ready(phone);await overflow(phone);
        const box=await phone.locator('[data-tree-svg]').boundingBox();
        const session=await mobile.newCDPSession(phone);
        const camera=await phone.locator('[data-tree-viewport]').getAttribute('transform');
        const point=(id,x,y)=>({id,x:Math.round(x),y:Math.round(y)});
        await session.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[point(1,box.x+90,box.y+180)]});
        await session.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[point(1,box.x+150,box.y+210)]});
        await session.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
        assert.notEqual(await phone.locator('[data-tree-viewport]').getAttribute('transform'),camera);
        const pan=await phone.locator('[data-tree-viewport]').getAttribute('transform');
        await session.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[point(1,box.x+100,box.y+200),point(2,box.x+220,box.y+200)]});
        await session.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[point(1,box.x+70,box.y+200),point(2,box.x+250,box.y+200)]});
        await session.send('Input.dispatchTouchEvent',{type:'touchCancel',touchPoints:[]});
        assert.notEqual(await phone.locator('[data-tree-viewport]').getAttribute('transform'),pan);
        await phone.locator('[data-tree-action=reset]').tap();await ready(phone);await screenshot(phone,'tree-mobile');
        await phone.locator('.tree-svg-node').first().tap();assert.equal(await phone.locator('[data-person-panel]').isVisible(),true);await screenshot(phone,'tree-panel-mobile');
        await phone.goto(`${base}/site-admin`);await settle(phone);await overflow(phone);await screenshot(phone,'site-admin-mobile');
    });
    assert.deepEqual(errors,[]);assert.deepEqual(failures,[]);
    fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({checks,errors,failures},null,2));
    await browser.close();
})().catch(async error=>{console.error(error);fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({checks,errors,failures,error:String(error)},null,2));await browser?.close();process.exitCode=1;});
