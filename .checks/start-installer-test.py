import pathlib,subprocess,shutil,secrets,os
root=pathlib.Path(__file__).resolve().parents[1];work=pathlib.Path('/workspace/nsc-install-test');work.mkdir(exist_ok=True)
site=work/('site-'+secrets.token_hex(4));site.mkdir(exist_ok=True);site.chmod(0o755)
# Copy deployment only, never private configuration or repository metadata.
for p in root.iterdir():
 if (p.name.startswith('.') and p.name!='.htaccess') or p.name=='uploads':continue
 if p.is_dir():shutil.copytree(p,site/p.name,dirs_exist_ok=True,ignore=shutil.ignore_patterns('.secrets','uploads'))
 else:shutil.copy2(p,site/p.name)
secret=site/'api/.secrets';secret.mkdir(exist_ok=True)
for name in ['schema.sql','.htaccess']:shutil.copy2(root/'api/.secrets'/name,secret/name)
for p in site.rglob('*'):
 if p.is_file():p.chmod(0o644)
 elif p.is_dir():p.chmod(0o755)
secret.chmod(0o700)
key=secrets.token_hex(26);(secret/'install.key').write_text(key);(secret/'install.key').chmod(0o600)
(work/'test-key').write_text(key);(work/'test-key').chmod(0o600)
db='nsc_install_audit_'+secrets.token_hex(4)
sql=f"CREATE DATABASE `{db}` CHARACTER SET utf8mb4; GRANT ALL ON `{db}`.* TO 'nsc'@'%';"
subprocess.run(['docker','exec','-i','nsc-preview-db','sh','-c','MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot'],input=sql,text=True,check=True,capture_output=True)
env=pathlib.Path('/workspace/nsc-audit/application.env').read_text().splitlines();env=[x for x in env if not x.startswith('DB_NAME=')];env.append('DB_NAME='+db);(work/'application.env').write_text('\n'.join(env)+'\n');(work/'application.env').chmod(0o600)
if subprocess.run(['docker','container','inspect','nsc-install-test-web'],capture_output=True).returncode==0:subprocess.run(['docker','rm','-f','nsc-install-test-web'],check=True,stdout=subprocess.DEVNULL)
subprocess.run(['docker','run','-d','--name','nsc-install-test-web','--network','nsc-preview','--env-file',str(work/'application.env'),'-p','127.0.0.1:8082:80','-v',str(site)+':/var/www/html','nsc-audit-php'],check=True,stdout=subprocess.DEVNULL)
subprocess.run(['docker','exec','nsc-install-test-web','chown','-R','www-data:www-data','/var/www/html/api/.secrets'],check=True)
subprocess.run(['docker','cp','/workspace/nsc-audit/test-sendmail','nsc-install-test-web:/usr/local/bin/nsc-test-sendmail'],check=True)
subprocess.run(['docker','exec','nsc-install-test-web','sh','-c','printf "sendmail_path=/usr/local/bin/nsc-test-sendmail\\n" > /usr/local/etc/php/conf.d/nsc-test-mail.ini; apachectl graceful'],check=True,capture_output=True)
print('Fresh isolated installer instance ready')
