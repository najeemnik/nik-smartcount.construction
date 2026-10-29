exec(open(__import__('pathlib').Path(__file__).with_name('regression.py')).read().split("owner=login('preview')")[0])
accounts=json.loads(pathlib.Path('/workspace/nsc-preview/accounts.json').read_text());ma=accounts[1]
s,b=request('/api/auth.php?action=master_login',ma);assert s==200;master=b['data']['token']
owner=login('preview');staff=login('audit-viewer')
def update(perms,mods=None):
 s,b=request('/api/office7.php?action=update_tenant',{'id':'preview-owner','permissions':perms,'customEnabledModules':mods or {}},master);assert s==200,(s,b)
def me(token):return request('/api/auth.php',token=token)[1]['data']['user']
try:
 catalog=me(owner)['permissionCatalog'];check('catalogue includes dashboard, audits, partners and all workspace sections',len(catalog)==20 and {'dashboard','audit_logs','partners'}.issubset(catalog))
 for mod in catalog:
  update({mod:{'view':False}})
  rights=me(owner)['effectivePermissions'][mod]
  check('owner cannot bypass hidden '+mod,not any(rights.values()))
 update({'projects':{'create':False,'edit':False,'delete':False,'print':False,'export':False,'approve':False}})
 rights=me(owner)['effectivePermissions']['projects'];check('owner action-level restrictions enforced',rights['view'] and not any(rights[a] for a in ['create','edit','delete','approve','print','export']))
 check('owner SQL create denied',request('/api/projects.php',{'id':'forbidden-matrix','name':'Denied'},owner)[0]==403)
 state=request('/api/workspace.php',token=owner)[1]['data'];ps=state['state']['projects'];ps[0]['name']='Denied edit'
 check('owner snapshot edit denied',request('/api/workspace.php',{'baseVersion':state['stateVersion'],'state':{'projects':ps}},owner)[0]==403)
 update({'expenses':{'approve':False}})
 state=request('/api/workspace.php',token=owner)[1]['data'];rows=state['state']['expenses']+[{'id':'approval-case','projectId':'audit-a','docStatus':'posted'}]
 check('posting requires approval independently of edit permission',request('/api/workspace.php',{'baseVersion':state['stateVersion'],'state':{'expenses':rows}},owner)[0]==403)
 update({'expenses':{'view':False},'users':{'view':False}})
 check('employee inherits current owner ceiling',not me(staff)['effectivePermissions']['expenses']['view'])
 check('restricted owner legacy snapshot read denied',request('/api/office7.php?action=workspace_sync',token=owner)[0]==403)
 check('restricted owner legacy snapshot write denied',request('/api/office7.php?action=workspace_sync',{'projects':[],'records':{}},owner)[0]==403)
 check('disabled user roster returns own account only',[u['id'] for u in request('/api/office7.php?action=tenants',token=owner)[1]['data']['users']]==['preview-owner'])
 update({'audit_logs':{'view':False}})
 check('audit snapshot independently hidden','auditLogs' not in request('/api/workspace.php',token=owner)[1]['data']['state'])
 update({})
 check('unlock immediately restores owner access',me(owner)['effectivePermissions']['projects']['create'])
 # AI is separately configurable by an owner, within the master ceiling.
 s,b=request('/api/office7.php?action=update_tenant',{'id':'audit-viewer','aiEnabled':False},owner)
 check('owner can disable AI for own employee',s==200 and me(staff)['aiEnabled'] is False)
 request('/api/office7.php?action=update_tenant',{'id':'audit-viewer','aiEnabled':True},owner)
 request('/api/office7.php?action=update_tenant',{'id':'preview-owner','aiEnabled':False},master)
 check('employee inherits AI ceiling from owner',me(staff)['aiEnabled'] is False)
 check('disabled employee AI endpoint rejects direct requests',request('/api/office7.php?action=ai_query',{'prompt':'audit'},staff)[0]==403)
finally:
 update({})
 request('/api/office7.php?action=update_tenant',{'id':'preview-owner','aiEnabled':True},master)
 request('/api/office7.php?action=update_tenant',{'id':'audit-viewer','aiEnabled':True},master)
print(f'{sum(ok for _,ok in checks)}/{len(checks)} permission checks passed');assert all(ok for _,ok in checks)
