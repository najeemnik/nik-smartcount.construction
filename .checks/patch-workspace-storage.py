from pathlib import Path
p=Path(__file__).resolve().parent.parent/'workspace.php';s=p.read_text()
def once(a,b):
 global s
 assert s.count(a)==1,a[:100]
 s=s.replace(a,b)
once('<link rel="stylesheet" href="/experience.css?v=2">','<link rel="stylesheet" href="/experience.css?v=2"><link rel="stylesheet" href="/forms-ui.css?v=1">')
once('<script src="/workspace-save.js?v=1" defer></script>','<script src="/workspace-save.js?v=1" defer></script><script src="/workspace-cache.js?v=1" defer></script><script src="/forms-camera.js?v=1" defer></script><script src="/forms-ui.js?v=1" defer></script>')
once('/nsc-runtime.php?asset=accounting&v=currency-20261106-1','/nsc-runtime.php?asset=accounting&v=refined-20261106-1')
once('function j(e,n){let a=JSON.stringify(n);J.call(localStorage,q(e),a),z[e]=a}', 'function j(e,n){let a=JSON.stringify(n);z[e]=a;try{J.call(localStorage,q(e),a)}catch(error){if(error.name!=="QuotaExceededError"&&error.name!=="NS_ERROR_DOM_QUOTA_REACHED")throw error}}')
a=s.index('function S(){');b=s.index('function ee(){',a)
s=s[:a]+'''var pendingSignature=null;function S(){if(!x)return;let state=Object.fromEntries([...u].map(key=>[key,A(key)])),baseline=Object.fromEntries([...u].map(key=>[key,v[key]])),backup={tenantId:m,userId:l.id,baseVersion:b,baseline,state},encoded=u.size?JSON.stringify(backup):null;if(encoded===pendingSignature)return;pendingSignature=encoded;if(!u.size){k.call(localStorage,x);window.NSC_WORKSPACE_CACHE.remove(x).catch(()=>{});return}try{J.call(localStorage,x,encoded);window.NSC_WORKSPACE_CACHE.remove(x).catch(()=>{})}catch(error){window.NSC_WORKSPACE_CACHE.put(x,backup).then(()=>{if(pendingSignature===encoded)k.call(localStorage,x)}).catch(()=>{if(pendingSignature===encoded&&u.size)I("ذخیرهٔ نسخهٔ بازیابی در این مرورگر ممکن نشد؛ تا تأیید ثبت در سرور صفحه را نبندید. می‌توانید نسخهٔ محفوظ را دانلود کنید.")})}}''' +s[b:]
once('let a;try{a=JSON.parse(localStorage.getItem(x)||"null")}catch{}','let a;try{a=JSON.parse(localStorage.getItem(x)||"null")}catch{}if(!a)try{a=await window.NSC_WORKSPACE_CACHE.get(x)}catch{}')
# A synchronously readable memory mirror is necessary when a section exceeds localStorage capacity.
once('JSON.stringify(c.users.map($))),Storage.prototype.setItem','JSON.stringify(c.users.map($)));Storage.prototype.setItem')
needle='Storage.prototype.setItem=function(t,r){'
once(needle,'const nativeGetItem=Storage.prototype.getItem;Storage.prototype.getItem=function(key){if(this===localStorage){let section=D.find(name=>q(name)===key);if(section&&Object.prototype.hasOwnProperty.call(z,section))return z[section]}return nativeGetItem.call(this,key)};'+needle)
once('let o=this.getItem(t);if(J.call(this,t,r),this!==localStorage||!y||o===String(r))return;let d=D.find(N=>q(N)===t);d&&(z[d]=String(r),L.includes(d)&&(u.add(d),P(),S()))','let o=this.getItem(t),d=this===localStorage?D.find(N=>q(N)===t):null;if(d)z[d]=String(r);try{J.call(this,t,r)}catch(error){if(!d||(error.name!=="QuotaExceededError"&&error.name!=="NS_ERROR_DOM_QUOTA_REACHED"))throw error}if(this!==localStorage||!y||o===String(r))return;d&&L.includes(d)&&(u.add(d),P(),S())')
s=s.replace('/experience.js?v=2','/experience.js?v=3')
s=s.replace('<link rel="stylesheet" href="/forms-ui.css?v=1">','<link rel="stylesheet" href="/forms-ui.css?v=1"><link rel="stylesheet" href="/workspace-design.css?v=1">').replace('<script src="/forms-ui.js?v=1" defer></script>','<script src="/forms-ui.js?v=1" defer></script><script src="/workspace-design.js?v=1" defer></script>')
p.write_text(s)
print('Patched targeted pending storage and asset versions')
