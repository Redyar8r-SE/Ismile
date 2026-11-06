// Local-only PSOOLA complimentary simulator. No external calls or messages.
import http from 'node:http';
const sessions=new Map(),keys=new Map(),bodies=new Map(),calls=new Map(),buyers=new Map();
const runId=Date.now().toString(36);let posts=0,creations=0,reads=0,catalogueReads=0,unsafePosts=0,checkoutPosts=0,permission=true;
const events=[{id:'event-test',name:{en:'iSmile Test Event',ar:'?????? ???????',ckb:'????? ???????????'},currency:'IQD',shows:[
 {id:'show-test',name:{en:'General admission',ar:'?????? ?????',ckb:'????????????? ????'},maxPerOrder:100,ticketTypes:[
  {id:'professional-test',name:{en:'Dentist',ckb:'??????? ?????? ????'},price:40000,available:500,addOns:[{id:'lunch1-test',name:{en:'Lunch day 1'},price:7500,available:500},{id:'lunch2-test',name:{en:'Lunch day 2'},price:8500,available:500}]},
  {id:'student-test',name:{en:'Student'},price:30000,available:500,addOns:[]},
  {id:'limited-test',name:{en:'Limited stock'},price:12345,available:1,addOns:[]},
  {id:'closed-test',name:{en:'Closed'},price:10000,available:100,salesOpen:false,addOns:[]}]},
 {id:'cinema-test',type:'cinema',name:{en:'Unsupported cinema'},ticketTypes:[{id:'cinema-ticket',price:10000,available:10}]}]},
 {id:'other-event',name:{en:'Other General Admission Event'},currency:'IQD',shows:[{id:'other-show',name:{en:'Other show'},ticketTypes:[{id:'other-ticket',name:{en:'Other ticket'},price:17000,available:500,addOns:[]}]}]}];
