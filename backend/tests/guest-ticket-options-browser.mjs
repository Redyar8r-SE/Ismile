import {spawn,execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
const root=process.cwd(),folder=path.resolve('.local/sponsored-ticket-test');
const php=path.join(process.env.TEMP,'ismile-php-check/php.exe'),args=['-c',path.resolve('.local/vip-release-tests/provider-check.ini')];
const env={...process.env,ISMILE_CONFIG:path.join(folder,'config.php'),PSOOLA_API_BASE_URL:'http://127.0.0.1:8862/api/partner/v1',PSOOLA_API_KEY:'sponsored-local-test-key',PSOOLA_COMPLIMENTARY_ENABLED:'1'};
const base='http://127.0.0.1:8863';
const server=spawn(php,[...args,'-S','127.0.0.1:8863','-t',root,'.local/sponsored-ticket-router.php'],{env,windowsHide:true,stdio:'ignore'});
for(let i=0;i<50;i++){try{await fetch(base+'/admin/login.php');break;}catch{}await new Promise(r=>setTimeout(r,100));}
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0','--user-data-dir='+path.join(folder,'browser-'+Date.now())],{windowsHide:true,stdio:['ignore','ignore','pipe']});
let ws;
try {
 const endpoint=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timeout')),15000);chrome.stderr.on('data',d=>{log+=d;const m=log.match(/DevTools listening on (ws:\/\/\S+)/);if(m){clearTimeout(timer);resolve(m[1]);}});chrome.on('error',reject);});
 ws=new WebSocket(endpoint);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});
 let id=0,passed=0;const pending=new Map(),errors=[];
 ws.onmessage=({data})=>{const m=JSON.parse(data),p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);};
 const call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const n=++id;const timer=setTimeout(()=>reject(Error('Timeout: '+method)),20000);pending.set(n,{resolve:v=>{clearTimeout(timer);resolve(v);},reject:e=>{clearTimeout(timer);reject(e);}});ws.send(JSON.stringify({id:n,method,params,...(sessionId?{sessionId}:{})}));});
 const {targetId}=await call('Target.createTarget',{url:'about:blank'}),{sessionId}=await call('Target.attachToTarget',{targetId,flatten:true});
 await call('Page.enable',{},sessionId);await call('Runtime.enable',{},sessionId);
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true},sessionId);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async expression=>{for(let i=0;i<100;i++){try{if(await evaluate(expression))return;}catch{}await new Promise(r=>setTimeout(r,100));}console.log(await evaluate(`JSON.stringify({url:location.href,flashes:[...document.querySelectorAll('.flash')].map(e=>e.textContent),forms:[...document.forms].map(f=>({action:f.action,sending:f.dataset.sending,pending:f.dataset.guestPending,valid:f.checkValidity(),fields:[...f.elements].filter(e=>e.name).map(e=>[e.name,e.validity?.valid,e.disabled])}))})`));throw Error('Timed out: '+expression);};
 const check=(name,ok)=>{if(!ok)throw Error(name);passed++;console.log('PASS: '+name);};
 const navigate=async pathname=>{await call('Page.navigate',{url:base+pathname},sessionId);await until(`document.readyState==='complete' && !!document.querySelector('main .page-heading')`);};
 const screenshot=async name=>{const s=await call('Page.captureScreenshot',{format:'png'},sessionId);fs.writeFileSync(path.join(folder,name+'.png'),Buffer.from(s.data,'base64'));};
 const fixture=async suffix=>fetch('http://127.0.0.1:8862/fixture'+(suffix||'')).then(r=>r.json());
 const fill=async prefix=>evaluate(`(()=>{const f=document.querySelector('[data-guest-ticket-form]');const values={first_name:'Browser',father_name:'Free',grandfather_name:'Guest',specialty:'gp',email:${JSON.stringify(prefix+'-'+Date.now()+'@example.invalid')},phone:${JSON.stringify('+964750'+String(Math.floor(1000000+Math.random()*8999999)))},complimentary_reason:'Invited research team',sponsoring_company:'Browser Dental Company'};for(const [name,value] of Object.entries(values)){f.elements[name].value=value;f.elements[name].dispatchEvent(new Event('input',{bubbles:true}));}return f.checkValidity()})()`);
 const submit=async()=>{await evaluate(`document.querySelector('[data-guest-issue]').click()`);await until(`!!document.querySelector('dialog.confirm-box[open]')`);await evaluate(`document.querySelector('dialog.confirm-box .confirm-yes').click()`);};

 await navigate('/sponsored-test-login');
 await navigate('/admin/registration.php?new=free&options=1');
 await until(`document.querySelector('[name=event_id]').options.length>1`);
 check('guest form defaults to no WhatsApp consent',await evaluate(`!document.querySelector('[name=whatsapp_consent]').checked`));
 await evaluate(`(()=>{const f=document.querySelector('[data-guest-options]');f.elements.event_id.value='event-test';f.elements.event_id.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 await until(`document.querySelector('[name=show_id]').options.length>1`);
 await evaluate(`(()=>{const f=document.querySelector('[data-guest-options]');f.elements.show_id.value='show-test';f.elements.show_id.dispatchEvent(new Event('change',{bubbles:true}));f.elements.ticket_type_id.value='professional-test';f.elements.ticket_type_id.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 await until(`!!document.querySelector('[name="add_on_ids[]"]')`);
 check('only supported general-admission shows are selectable',await evaluate(`![...document.querySelector('[name=show_id]').options].some(o=>o.value==='cinema-test')`));
 await evaluate(`(()=>{const f=document.querySelector('[data-guest-options]');const values={buyer_name:'Options Browser Guest',email:'options-'+Date.now()+'@example.invalid',phone:'+964750'+Math.floor(1000000+Math.random()*8999999),complimentary_reason:'Invited research team',quantity:'2',locale:'kmr'};for(const [key,value] of Object.entries(values)) f.elements[key].value=value;f.querySelector('[name="add_on_ids[]"][value="lunch1-test"]').checked=true;f.dispatchEvent(new Event('input'));})()`);
 check('review includes guest, event, quantity and zero total',await evaluate(`document.querySelector('[data-guest-review]').textContent.includes('Options Browser Guest')&&document.querySelector('[data-guest-review]').textContent.includes('2 Ã—')&&document.querySelector('.guest-pass').textContent.includes('Total: 0 IQD')&&!document.querySelector('[data-guest-issue]').disabled`));
 const refreshBefore=await fixture();
 await evaluate(`document.querySelector('[data-guest-refresh]').click()`);await until(`!document.querySelector('[data-guest-issue]').disabled`);
 check('availability refresh preserves selection and creates no order',(await fixture()).posts===refreshBefore.posts&&await evaluate(`document.querySelector('[name=ticket_type_id]').value==='professional-test'&&document.querySelector('[name="add_on_ids[]"][value="lunch1-test"]').checked`));
 const before=await fixture();await submit();await until(`!!document.querySelector('[data-guest-order]')`);
 const after=await fixture(), request=after.sessions.at(-1).request;
 check('selectable form issues one complimentary order',after.creations===before.creations+1&&request.issuanceType==='complimentary'&&request.items[0].quantity===2&&request.items[0].addOnIds[0]==='lunch1-test');
 check('ticket language and optional consent reach API correctly',request.locale==='kmr'&&!request.buyer.whatsappConsent);
 check('no payment fields or prices are submitted',!['returnUrl','total','price','payment'].some(key=>key in request));
 check('issued page has ticket actions and separate delivery status',await evaluate(`!!document.querySelector('[data-guest-ticket-link=view]')&&!!document.querySelector('[data-guest-email-status]')&&document.querySelector('[data-guest-order]').textContent.includes('Total: 0 IQD')`));
 for(const width of [1440,390]){
  await call('Emulation.setDeviceMetricsOverride',{width,height:1150,deviceScaleFactor:1,mobile:width<600},sessionId);
  await navigate('/admin/registration.php?new=free&options=1&ui=ckb');
  check('Sorani form preserves RTL at '+width,await evaluate(`document.querySelector('[data-guest-tickets]').dir==='rtl'&&document.querySelector('[data-guest-tickets]').textContent.includes('Ù¾Ø³ÙˆÙˆÚµÛ•')&&document.documentElement.scrollWidth<=innerWidth+1`));
 }
 await navigate('/sponsored-test-login?role=registration');
 const denied=await evaluate(`fetch('/admin/registration.php?new=free&options=1&do=catalogue').then(r=>r.status)`);
 check('catalogue endpoint requires staff issuance permission',denied===403);
 check('no browser runtime exceptions',errors.length===0);
 console.log(passed+' guest ticket option browser checks passed.');
} finally {ws?.close();chrome.kill();server.kill();}
