// A reproducible local read-load smoke test; E2E_URL may target an isolated staging environment.
import { performance } from 'node:perf_hooks';
const base=(process.env.E2E_URL||'http://localhost:8080')+'/api/v1';
async function request(path,body){const r=await fetch(base+path,{method:body?'POST':'GET',headers:{'Content-Type':'application/json',...(globalThis.token?{Authorization:'Bearer '+globalThis.token}:{})},body:body?JSON.stringify(body):undefined});if(!r.ok)throw Error(`${path}: HTTP ${r.status}`);return r.json();}
globalThis.token=(await request('/auth/login',{email:'admin@clinicflow.test',password:'ClinicFlow!2026'})).data.access_token;
const clinic=(await request('/me')).clinics[0].id;
const doctor=(await request(`/clinics/${clinic}/doctors`)).data[0].id;
const service=(await request(`/clinics/${clinic}/doctors/${doctor}`)).data.service_ids[0];
const day=new Date(Date.now()+20*86400000).toISOString().slice(0,10);
let next=0;const durations=[];const errors=[];
const started=performance.now();
await Promise.all(Array.from({length:20},async()=>{while(next++<100){const t=performance.now();try{await request(`/clinics/${clinic}/availability?doctor_id=${doctor}&service_id=${service}&date=${day}`);durations.push(performance.now()-t);}catch(e){errors.push(e.message);}}}));
durations.sort((a,b)=>a-b);
const report={requests:durations.length+errors.length,concurrency:20,elapsed_ms:Math.round(performance.now()-started),p95_ms:Math.round(durations[Math.ceil(durations.length*.95)-1]||0),errors};
console.log(JSON.stringify(report,null,2));if(errors.length||report.p95_ms>2000)process.exitCode=1;
