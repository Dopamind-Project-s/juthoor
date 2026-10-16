const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = process.env.BROWSER_BASE_URL || 'http://127.0.0.1:8000';
const output = process.env.BROWSER_ARTIFACTS || '/tmp/juthoor-admin-browser';
fs.mkdirSync(output, {recursive:true});
(async () => {
 const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
 const page = await browser.newPage({viewport:{width:1440,height:1000}});
 const errors=[]; page.on('pageerror',error=>errors.push(error.message));
 await page.goto(base+'/login');
 await page.locator('[name=email]').fill('superadmin');
 await page.locator('[name=password]').fill('password');
 await Promise.all([page.waitForURL('**/site-admin'),page.locator('button').filter({hasText:'دخول'}).click()]);
 assert.equal(await page.locator('.alert-danger').count(),0);
 for(const resource of ['families','trees','users','memberships','invitations','audit']) {
   const response=await page.goto(base+'/site-admin/'+resource);
   assert.equal(response.status(),200,resource);
   if(resource!=='audit') {
    await page.getByRole('link',{name:'إضافة سجل',exact:true}).click();
    assert.equal(await page.locator('h1').count(),1);
   }
 }
 await page.goto(base+'/site-admin');
 await page.screenshot({path:output+'/super-admin-desktop.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 await page.reload();
 await page.locator('[data-sidebar-toggle]').click();
 assert.equal(await page.locator('[data-sidebar-toggle]').getAttribute('aria-expanded'),'true');
 await page.keyboard.press('Escape');
 assert.equal(await page.locator('[data-sidebar-toggle]').getAttribute('aria-expanded'),'false');
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1));
 await page.screenshot({path:output+'/super-admin-mobile.png',fullPage:true});
 await page.goto(base+'/site-admin/users');
 await page.getByRole('link',{name:'إدارة وتعديل',exact:true}).first().click();
 const beforeCancel = page.url();
 page.once('dialog',dialog=>dialog.dismiss());
 await page.getByRole('button',{name:'حذف المستخدم',exact:true}).click();
 assert.equal(page.url(),beforeCancel);
 assert.equal(await page.locator('form[data-pending="true"]').count(),0);
 await page.locator('form[action$="/logout"] button').click();
 await page.locator('[name=email]').fill('familyadmin');
 await page.locator('[name=password]').fill('password');
 await Promise.all([page.waitForURL('**/manage/families'),page.locator('button').filter({hasText:'دخول'}).click()]);
 const familyLink = page.locator('main a[href*="/manage/families/"]').first();
 const familyUrl = await familyLink.getAttribute('href');
 await page.goto(familyUrl);
 for(const section of ['people','relationships','branches','trees','members','invitations','audit']) {
   const response=await page.goto(familyUrl+'/pages/'+section);
   assert.equal(response.status(),200,section);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1),section+' mobile overflow');
 }
 await page.goto(familyUrl+'/records/people/create');
 await page.locator('[name=first_name_ar]').fill('اختبار');
 await page.locator('[name=full_name_ar]').fill('اختبار الواجهة');
 assert.equal(await page.locator('[name=first_name_ar]').inputValue(),'اختبار');
 await page.screenshot({path:output+'/family-person-form.png',fullPage:true});
 await page.goto(familyUrl);
 await page.screenshot({path:output+'/family-admin-mobile.png',fullPage:true});
 // Intercept a native named-button submission; shared loading feedback must preserve its value.
 await page.locator('form[action$="/logout"] button').evaluate(button=>{button.name='decision';button.value='accepted';});
 let submitted;
 await page.route('**/logout',async route=>{submitted=route.request().postData();await route.fulfill({status:200,contentType:'text/html',body:'<p>Submission captured</p>'});});
 await Promise.all([page.waitForURL('**/logout'),page.locator('form[action$="/logout"] button').click()]);
 assert.ok(new URLSearchParams(submitted).get('decision')==='accepted');
 assert.deepEqual(errors,[]);
 console.log(JSON.stringify({result:'passed',checks:['MySQL superadmin/password login','Super Admin resource pages','Family Admin login and sections','responsive sidebar','mobile overflow','person form inputs','destructive confirmation cancellation','named submit button value preserved','no browser JavaScript errors'],familyUrl}));
 await browser.close();
})().catch(error=>{console.error(error);process.exit(1)});
