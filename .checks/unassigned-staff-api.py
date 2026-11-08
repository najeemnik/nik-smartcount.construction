"""Only local nsc_audit: new staff must not inherit global scope and clearing grants must revoke access."""
import json,pathlib,urllib.request,urllib.error,uuid
base='http://127.0.0.1:8081/api/';accounts=json.loads(pathlib.Path('/workspace/nsc-test/accounts.json').read_text());password=accounts[0]['password'];checks=[];opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(path,data=None,token=None):
 r=urllib.request.Request(base+path,data=json.dumps(data).encode()if data is not None else None,headers={'Content-Type':'application/json',**({'Authorization':'Bearer '+token}if token else{})})
 try:s=opener.open(r,timeout=20)
 except urllib.error.HTTPError as e:s=e
 with s:return s.status,json.load(s)
def check(name,ok):checks.append((name,bool(ok)));print(('PASS 'if ok else'FAIL ')+name,flush=True)
s,b=req('auth.php?action=master_login',accounts[1]);assert s==200;master=b['data']['token'];username='unassigned-'+uuid.uuid4().hex[:12];uid=None
try:
 s,b=req('auth.php?action=sync_user',{'user':{'createOnly':True,'username':username,'email':username+'@nik-smartcount.com','rawPassword':password,'name':'Unassigned audit employee','role':'accountant','ownerAdminId':'workflow-owner'}},master);assert s==200,(s,b);uid=b['data']['id']
 def login():
  s,b=req('auth.php?action=login',{'username':username,'password':password});assert s==200,(s,b);return b['data']['token']
 token=login();s,b=req('auth.php',token=token);check('new unassigned employee has an explicit empty scope',b['data']['user']['projectGrants']==[]);check('new unassigned employee cannot read an existing project',req('projects.php?projectId=workflow-a',token=token)[0]==403);s,b=req('workspace.php',token=token);state=b['data']['state'];check('new unassigned employee receives no project snapshots',not (state.get('projects',[])if isinstance(state,dict)else state));check('new unassigned employee cannot read company totals',req('reports.php?type=dashboard',token=token)[0]==403)
 grants=[{'projectId':'workflow-a','sections':{'projects':{'view':True},'expenses':{'view':True,'create':True}}}]
 s,b=req('auth.php?action=sync_user',{'user':{'id':uid,'username':username,'projectGrants':grants,'assignedProjectIds':['workflow-a']}},master);assert s==200,(s,b);token=login();check('assigning one project enables its authorized reads',req('projects.php?projectId=workflow-a',token=token)[0]==200);s,b=req('projects.php?projectId=workflow-b',token=token);check('project list never leaks an unassigned project',s==403 or (s==200 and all(p['id']!='workflow-b' for p in b['data']['projects'])))
 s,b=req('auth.php?action=sync_user',{'user':{'id':uid,'username':username,'projectGrants':[],'assignedProjectIds':[]}},master);assert s==200;token=login();check('clearing grants does not restore global access',req('projects.php?projectId=workflow-a',token=token)[0]==403);check('clearing grants blocks company-wide reports',req('reports.php?type=dashboard',token=token)[0]==403);s,b=req('workspace.php',token=token);state=b['data']['state'];check('revoked employee receives no projects after re-login',not (state.get('projects',[])if isinstance(state,dict)else state))
 s,b=req('auth.php?action=sync_user',{'user':{'id':uid,'username':username,'projectGrants':grants,'assignedProjectIds':['workflow-a']}},token);check('self update cannot grant projects',s==200 and req('projects.php?projectId=workflow-a',token=token)[0]==403)
finally:
 if uid:req('auth.php?action=delete_user',{'id':uid},master)
pathlib.Path('/workspace/nsc-test/full-audit/unassigned-staff.json').write_text(json.dumps(checks,indent=2));print(f'{sum(ok for _,ok in checks)}/{len(checks)} unassigned staff API checks passed');assert all(ok for _,ok in checks)
