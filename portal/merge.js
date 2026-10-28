/** Merge disjoint record changes; never guess when the same record changed twice. */
export const equal=(a,b)=>stable(a)===stable(b);
function stable(v){if(Array.isArray(v))return '['+v.map(stable).join(',')+']';if(v&&typeof v==='object')return '{'+Object.keys(v).sort().map(k=>JSON.stringify(k)+':'+stable(v[k])).join(',')+'}';return JSON.stringify(v);}
export function merge(base,local,remote){
 if(equal(local,base))return remote;
 if(equal(remote,base)||equal(local,remote))return local;
 if(Array.isArray(base)&&Array.isArray(local)&&Array.isArray(remote)){
  const indexed=rows=>{const m=new Map();for(const r of rows){const id=r?.id??r?.code;if(id===undefined||m.has(String(id)))throw Error('conflict');m.set(String(id),r);}return m;};
  const b=indexed(base),l=indexed(local),r=indexed(remote),result=[];
  for(const id of new Set([...r.keys(),...l.keys(),...b.keys()])){const value=merge(b.get(id),l.get(id),r.get(id));if(value!==undefined)result.push(value);}return result;
 }
 if(base&&local&&remote&&!Array.isArray(base)&&!Array.isArray(local)&&!Array.isArray(remote)&&[base,local,remote].every(v=>typeof v==='object')){
  const result={};for(const k of new Set([...Object.keys(base),...Object.keys(remote),...Object.keys(local)])){const v=merge(base[k],local[k],remote[k]);if(v!==undefined)result[k]=v;}return result;
 }
 throw Error('conflict');
}
