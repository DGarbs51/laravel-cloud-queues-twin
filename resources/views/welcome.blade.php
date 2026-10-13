<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Laravel queue twin</title>
<style>body{font:16px system-ui;background:#101827;color:#e2e8f0;max-width:55rem;margin:3rem auto;padding:0 1rem}button{font:inherit;padding:.7rem 1rem;cursor:pointer}button:focus-visible{outline:3px solid #38bdf8;outline-offset:3px}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#1e293b;padding:1rem;border-radius:.5rem}</style></head>
<body><h1>Laravel queue twin</h1><p>Run the seven queue checks. Timeout checks may take up to four minutes.</p><button id="run" type="button">Run queue checks</button><p id="status" role="status">Ready</p><pre id="stats" aria-label="Queue statistics">Loading…</pre>
<script>
const button=document.getElementById('run'), status=document.getElementById('status'), stats=document.getElementById('stats');
async function poll(){try{const res=await fetch('/api/stats');const data=await res.json();if(!res.ok)throw new Error(data.error);stats.textContent=JSON.stringify(data,null,2);status.textContent=data.check?`Queue check: ${data.check.status}`:'Ready';}catch(err){status.textContent=err.message;}}
button.addEventListener('click',async()=>{button.disabled=true;try{const res=await fetch('/api/check',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});const data=await res.json();status.textContent=res.ok?'Check started':data.error;await poll();}catch(err){status.textContent=err.message;}finally{button.disabled=false;}});
poll();setInterval(poll,2000);
</script></body></html>
