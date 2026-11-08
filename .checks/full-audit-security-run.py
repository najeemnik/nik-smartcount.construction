"""Sequential tests of the LOCAL guarded audit database only; no production host or real mail."""
from pathlib import Path
import subprocess,re,json
root=Path(__file__).resolve().parent;out=Path('/workspace/nsc-test/full-audit');out.mkdir(exist_ok=True);results=[]
def command(args):return subprocess.run(args,capture_output=True,text=True)
def fixture(name,mode=None):
 command(['docker','cp',str(root/(name+'.php')),'nsc-audit-web:/run/'+name+'.php'])
 r=command(['docker','exec','nsc-audit-web','php','/run/'+name+'.php']+([mode]if mode else[]));assert r.returncode==0,r.stderr
for name in ['fixtures','deep-fixtures','email-fixtures','session-fixtures','reset-fixtures','token-tests']:
 r=command(['docker','cp',str(root/(name+'.php')),'nsc-audit-web:/run/'+name+'.php']);assert r.returncode==0
fixture('email-fixtures','disable');fixture('fixtures')
for name in ['regression','extended','financial','deep','workspace-transport','permission-matrix','email-security','session-security','password-reset']:
 if name in ['deep','workspace-transport','permission-matrix']:fixture('fixtures')
 if name=='deep':fixture('deep-fixtures')
 r=command(['python',str(root/(name+'.py'))]);log=r.stdout+r.stderr;(out/(name+'.txt')).write_text(log)
 passed=r.returncode==0 and not re.search(r'^FAIL ',log,re.M);m=re.findall(r'(\d+)/(\d+) [^\n]*passed',log);counts=m[-1]if m else None
 results.append({'suite':name,'passed':passed,'counts':counts});(out/'security-results.json').write_text(json.dumps(results,indent=2))
 print(name+': '+('PASS'if passed else'FAIL')+(' '+('/'.join(counts))if counts else''),flush=True)
 if not passed:print(log[-1600:],flush=True)
fixture('email-fixtures','disable')
r=command(['docker','exec','nsc-audit-web','php','/run/token-tests.php']);(out/'token-tests.txt').write_text(r.stdout+r.stderr);print('token-tests: '+('PASS'if r.returncode==0 else'FAIL'),flush=True)
raise SystemExit(0 if all(x['passed']for x in results)and r.returncode==0 else 1)
