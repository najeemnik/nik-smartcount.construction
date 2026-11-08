"""Destructive synthetic fixtures, only nsc_audit / workflow-owner. No production data."""
import pathlib,subprocess,json
root=pathlib.Path(__file__).resolve().parent
runtime=pathlib.Path('/workspace/nsc-test')
accounts=json.loads((runtime/'accounts.json').read_text())
a=runtime/'workflow-account.json';a.write_text(json.dumps({'username':'workflow-demo','password':accounts[0]['password']}));a.chmod(0o600)
subprocess.run(['docker','cp',str(root/'workflow-seed.php'),'nsc-audit-web:/run/workflow-seed.php'],check=True)
subprocess.run(['docker','exec','nsc-audit-web','php','/run/workflow-seed.php'],check=True)
subprocess.run(['docker','exec','nsc-audit-web','php','/run/email-fixtures.php','disable'],check=True,capture_output=True)
pathlib.Path('/workspace/output/guide/screenshots').mkdir(parents=True,exist_ok=True)
for name in ['browser-expense-workflow','browser-bill-settlement','browser-payroll-workflow','browser-file-manager','browser-project-matrix','browser-staff-projects','browser-afn-settlement']:
 result=subprocess.run(['node',str(root/(name+'.cjs'))],capture_output=True,text=True)
 (runtime/'results'/(name+'.txt')).write_text(result.stdout+result.stderr)
 print(name+': '+('PASS'if result.returncode==0 else'FAIL'),flush=True)
 if result.returncode:print((result.stdout+result.stderr)[-1800:]);raise SystemExit(1)
