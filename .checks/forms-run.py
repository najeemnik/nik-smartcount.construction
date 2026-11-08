"""Local audit container only; browser scenarios restore synthetic workspace snapshots."""
from pathlib import Path
import subprocess,json
root=Path(__file__).resolve().parent
results=Path('/workspace/nsc-test/results');report=[]
for name in ['browser-supplier-forms','browser-camera','browser-steel-to-supplier','browser-native-currencies','browser-forms-layout']:
 r=subprocess.run(['node',str(root/(name+'.cjs'))],capture_output=True,text=True)
 (results/(name+'.txt')).write_text(r.stdout+r.stderr)
 report.append({'test':name,'passed':r.returncode==0})
 print(name+': '+('PASS' if r.returncode==0 else 'FAIL'),flush=True)
 if r.returncode:
  print((r.stdout+r.stderr)[-2000:]);raise SystemExit(1)
(results/'forms-final.json').write_text(json.dumps(report,indent=2))
