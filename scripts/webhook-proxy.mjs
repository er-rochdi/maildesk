import http from 'node:http';

// The development tunnel reaches only this endpoint, never the rest of Maildesk.
const server = http.createServer((request, response) => {
  const reply = (status, data) => {
    response.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    response.end(JSON.stringify(data));
  };
  if (request.url === '/health' && request.method === 'GET') return reply(200, { service: 'maildesk-webhook' });
  if (request.url !== '/webhooks/resend') return reply(404, { message: 'Not found' });
  if (request.method !== 'POST') return reply(405, { message: 'POST required' });
  let size = 0;
  const chunks = [];
  request.on('data', chunk => {
    size += chunk.length;
    if (size <= 65536) chunks.push(chunk);
  });
  request.on('end', () => {
    if (size > 65536) return reply(413, { message: 'Payload too large' });
    const headers = { Host: 'maildesk.test', Accept: 'application/json', 'Content-Type': 'application/json' };
    for (const name of ['svix-id', 'svix-timestamp', 'svix-signature']) {
      if (request.headers[name]) headers[name] = request.headers[name];
    }
    const upstream = http.request({ hostname: '127.0.0.1', port: 80, path: '/webhooks/resend', method: 'POST', headers, timeout: 10000 }, result => {
      const body = [];
      let bytes = 0;
      result.on('data', chunk => { bytes += chunk.length; if (bytes <= 16384) body.push(chunk); });
      result.on('end', () => {
        let data;
        try { data = JSON.parse(Buffer.concat(body).toString()); } catch { data = {}; }
        if (result.statusCode === 200 && data.received === true) {
          reply(200, { received: true, matched: data.matched === true });
        } else {
          // Do not expose Laravel debug pages or transport errors through the tunnel.
          reply(result.statusCode >= 400 ? result.statusCode : 502, { message: 'Webhook request rejected' });
        }
      });
    });
    upstream.on('timeout', () => upstream.destroy());
    upstream.on('error', () => { if (!response.headersSent) reply(502, { message: 'Webhook unavailable' }); });
    upstream.end(Buffer.concat(chunks));
  });
  request.on('error', () => { if (!response.headersSent) reply(400, { message: 'Invalid request' }); });
});
server.requestTimeout = 15000;
server.headersTimeout = 10000;
server.listen(Number(process.env.WEBHOOK_PROXY_PORT || 8789), '127.0.0.1', () => {
  console.log(`Webhook proxy: http://127.0.0.1:${server.address().port}/webhooks/resend`);
});
