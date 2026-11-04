const { chromium }=require('playwright');const fs=require('fs');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_BIN || undefined,headless:true,args:['--no-sandbox']});
 const results=[];fs.mkdirSync(process.env.VISUAL_DIR || 'test-results/visuals',{recursive:true});
 for(const [label,port] of [['before',process.env.LEGACY_PORT || 8001],['after',process.env.APP_PORT || 8000]])for(const size of [{width:1440,height:1000},{width:390,height:844}]){
  const context=await browser.newContext({viewport:size,timezoneId:'UTC'});const page=await context.newPage();page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(20000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(`http://127.0.0.1:${port}/login_student.php`);await page.waitForTimeout(700);await page.screenshot({path:`${process.env.VISUAL_DIR || 'test-results/visuals'}/${label}-login-${size.width}.png`,fullPage:true});
  await page.fill('[name=std_no]','2026000001');await page.fill('[name=password]','Student@123');await Promise.all([page.waitForURL('**/student_dashboard.php'),page.click('button[type=submit]')]);await page.waitForTimeout(700);
  await page.screenshot({path:`${process.env.VISUAL_DIR || 'test-results/visuals'}/${label}-student-${size.width}.png`,fullPage:true});
  results.push({label,width:size.width,page:'student',url:page.url(),horizontalOverflow:await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth),header:await page.locator('#m_header').boundingBox(),sidebar:await page.locator('#m_aside_left').boundingBox(),main:await page.locator('main').boundingBox(),errors});
  await page.goto(`http://127.0.0.1:${port}/login_staff.php`);await page.fill('[name=staff_id]','GRD001');await page.fill('[name=password]','Staff@123');await Promise.all([page.waitForURL(/\/(dashboard|gate)\.php$/),page.click('button[type=submit]')]);
  await page.goto(`http://127.0.0.1:${port}/gate.php?std_no=2026000001`);await page.waitForTimeout(500);await page.screenshot({path:`${process.env.VISUAL_DIR || 'test-results/visuals'}/${label}-gate-${size.width}.png`,fullPage:true});results.push({label,width:size.width,page:'gate',horizontalOverflow:await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth),errors});
  await context.close();
 }
 fs.writeFileSync(`${process.env.VISUAL_DIR || 'test-results/visuals'}/results.json`,JSON.stringify(results,null,2));console.log(JSON.stringify(results,null,2));await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
