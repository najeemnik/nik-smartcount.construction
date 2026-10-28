exec(open(__import__('pathlib').Path(__file__).with_name('regression.py')).read().split("owner=login('preview')")[0])
owner=login('preview');staff=login('audit-staff')
row={'projectId':'audit-a','workerName':'Wage regression','days':2,'rate':10,'currency':'AFN','advanceDeduction':5,'notes':uuid.uuid4().hex}
s,b=request('/api/payroll.php',row,owner);check('earned wages do not imply a cash payment',s==200 and b['data']['amountPaid']==0 and b['data']['remainingPayable']==15)
for change,label in [({'amountPaid':-1},'negative payment'),({'advanceDeduction':25},'advance above earnings'),({'amountPaid':21},'overpayment'),({'currency':'USD'},'missing exchange rate')]:
 check('payroll rejects '+label,request('/api/payroll.php',{**row,**change},owner)[0]==400)
s,b=request('/api/exchange.php',{'projectId':'audit-a','exchangeAgent':'Test agent','recipientName':'Test recipient','amount':1,'notes':uuid.uuid4().hex},owner);check('exchange defaults work when optional fields omitted',s==201)
if s==201:
 id=b['data']['id'];check('exchange rejects negative update',request('/api/exchange.php?action=update',{'id':id,'amount':-1},owner)[0]==400)
s,b=request('/api/apartments.php',{'projectId':'audit-a','unitNumber':uuid.uuid4().hex[:8],'priceAfn':100,'totalPaidAfn':101},owner);check('apartment overpayment not silently discarded',s==400)
s,b=request('/api/materials.php',{'projectId':'audit-a','materialName':'USD purchase test','materialCategory':'steel','quantity':1,'unitPrice':100,'currency':'USD','exchangeRate':70,'isPaid':True,'notes':uuid.uuid4().hex},owner)
check('paid USD material purchase saved',s==200)
if s==200:
 id=b['data']['id'];s,b=request('/api/materials.php?projectId=audit-a',token=owner);r=next(x for x in b['data']['materials'] if x['id']==id);check('material currency and exchange rate preserved',r['currency']=='USD' and float(r['exchange_rate'])==70)
 s,b=request('/api/finance.php?section=payments&projectId=audit-a',token=owner);p=next(x for x in b['data']['payments'] if x['id']==r['payment_voucher_id']);check('payment base amount converted to AFN',float(p['amount'])==100 and float(p['amount_in_base_afn'])==7000)
 s,b=request('/api/materials.php',{'id':id,'quantity':2,'unitPrice':100,'notes':uuid.uuid4().hex},owner,method='PUT');check('material edit uses valid invoice column',s==200)
# Version conflicts preserve both records rather than silently overwriting newer state.
s=request('/api/workspace.php',token=owner)[1]['data'];settings=s['state'].get('appSettings',[])
a=request('/api/workspace.php',{'baseVersion':s['stateVersion'],'state':{'appSettings':{'test':'new'}}},owner)
b=request('/api/workspace.php',{'baseVersion':s['stateVersion'],'state':{'appSettings':{'test':'old'}}},owner)
check('stale workspace save rejected',a[0]==200 and b[0]==409)
latest=request('/api/workspace.php',token=owner)[1]['data'];check('newer workspace state preserved',latest['state']['appSettings']['test']=='new')
request('/api/workspace.php',{'baseVersion':latest['stateVersion'],'state':{'appSettings':settings}},owner)
print(f'{sum(ok for _,ok in checks)}/{len(checks)} financial checks passed')
pathlib.Path(__file__).with_name('financial-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
raise SystemExit(0 if all(ok for _,ok in checks) else 1)