http.createServer(async(req,res)=>{
 const url=new URL(req.url,'http://127.0.0.1:8862');
 const reply=(status,body,headers={})=>{res.writeHead(status,{'Content-Type':'application/json',...headers});res.end(JSON.stringify(body));};
 const error=(status,code,headers={})=>reply(status,{error:{code,message:'Fixture '+code},requestId:'support-'+code},headers);
 if(url.pathname==='/fixture')return reply(200,{posts,creations,reads,catalogueReads,unsafePosts,checkoutPosts,sessions:[...sessions.values()]});
 if(url.pathname==='/fixture/permission'){permission=url.searchParams.get('enabled')==='1';return reply(200,{ok:true});}
 if(url.pathname==='/fixture/delivery'){const s=sessions.get(url.searchParams.get('id'));if(s){s.emailStatus='delivered';s.whatsappStatus='delivered';}return reply(200,{ok:!!s});}
 if(url.pathname==='/fixture/tamper'){const s=sessions.get(url.searchParams.get('id'));if(s)s.order.totalAmount=40000;return reply(200,{ok:!!s});}
 if(req.headers.authorization!=='Bearer sponsored-local-test-key')return error(401,'UNAUTHORIZED');
 if(req.method==='GET'&&url.pathname==='/api/partner/v1/events'){catalogueReads++;return reply(200,{events});}
 const eventId=url.pathname.match(/^\/api\/partner\/v1\/events\/([^/]+)$/)?.[1];
 if(req.method==='GET'&&eventId){catalogueReads++;const event=events.find(e=>e.id===eventId);return event?reply(200,{event}):error(403,'EVENT_NOT_ENABLED');}
 if(req.method==='POST'&&url.pathname.endsWith('/checkout-sessions')){checkoutPosts++;return error(500,'CHECKOUT_FORBIDDEN');}
 if(req.method==='POST'&&url.pathname==='/api/partner/v1/orders'){
  posts++;let raw='';for await(const data of req)raw+=data;
  const body=JSON.parse(raw),key=req.headers['idempotency-key'];
  if(body.issuanceType!=='complimentary'){unsafePosts++;return error(400,'PAID_REQUEST_FORBIDDEN');}
  if(!key||key.length>64||body.returnUrl||body.amount!==undefined||body.total!==undefined||body.payment!==undefined||body.price!==undefined)return error(400,'INVALID_REQUEST');
  if(bodies.has(key)&&bodies.get(key)!==raw)return error(409,'IDEMPOTENCY_CONFLICT');
  bodies.set(key,raw);calls.set(key,(calls.get(key)||0)+1);
  if(keys.has(key))return reply(200,keys.get(key));
  if(!permission)return error(403,'COMPLIMENTARY_ISSUE_NOT_ENABLED');
  const event=events.find(e=>e.id===body.eventId),show=event?.shows.find(s=>s.id===body.showId);
  if(!event)return error(403,'EVENT_NOT_ENABLED');if(!show||show.type==='cinema')return error(422,'UNSUPPORTED_SHOW');
  if(!body.complimentaryReason?.trim()||body.complimentaryReason.length>500)return error(400,'INVALID_REQUEST');
  const email=body.buyer?.email||'';
  const forced=[['inventory-',409,'INSUFFICIENT_INVENTORY'],['limit-',422,'LIMIT_EXCEEDED'],['closed-',409,'SALES_CLOSED'],['addon-',422,'ADD_ON_NOT_AVAILABLE'],['unavailable-',422,'TICKET_TYPE_NOT_AVAILABLE'],['unsupported-',422,'UNSUPPORTED_SHOW'],['invalid-',400,'INVALID_REQUEST'],['unauthorized-',401,'UNAUTHORIZED'],['conflict-',409,'IDEMPOTENCY_CONFLICT']].find(([prefix])=>email.startsWith(prefix));
  if(forced)return error(forced[1],forced[2]);
  if(email.startsWith('rate-')&&calls.get(key)===1)return error(429,'RATE_LIMITED',{'Retry-After':'2'});
  if(email.startsWith('transient-')&&calls.get(key)===1)return error(503,'INTERNAL_ERROR');
  if(email.startsWith('network-'))return error(503,'INTERNAL_ERROR');
  let quantity=0,discount=0;const ordered=[];
  for(const item of body.items){
   const type=show.ticketTypes.find(t=>t.id===item.ticketTypeId);if(!type)return error(422,'TICKET_TYPE_NOT_AVAILABLE');
   if(!Number.isInteger(item.quantity)||item.quantity<1||item.quantity>100)return error(400,'INVALID_REQUEST');
   if(item.quantity>type.available)return error(409,'INSUFFICIENT_INVENTORY');
   const addOns=[];
   for(const id of item.addOnIds||[]){const add=type.addOns.find(a=>a.id===id);if(!add)return error(422,'ADD_ON_NOT_AVAILABLE');if(add.available<item.quantity)return error(409,'INSUFFICIENT_INVENTORY');addOns.push({addOnId:id,quantity:item.quantity,unitPrice:add.price});discount+=add.price*item.quantity;}
   quantity+=item.quantity;discount+=type.price*item.quantity;ordered.push({ticketTypeId:type.id,quantity:item.quantity,unitPrice:type.price,addOns});
  }
  const buyerKey=body.buyer.phone+'|'+event.id;if((buyers.get(buyerKey)||0)+quantity>10)return error(422,'LIMIT_EXCEEDED');
  const session={id:'guest_'+runId+'_'+(++creations),status:'paid',channel:'direct',isComplimentary:true,complimentaryReason:body.complimentaryReason,
   partnerReference:body.partnerReference,eventId:body.eventId,showId:body.showId,checkoutUrl:null,
   order:{totalAmount:0,discountAmount:discount,currency:'IQD',ticketCount:quantity,items:ordered},
   emailStatus:email.startsWith('mailfail-')?'failed':'queued',whatsappStatus:body.buyer.whatsappConsent?'pending':null,
   ticketsUrl:'https://psoola.com/t/private-'+runId+'-'+creations,metadata:{ticketsUrl:'https://psoola.com/t/nested-private'},request:body};
  if(email.startsWith('positive-'))session.order.totalAmount=40000;
  if(email.startsWith('flag-'))session.isComplimentary=false;
  if(email.startsWith('reason-'))session.complimentaryReason='Different reason';
  if(email.startsWith('wrong-'))session.partnerReference='wrong-reference';
  if(email.startsWith('count-'))session.order.ticketCount++;
  if(email.startsWith('items-'))session.order.items=[{ticketTypeId:'wrong-ticket',quantity}];
  if(email.startsWith('waiting-'))session.status='awaiting_payment';
  sessions.set(session.id,session);keys.set(key,session);buyers.set(buyerKey,(buyers.get(buyerKey)||0)+quantity);
  for(const item of body.items){const type=show.ticketTypes.find(t=>t.id===item.ticketTypeId);type.available-=item.quantity;for(const id of item.addOnIds||[])type.addOns.find(a=>a.id===id).available-=item.quantity;}
  if(email.startsWith('lost-')||email.startsWith('webhook-'))return error(503,'INTERNAL_ERROR');
  return reply(201,session);
 }
 if(req.method==='GET'&&url.pathname==='/api/partner/v1/orders'){
  reads++;const ref=url.searchParams.get('partnerReference');if(ref){const s=[...sessions.values()].find(s=>s.request.partnerReference===ref);return s?reply(200,s):error(404,'NOT_FOUND');}
  return reply(200,{orders:[...sessions.values()].filter(s=>!url.searchParams.get('eventId')||s.eventId===url.searchParams.get('eventId')),nextCursor:null});
 }
 const id=url.pathname.match(/^\/api\/partner\/v1\/orders\/([^/]+)$/)?.[1];
 if(req.method==='GET'&&id){reads++;return sessions.has(id)?reply(200,sessions.get(id)):error(404,'NOT_FOUND');}
 return error(404,'NOT_FOUND');
}).listen(8862,'127.0.0.1',()=>console.log('Local complimentary PSOOLA simulator ready on 8862.'));
