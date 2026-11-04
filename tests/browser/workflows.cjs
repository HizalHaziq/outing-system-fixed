const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const base = process.env.BASE_URL || 'http://127.0.0.1:8000';
  assert.equal(process.env.OUTING_BROWSER_DEMO, '1', 'Use an isolated DemoSeeder installation and set OUTING_BROWSER_DEMO=1.');
  const browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || undefined, args: ['--no-sandbox'] });
  const errors = [];
  const create = async (kind, id, password) => {
    const context = await browser.newContext({viewport:{width:390,height:844},timezoneId:'UTC'});
    const page = await context.newPage(); page.setDefaultTimeout(15000);
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${base}/login_${kind}.php`);
    await page.fill(`[name=${kind === 'student' ? 'std_no' : 'staff_id'}]`,id);
    await page.fill('[name=password]',password);
    await Promise.all([page.waitForURL(/\/(student_dashboard|gate|dashboard|change_password)\.php$/),page.click('button[type=submit]')]);
    return {context,page};
  };
  try {
    const student = await create('student','2026000001','Student@123');
    const guard = await create('staff','GRD001','Staff@123');
    assert.ok(guard.page.url().endsWith('/gate.php'));
    await student.page.goto(`${base}/my_qr.php`);
    await student.page.locator('#qrcode canvas, #qrcode img').first().waitFor({state:'attached'});
    const scan = async kind => {
      const token = await (await student.context.request.get(`${base}/qr_token.php?kind=${kind}`)).json();
      assert.ok(token.token.startsWith(`OQ1|${kind}|`));
      await guard.page.goto(`${base}/gate.php`);
      await guard.page.locator('[name=qr_token]').evaluate((input,value)=>{input.value=value},token.token);
      await Promise.all([guard.page.waitForURL(kind==='G'?'**/gate_group.php?group_id=*':'**/gate.php?std_no=*'),guard.page.locator('#scan-form').evaluate(f=>f.submit())]);
    };
    await scan('S');
    assert.match(await guard.page.locator('main').innerText(),/Live QR verified/);
    guard.page.on('dialog',d=>d.accept());
    await guard.page.check('[name=identity_confirmed]');
    await Promise.all([guard.page.waitForURL('**/gate.php'),guard.page.getByRole('button',{name:'Check out',exact:true}).click()]);
    assert.match(await guard.page.locator('main').innerText(),/Check out \(standard\)/);
    await guard.page.goto(`${base}/gate.php?std_no=2026000001`);
    await guard.page.check('[name=identity_confirmed]');
    await Promise.all([guard.page.waitForURL('**/gate.php'),guard.page.getByRole('button',{name:'Check in',exact:true}).click()]);
    console.log('PASS: browser student QR issuance, guard verification, identity confirmation, standard checkout and return.');
    await guard.page.click('#navToggle');assert.equal(await guard.page.locator('#navToggle').getAttribute('aria-expanded'),'true');
    await guard.page.keyboard.press('Escape');assert.equal(await guard.page.locator('#navToggle').getAttribute('aria-expanded'),'false');
    await student.page.goto(`${base}/my_group.php`);
    await student.page.getByRole('button',{name:'Start a group (I am the lead)',exact:true}).click();
    await student.page.fill('[name=member_std_no]','2026000003');await student.page.getByRole('button',{name:'Invite',exact:true}).click();
    const member = await create('student','2026000003','Student@123');await member.page.goto(`${base}/my_group.php`);await member.page.getByRole('button',{name:'Accept',exact:true}).click();
    await scan('G');
    assert.equal(await guard.page.locator('[name="members[]"]:checked').count(),2);
    await guard.page.check('[name=identity_confirmed]');await guard.page.getByRole('button',{name:'Process selected',exact:true}).click();await guard.page.waitForURL('**/gate.php');
    assert.match(await guard.page.locator('main').innerText(),/2 processed/);
    for(const id of ['2026000001','2026000003']){
      await guard.page.goto(`${base}/gate.php?std_no=${id}`);await guard.page.check('[name=identity_confirmed]');await guard.page.getByRole('button',{name:'Check in',exact:true}).click();await guard.page.waitForURL('**/gate.php');
    }
    await student.page.goto(`${base}/my_group.php`);await student.page.getByRole('button',{name:'Start a group (I am the lead)',exact:true}).waitFor();
    console.log('PASS: browser group invitation, acceptance, Master QR, batch departure, individual returns and automatic closure.');
    const forced = await create('student','2026000002','Student@123');assert.ok(forced.page.url().endsWith('/change_password.php'));
    const csrf = await guard.context.request.post(`${base}/gate_action.php`,{form:{action:'standard_check_out',std_no:'2026000001'}});assert.equal(csrf.status(),419);
    console.log('PASS: forced password redirect, mobile menu and CSRF rejection without a token.');
    assert.deepEqual(errors,[]);
    console.log('PASS: no browser JavaScript errors.');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exit(1)});
