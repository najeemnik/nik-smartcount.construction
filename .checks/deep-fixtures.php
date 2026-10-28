<?php
require '/var/www/html/api/db.php';
if(DB_NAME!=='nsc_audit')throw new RuntimeException('Audit database required');
$db=Database::getConnection();
foreach(['deep-orphan-edit','deep-orphan-delete'] as $id){$s=$db->prepare("INSERT INTO subcontractors(id,tenant_id,project_id,contractor_name,trade_specialty) VALUES (?,NULL,NULL,'Unclaimed legacy contractor','test') ON DUPLICATE KEY UPDATE tenant_id=NULL,project_id=NULL");$s->execute([$id]);}
echo "Deep fixtures ready\n";
$victimKey='v2:'.hash('sha256',json_encode(['preview-owner','audit-a','reserved-victim','reserved','ton','AFN'],JSON_UNESCAPED_UNICODE));
$reservedId='spr-'.substr(hash('sha256',$victimKey),0,60);
$s=$db->prepare("INSERT INTO supplier_product_rates(id,tenant_id,project_id,supplier_name,product,unit,last_agreed_rate,currency,effective_date,rate_key) VALUES (?,'audit-other','audit-foreign','Reserved legacy ID','reserved','ton',50,'AFN','2026-10-29',?) ON DUPLICATE KEY UPDATE last_agreed_rate=50");
$s->execute([$reservedId,'legacy-reserved-'.$reservedId]);
