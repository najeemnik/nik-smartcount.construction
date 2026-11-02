/* Recording-ready frames rendered from the offline training HTML; no live accounting actions. */
const {chromium}=require('/workspace/nsc-test/tools/node_modules/playwright-core');
const fs=require('fs'),assert=require('assert'),root='/workspace/releases/nik-training-2026-11-02';
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']}),ctx=await browser.newContext({viewport:{width:1920,height:1080},offline:true}),page=await ctx.newPage(),errors=[];
 page.on('pageerror',e=>errors.push(e.message));fs.mkdirSync(root+'/INTRO_90S_FRAMES',{recursive:true});
 try{
  await page.setContent(fs.readFileSync(root+'/NiK_Training_2026-11-02.html','utf8'),{waitUntil:'load'});
  await page.selectOption('#route','short');await page.locator('#video').click();
  await page.addStyleTag({content:'body.video .controls,body.video .videoExit,body.video .sub{visibility:hidden!important}'});
  for(let index=0;index<9;index++){
   await page.waitForFunction(()=>{const i=document.querySelector('#image .figure>img');return i&&i.complete&&i.naturalWidth>0});await page.waitForTimeout(120);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1),true);
   await page.screenshot({path:root+'/INTRO_90S_FRAMES/'+String(index+1).padStart(2,'0')+'.png'});
   if(index<8)await page.keyboard.press('ArrowLeft');
  }
  assert.deepEqual(errors,[]);console.log('PASS nine annotated 1920×1080 introduction frames exported offline');
 }finally{await browser.close()}
})().catch(e=>{console.error(e.message);process.exit(1)});
