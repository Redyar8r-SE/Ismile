// Local provider stand-in. Never contacts PSOOLA or sends messages.
import http from 'node:http';
const sessions = new Map(), keys = new Map();
const runId = Date.now().toString(36);
let posts = 0, creations = 0, reads = 0;
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1:8862');
  const reply = (code, body) => { res.writeHead(code, {'Content-Type':'application/json'}); res.end(JSON.stringify(body)); };
  if (url.pathname === '/fixture') return reply(200,{posts,creations,reads,sessions:[...sessions.values()]});
  if (url.pathname === '/fixture/tamper') {
    const session = sessions.get(url.searchParams.get('id'));
    if (session) session.order.items = [{ticketTypeId:'wrong-ticket',quantity:1}];
    return reply(200,{ok:!!session});
  }
  if (url.pathname === '/fixture/delivery') {
    const session = sessions.get(url.searchParams.get('id'));
    if (session) { session.emailStatus='sent'; session.whatsappStatus='delivered'; }
    return reply(200,{ok:!!session});
  }
  if (req.headers.authorization !== 'Bearer sponsored-local-test-key') return reply(401,{error:{code:'UNAUTHORIZED'}});
  if (req.method === 'POST' && url.pathname === '/api/partner/v1/orders') {
    posts++;
    let raw = ''; for await (const data of req) raw += data;
    const body = JSON.parse(raw), key = req.headers['idempotency-key'];
    if (!key || key.length > 64 || body.returnUrl || body.amount !== undefined || body.status !== undefined) return reply(400,{error:{code:'INVALID_REQUEST'}});
    if (keys.has(key)) {
      const saved = keys.get(key);
      if (saved.raw !== raw) return reply(409,{error:{code:'IDEMPOTENCY_CONFLICT'}});
      return reply(200,saved.session);
    }
    if (body.buyer.email.startsWith('rejected-')) return reply(422,{error:{code:'LIMIT_EXCEEDED'}});
    const item = body.items[0], amount = item.ticketTypeId === 'student-test' ? 20000 : 30000;
    const session = {id:'sponsored_'+runId+'_'+(++creations),status:'paid',channel:'direct',partnerReference:body.partnerReference,
      order:{totalAmount:amount,currency:'IQD',items:body.items},emailStatus:'queued',
      whatsappStatus:body.buyer.whatsappConsent ? 'pending' : null,ticketsUrl:'https://psoola.invalid/t/private-token',request:body};
    sessions.set(session.id,session); keys.set(key,{raw,session});
    if (body.buyer.email.startsWith('lost-') || body.buyer.email.startsWith('webhook-')) return reply(503,{error:{code:'SIMULATED_LOST_RESPONSE'}});
    if (body.buyer.email.startsWith('wrong-')) return reply(201,{...session,partnerReference:'wrong-reference'});
    if (body.buyer.email.startsWith('items-')) return reply(201,{...session,order:{...session.order,items:[{ticketTypeId:'wrong',quantity:1}]}});
    if (body.buyer.email.startsWith('waiting-')) return reply(201,{...session,status:'awaiting_payment'});
    return reply(201,session);
  }
  const id = url.pathname.match(/^\/api\/partner\/v1\/orders\/([^/]+)$/)?.[1];
  if (req.method === 'GET' && id) { reads++; return reply(sessions.has(id)?200:404,sessions.get(id)||{}); }
  return reply(404,{});
});
server.listen(8862,'127.0.0.1',()=>console.log('Local sponsored-ticket provider ready on 8862.'));
