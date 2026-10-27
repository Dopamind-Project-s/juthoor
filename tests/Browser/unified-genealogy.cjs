const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.BROWSER_BASE_URL || 'http://127.0.0.1:8039';
assert.match(base, /^http:\/\/127\.0\.0\.1:8039$/);
const output = process.env.BROWSER_ARTIFACTS || path.resolve('docs/screenshots/genealogy-rebuild');
const fixture = JSON.parse(fs.readFileSync(process.env.BROWSER_FIXTURE || '/tmp/juthoor-browser/fixture.json'));
fs.mkdirSync(output, {recursive:true});
(async () => {
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH || '/opt/google/chrome/chrome',headless:true,args:['--no-sandbox']});
 const checks=[],errors=[];
 const context=await browser.newContext({viewport:{width:1440,height:1000}});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 async function check(name,fn){await fn();checks.push(name);console.log('PASS',name);}
 async function visit(url){const r=await page.goto(base+url);assert.equal(r.status(),200,url);await page.waitForLoadState('networkidle');}
 async function shot(name){await page.screenshot({path:path.join(output,name+'.png'),fullPage:true});}
 async function loaded(){await page.locator('[data-tree-progress]').filter({hasText:'اكتمل التحميل'}).waitFor({timeout:60000});}
 async function login(p,username){await p.goto(base+'/login');await p.locator('[name=email]').fill(username+'@juthoor.test');await p.locator('[name=password]').fill('password');await Promise.all([p.waitForURL(u=>!u.pathname.endsWith('/login')),p.locator('form[action$="/login"] button').click()]);}
 await check('public family portal and private descendant protection',async()=>{await visit('/families/al-zoubi/portal');await shot('public-portal-desktop');const r=await page.goto(base+'/families/al-zoubi/people/'+fixture['QW-0031']);assert.equal(r.status(),404);});
 await check('public 79-ancestor narrative',async()=>{await visit('/families/al-zoubi/tree');await loaded();assert.match(await page.locator('[data-tree-progress]').innerText(),/79 شخص/);assert.ok(await page.locator('.tree-node').count()>0);await shot('public-ancestry-desktop');});
 await check('local canonical family homepage uses database portal',async()=>{await visit('/al-zoubi');assert.match(page.url(),/\/families\/al-zoubi$/);});
 await check('legacy fakhd directory uses database entities',async()=>{await visit('/al-zoubi/branches');assert.match(page.url(),/\/families\/al-zoubi\/entities$/);});
 await login(page,'superadmin');
 await check('legacy fakhd preview uses seeded database page',async()=>{await visit('/al-zoubi/branches/al-qawasmeh?preview=1');assert.match(page.url(),/\/entities\/al-qawasmeh\?preview=1$/);assert.match(await page.locator('body').innerText(),/356/);});
 await check('complete private unified tree',async()=>{await visit('/families/al-zoubi/tree?private=1');await loaded();assert.match(await page.locator('[data-tree-progress]').innerText(),new RegExp(fixture.count+' شخص'));assert.ok(await page.locator('.tree-node').count()>0);await shot('private-unified-desktop');});
 await check('founder attributes from database',async()=>{await visit('/families/al-zoubi/tree?private=1&person='+fixture['ROOT-049']);await loaded();assert.match(await page.locator('[data-person-attributes]').innerText(),/الخلفاء/);await shot('attributes-desktop');});
 await check('repeated Arabic name search',async()=>{await page.locator('[data-tree-search]').fill('محمد');await page.locator('[data-tree-search-results] button').first().waitFor();assert.ok(await page.locator('[data-tree-search-results] button').count()>1);await shot('repeated-name-search');});
 for(const branch of ['al-qawasmeh','bakr','qawasmeh-samrin','eid','khamis','bakr-mahmoud','bakr-thalji','bakr-hamdan','ali-wahsh']) await check('private branch '+branch,async()=>{await visit('/families/al-zoubi/tree/branches/'+branch+'?private=1');await loaded();await shot('branch-'+branch);});
 await check('deep descendant profile',async()=>{await visit('/families/al-zoubi/people/'+fixture['QW-0031']+'?private=1');assert.match(await page.locator('body').innerText(),/مؤسس النظام/);await shot('private-person');});
 for(const url of ['/families/al-zoubi/entities','/families/al-zoubi/entities/al-qawasmeh?preview=1','/families/al-zoubi/entities/qawasmeh-samrin?preview=1','/manage/families/al-zoubi/dashboard','/manage/families/al-zoubi/roles','/site-admin/seed-audit','/manage/families/al-zoubi/sources/'+fixture.source]) await check(url,async()=>{await visit(url);await shot(url.includes('seed-audit')?'seed-audit':url.includes('sources')?'source-review':url.includes('roles')?'role-management':url.includes('dashboard')?'family-dashboard':url.includes('samrin')?'subbranch-preview':url.includes('?')?'fakhd-preview':'fakhd-directory');});
 await check('mobile tree and founder tap',async()=>{await page.setViewportSize({width:390,height:844});await visit('/families/al-zoubi/tree?private=1&person='+fixture['QW-0003']);await loaded();await page.locator('.tree-node[data-id="'+fixture['QW-0003']+'"]').click();assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await shot('private-tree-mobile');});
 await check('mobile portal',async()=>{await visit('/families/al-zoubi/portal');assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await shot('public-portal-mobile');});
 for(const user of ['familyadmin','editor','reader','demo_genealogy_manager','demo_genealogy_editor','demo_genealogy_reviewer','demo_content_manager','demo_news_editor','demo_news_publisher','demo_death_notice_reviewer','demo_directory_moderator','entity_admin','entity_editor']) await check('demo login '+user,async()=>{const c=await browser.newContext();const p=await c.newPage();await login(p,user);await c.close();});
 await check('reader cannot inspect raw unresolved sources',async()=>{const c=await browser.newContext();const p=await c.newPage();await login(p,'reader');const r=await p.goto(base+'/manage/families/al-zoubi/sources/'+fixture.source);assert.equal(r.status(),403);const d=await c.request.get(base+'/manage/families/al-zoubi/sources/'+fixture.source+'/download');assert.equal(d.status(),403);await c.close();});
 assert.deepEqual(errors,[]);fs.writeFileSync(path.join(output,'journeys.json'),JSON.stringify({checks,errors},null,2));await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
