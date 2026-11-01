exec(open(__import__('pathlib').Path(__file__).with_name('regression.py')).read().split("owner=login('preview')")[0])
import hashlib,urllib.parse,struct,zlib,os
owner=login('preview');staff=login('audit-staff');other=login('audit-other')
png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9WQAAAAASUVORK5CYII='
raw=base64.b64decode(png.split(',',1)[1]);digest=hashlib.sha256(png.encode()).hexdigest();ref={'__nsc_file__':digest}
def state(t):return request('/api/workspace.php',token=t)[1]['data']
def form(payload,files=None,token=owner,extra=None,origin=None):
 boundary='nsc'+uuid.uuid4().hex;parts=[]
 for key,value in {'payload':json.dumps(payload),**(extra or{})}.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
 for id,content,mime in files or[]:parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="files[{id}]"; filename="file.bin"\r\nContent-Type: {mime}\r\n\r\n'.encode()+content+b'\r\n')
 parts.append(f'--{boundary}--\r\n'.encode());headers={'Content-Type':'multipart/form-data; boundary='+boundary,'X-Requested-With':'XMLHttpRequest'}
 if origin:headers['Origin']=origin
 return request('/api/workspace.php',token=token,raw=b''.join(parts),headers=headers)
old=state(owner)['state']['appSettings']
try:
 s=state(owner);payload={'tenantId':s['tenantId'],'baseVersion':s['stateVersion'],'state':{'appSettings':{'companyName':'Multipart test'}}}
 check('plain standard form save succeeds',form(payload)[0]==200)
 s=state(owner);payload={'baseVersion':s['stateVersion'],'fileTransport':1,'state':{'appSettings':{'logoUrl':ref}}}
 check('binary PNG reconstructed and saved',form(payload,[(digest,raw,'image/png')])[0]==200)
 check('saved logo equals original data URI',state(owner)['state']['appSettings']['logoUrl']==png)
 payload['baseVersion']=state(owner)['stateVersion'];payload['state']['appSettings']['companyName']='Reuse'
 check('saved file reference reused without upload',form(payload)[0]==200)
 payload['baseVersion']=state(other)['stateVersion'];check('file reference cannot cross company',form(payload,token=other)[0]==409)
 payload['baseVersion']=state(staff)['stateVersion'];payload['state']={'expenses':[{'id':'hidden-source','projectId':'audit-a','image':ref}]}
 check('restricted user cannot reuse hidden settings file',form(payload,token=staff)[0]==409)
 payload={'baseVersion':state(owner)['stateVersion'],'fileTransport':1,'state':{'appSettings':{'logoUrl':ref}}}
 bad_id='a'*64
 check('mismatched file digest rejected',form(payload,[(bad_id,raw,'image/png')])[0]==400)
 check('executable disguised as PNG rejected',form(payload,[(digest,b'<?php echo 1;','image/png')])[0]==400)
 check('MIME mismatch rejected',form(payload,[(digest,raw,'application/pdf')])[0]==400)
 check('unreferenced extra file rejected',form({'baseVersion':state(owner)['stateVersion'],'fileTransport':1,'state':{'appSettings':{}}},[(digest,raw,'image/png')])[0]==400)
 check('anonymous multipart save denied',form(payload,[(digest,raw,'image/png')],token=None)[0]==401)
 check('cross-origin multipart save denied',form(payload,origin='https://hostile.invalid')[0]==403)
 check('unexpected form fields rejected',form(payload,extra={'untrusted':'x'})[0]==400)
 check('cross-tenant override denied',form({**payload,'tenantId':'some-other-company'})[0]==403)
 check('malformed file reference rejected',form({'baseVersion':state(owner)['stateVersion'],'fileTransport':1,'state':{'appSettings':{'logoUrl':{'__nsc_file__':digest,'extra':'x'}}}})[0]==400)
 check('stale version still rejected',form({'baseVersion':-1,'state':{'appSettings':{'companyName':'Stale'}}})[0]==409)
 check('stored image survives all rejected writes',state(owner)['state']['appSettings']['logoUrl']==png)
 # Native PHP file uploads must accept normal photos larger than the default 2 MB limit.
 def chunk(kind,data):return struct.pack('>I',len(data))+kind+data+struct.pack('>I',zlib.crc32(kind+data)&0xffffffff)
 pixels=b''.join(b'\x00'+os.urandom(1024*3)for _ in range(1024))
 photo=b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',1024,1024,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(pixels,1))+chunk(b'IEND',b'')
 photo_uri='data:image/png;base64,'+base64.b64encode(photo).decode();photo_hash=hashlib.sha256(photo_uri.encode()).hexdigest()
 check('normal photo larger than 2 MB saves',form({'baseVersion':state(owner)['stateVersion'],'fileTransport':1,'state':{'appSettings':{'largePhoto':{'__nsc_file__':photo_hash},'logoUrl':ref}}},[(photo_hash,photo,'image/png')])[0]==200)
 check('private transport helper cannot be fetched',request('/api/workspace-files.php')[0]==403)
 # Size is bounded in decoded transport too.
 too_big=b'x'*(10*1024*1024+1)
 check('oversized binary file rejected',form(payload,[(digest,too_big,'image/png')])[0]in[400,413])
finally:
 s=state(owner);assert request('/api/workspace.php',{'baseVersion':s['stateVersion'],'state':{'appSettings':old}},owner)[0]==200
print(f'{sum(ok for _,ok in checks)}/{len(checks)} transport checks passed');assert all(ok for _,ok in checks)
