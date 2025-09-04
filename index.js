//https://chatgpt.com/share/68ba1035-9414-800c-96e3-4a1e52646243
import http from 'http';
import { Php, Request } from '@platformatic/php-node';

const php = new Php({ docroot: './' });

const server = http.createServer(async (req, res) => {
  const headers = {};
  for (const [k, v] of Object.entries(req.headers)) {
    headers[k] = Array.isArray(v) ? v : [v];
  }

  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  const body = chunks.length ? Buffer.concat(chunks) : undefined;

  const phpReq = new Request({
    method: req.method || 'GET',
    url: `http://localhost${req.url}`,
    headers,
    body
  });

  try {
    const phpRes = await php.handleRequest(phpReq);
    phpRes.headers.forEach((vals, key) => res.setHeader(key, vals));
    res.writeHead(phpRes.status);
    res.end(phpRes.body);
  } catch (err) {
    console.error(err);
    res.writeHead(500, { 'Content-Type': 'text/plain' });
    res.end('Server error running PHP');
  }
});

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
  console.log(`http://localhost:${PORT}`);
});
