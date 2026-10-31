// Tatsächlich gerenderte Modul-HTML, synthetische Daten. Kein Symcon-/Android-Clienttest.
const {chromium}=require('playwright');
const fs=require('fs');
const path=require('path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_BINARY||'/opt/brave.com/brave/brave',args:['--no-sandbox']});
 const page=await browser.newPage();let checks=0;
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const html=fs.readFileSync(path.join(__dirname,'../UgreenNAS/module.html'),'utf8');
 const original=JSON.parse(fs.readFileSync(path.join(__dirname,'preview-payload.json'),'utf8'));
 async function feed(p){await page.evaluate(p=>handleMessage(JSON.stringify(p)),p);}
 function check(ok,label){if(!ok)throw Error(label);checks++;console.log('OK '+label);}
 for(const width of [240,320,480,768,1200]){
  for(const theme of ['light','dark']){
   await page.setViewportSize({width,height:900});await page.goto('about:blank');await page.setContent(html);
   const p=structuredClone(original);p.options.theme=theme;await feed(p);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Kein horizontaler Überlauf '+width+' '+theme);
   check(await page.locator('#refresh').isVisible(),'Aktualisieren sofort sichtbar '+width+' '+theme);
   if(width===768)await page.screenshot({path:path.join(__dirname,'../docs/preview-'+theme+'.png'),fullPage:true});
   if(width===320&&theme==='dark')await page.screenshot({path:path.join(__dirname,'../docs/preview-mobile.png'),fullPage:true});
  }
 }
 let p=structuredClone(original);p.options.title='<img src=x onerror=alert(1)>';p.data.disks[0].name='SehrLangerLaufwerksname'.repeat(8);await feed(p);
 check(await page.locator('h1 img').count()===0,'Dynamische Texte werden nicht als HTML ausgeführt');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Lange Namen bleiben im Layout');
 await page.locator('summary').click();await feed(original);
 check(await page.locator('details').getAttribute('open')!==null,'Diagnose bleibt bei Live-Update geöffnet');
 p=structuredClone(original);p.fresh=false;await feed(p);
 check(await page.locator('#state').textContent()==='Daten veraltet','Veraltete Daten werden markiert');
 p=structuredClone(original);p.enabled=false;await feed(p);
 check(await page.locator('#refresh').isDisabled(),'Deaktivierte Instanz sperrt Aktualisieren');
 p=structuredClone(original);p.options.disks=false;p.options.pools=false;p.options.fans=false;await feed(p);
 check(await page.locator('#disksSection').isHidden()&&await page.locator('#poolsSection').isHidden(),'Ansichtsoptionen wirksam');
 p=structuredClone(original);p.data={};p.fresh=false;await feed(p);
 check(await page.locator('#cpu').textContent()==='—','Keine Daten ergeben keine Nullmessung');
 await feed(original);await page.evaluate(()=>{window.calls=[];window.requestAction=(id,value)=>window.calls.push([id,value]);});
 await page.locator('#refresh').click();
 check(await page.evaluate(()=>calls.length===1&&calls[0][0]==='Refresh'&&calls[0][1]===true),'Aktualisieren sendet korrekte SDK-Aktion');
 check(await page.locator('#refresh').isDisabled(),'Laufende Aktion sperrt Doppelklick');
 await feed(original);check(!(await page.locator('#refresh').isDisabled()),'Antwort gibt Schaltfläche frei');
 await page.clock.install();p=structuredClone(original);p.staleAfter=45;await feed(p);await page.clock.fastForward(45100);
 check(await page.locator('#state').textContent()==='Daten veraltet','Ausbleibende Updates altern ohne Polling-Timer');
 check(errors.length===0,'Keine Browser-JavaScriptfehler');
 await browser.close();console.log('Bestanden: '+checks+' Browserprüfungen');
})().catch(e=>{console.error(e);process.exit(1);});
