<?php
/** Read current purchases and preserve legacy steel records without duplicate IDs. */
function steelReportRows(PDO $db, string $tenant, ?string $project = null, ?string $record = null): array {
    $params=['tid'=>$tenant];
    $sql="SELECT m.* FROM material_procurements m INNER JOIN projects p ON p.id=m.project_id AND p.tenant_id=m.tenant_id WHERE m.tenant_id=:tid AND (m.material_category IN ('steel','rebar','فولاد','آهن','سیخ‌گول') OR m.confirmed_total_ton IS NOT NULL)";
    if($project){$sql.=' AND m.project_id=:pid';$params['pid']=$project;}
    if($record){$sql.=' AND m.id=:rid';$params['rid']=$record;}
    $s=$db->prepare($sql);$s->execute($params);$rows=[];
    foreach($s->fetchAll() as $r){
        $currency=strtoupper($r['currency']);$cost=(float)$r['total_cost'];$rate=(float)$r['exchange_rate'];
        $usd=$currency==='USD'?$cost:($rate>0?$cost/$rate:null);
        $lines=json_decode($r['line_items_json']??'[]',true)?:[];$sizes=[];$brands=[];$bundles=0;$branches=0;
        foreach($lines as $line){if(!is_array($line))continue;if(isset($line['diameterMm']))$sizes[]=$line['diameterMm'].'mm';if(!empty($line['brandSpec']))$brands[]=$line['brandSpec'];$bundles+=(int)($line['bundleCount']??0);$branches+=(int)($line['barCount']??0);}
        $r+=['date'=>$r['purchase_date'],'total_native_cost'=>$cost,'total_amount'=>$usd,'amount_in_usd'=>$usd,'amount_in_afn'=>$currency==='AFN'?$cost:($rate>0?$cost*$rate:null),
          'paid_amount'=>$r['is_paid']?($usd??0):0,'size_mm'=>implode(', ',array_unique($sizes)),'origin_country'=>implode(', ',array_unique($brands)),
          'bundles'=>$bundles,'branches'=>$branches,'invoice_number'=>$r['invoice_no'],'invoice_url'=>null];
        $rows[(string)$r['id']]=$r;
    }
    if($db->query("SHOW TABLES LIKE 'steel_records'")->fetchColumn()){
        $sql='SELECT s.* FROM steel_records s INNER JOIN projects p ON s.project_id=p.id WHERE p.tenant_id=:tid';
        if($project)$sql.=' AND s.project_id=:pid';if($record)$sql.=' AND s.id=:rid';
        $s=$db->prepare($sql);$s->execute($params);
        foreach($s->fetchAll() as $r)if(!isset($rows[(string)$r['id']]))$rows[(string)$r['id']]=$r;
    }
    $rows=array_values($rows);usort($rows,fn($a,$b)=>strcmp((string)($b['date']??''),(string)($a['date']??'')));
    return $rows;
}
