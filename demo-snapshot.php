<?php
/* demo-snapshot.php - Generateur seed DEMO (Option A). READ ONLY sur la base. CLI only. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require __DIR__ . '/config.php';
ini_set('display_errors','1'); error_reporting(E_ALL);

$DB='pura7044_assokit';
$OUT_DIR=__DIR__.'/demo-sql';
$OUT=$OUT_DIR.'/00-demo-full-snapshot.sql';
$ORG_IDS=[23,24,25,26];
@mkdir($OUT_DIR,0755,true);

function col_exists(PDO $p,$db,$t,$c){ $s=$p->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=? LIMIT 1"); $s->execute([$db,$t,$c]); return (bool)$s->fetchColumn(); }
function table_exists(PDO $p,$db,$t){ $s=$p->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=? LIMIT 1"); $s->execute([$db,$t]); return (bool)$s->fetchColumn(); }
function id_set(PDO $p,$sql){ $ids=$p->query($sql)->fetchAll(PDO::FETCH_COLUMN,0); $ids=array_values(array_filter($ids,fn($v)=>$v!==null)); return array_map('intval',$ids); }
function in_list($ids){ return empty($ids)?'(0)':'('.implode(',',$ids).')'; }

$orgIn=in_list($ORG_IDS);
$sets=[];
$sets['org']=$ORG_IDS;
$sets['user']=id_set($pdo,"SELECT id FROM users WHERE org_id IN $orgIn");
$sets['folder']=id_set($pdo,"SELECT id FROM folders WHERE org_id IN $orgIn");
$folderIn=in_list($sets['folder']);
$sets['project']=id_set($pdo,"SELECT id FROM projects WHERE folder_id IN $folderIn");
$projectIn=in_list($sets['project']);
$sets['assoInvoice']=id_set($pdo,"SELECT id FROM asso_invoices WHERE org_id IN $orgIn");
$sets['quote']=id_set($pdo,"SELECT id FROM asso_quotes WHERE org_id IN $orgIn");
$sets['grant']=id_set($pdo,"SELECT id FROM grants WHERE org_id IN $orgIn");
$sets['event']=id_set($pdo,"SELECT id FROM events WHERE org_id IN $orgIn");
$sets['commCampaign']=id_set($pdo,"SELECT id FROM communication_campaigns WHERE org_id IN $orgIn");
$sets['broadcast']=id_set($pdo,"SELECT id FROM communication_broadcasts WHERE org_id IN $orgIn");
$sets['cotisCampaign']=id_set($pdo,"SELECT id FROM cotisation_campaigns WHERE org_id IN $orgIn");
$sets['channel']=id_set($pdo,"SELECT id FROM channels WHERE org_id IN $orgIn");
$sets['assembly']=id_set($pdo,"SELECT id FROM assemblies WHERE org_id IN $orgIn");
$sets['tag']=id_set($pdo,"SELECT id FROM asso_tags WHERE org_id IN $orgIn");
$sets['ticket']=id_set($pdo,"SELECT id FROM support_tickets WHERE org_id IN $orgIn");
$sets['aiConv']=id_set($pdo,"SELECT id FROM ai_conversations WHERE project_id IN $projectIn");
$sets['diffusion']=id_set($pdo,"SELECT id FROM asso_ai_diffusions WHERE org_id IN $orgIn");

$MAP=[
 ['organizations','id','org'],['users','org_id','org'],['folders','org_id','org'],['projects','folder_id','folder'],
 ['asso_invoices','org_id','org'],['asso_invoice_lines','invoice_id','assoInvoice'],['asso_invoice_payments','invoice_id','assoInvoice'],
 ['asso_invoice_recurrences','org_id','org'],['asso_quotes','org_id','org'],['asso_quote_lines','quote_id','quote'],
 ['asso_clients','org_id','org'],['asso_tags','org_id','org'],['asso_tag_links','tag_id','tag'],['invoices','org_id','org'],
 ['org_invoice_settings','org_id','org'],['subscriptions','org_id','org'],['subscription_invoices','org_id','org'],
 ['subscription_payments','org_id','org'],['asso_subscriptions','org_id','org'],['asso_subscription_addons','org_id','org'],
 ['grants','org_id','org'],['grant_steps','grant_id','grant'],['grant_documents','grant_id','grant'],['project_grants','project_id','project'],
 ['project_invoices','project_id','project'],['project_documents','project_id','project'],['project_files','project_id','project'],
 ['project_followers','project_id','project'],['project_members','project_id','project'],['project_messages','project_id','project'],
 ['project_share_tokens','project_id','project'],['project_steps','project_id','project'],['project_updates','project_id','project'],
 ['user_pinned_folders','folder_id','folder'],['user_project_reads','project_id','project'],
 ['events','org_id','org'],['event_participants','event_id','event'],['event_rsvp','event_id','event'],
 ['attendance_sessions','org_id','org'],['attendance_records','user_id','user'],
 ['assemblies','org_id','org'],['assembly_attendees','user_id','user'],['assembly_resolutions','assembly_id','assembly'],['assembly_votes','assembly_id','assembly'],
 ['channels','org_id','org'],['channel_members','channel_id','channel'],['channel_messages','channel_id','channel'],['channel_reads','channel_id','channel'],['channel_ai_reports','channel_id','channel'],
 ['communication_campaigns','org_id','org'],['communication_broadcasts','org_id','org'],['communication_broadcast_recipients','broadcast_id','broadcast'],
 ['communication_events','org_id','org'],['communication_event_rsvps','user_id','user'],['communication_logs','campaign_id','commCampaign'],['communication_saved_templates','org_id','org'],
 ['cotisation_campaigns','org_id','org'],['cotisation_tiers','campaign_id','cotisCampaign'],['cotisation_payments','org_id','org'],
 ['ai_conversations','project_id','project'],['ai_messages','conversation_id','aiConv'],['ai_generated_docs','project_id','project'],
 ['asso_ai_settings','org_id','org'],['asso_ai_role_quotas','org_id','org'],['asso_ai_generations','org_id','org'],['asso_ai_diffusions','org_id','org'],['asso_ai_diffusion_recipients','diffusion_id','diffusion'],
 ['assokit_absences','org_id','org'],['assokit_schedules','org_id','org'],['coach_reports','org_id','org'],
 ['support_tickets','org_id','org'],['support_messages','ticket_id','ticket'],['support_ticket_events','ticket_id','ticket'],
 ['public_signups','org_id','org'],['user_notifications','user_id','user'],
 ['asso_custom_domains','org_id','org'],['asso_domain_email_config','org_id','org'],
];

$fh=fopen($OUT,'w');
fwrite($fh,"-- DEMO snapshot ".date('Y-m-d H:i:s')."  orgs=".implode(',',$ORG_IDS)."\n");
fwrite($fh,"SET FOREIGN_KEY_CHECKS=0;\n");
$total=0; $skip=[]; $rep=[];
foreach($MAP as [$t,$c,$sn]){
  if(!table_exists($pdo,$DB,$t)){ $skip[]="$t (table absente)"; continue; }
  if(!col_exists($pdo,$DB,$t,$c)){
    $av=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position");
    $av->execute([$DB,$t]); $skip[]="$t (col '$c' absente; dispo: ".implode(',',$av->fetchAll(PDO::FETCH_COLUMN,0)).")"; continue;
  }
  $ids=$sets[$sn]??[]; $inl=in_list($ids);
  fwrite($fh,"\n-- ===== $t (scope $c IN $sn) =====\n");
  fwrite($fh,"DELETE FROM `$t` WHERE `$c` IN $inl;\n");
  if(empty($ids)){ $rep[$t]=0; continue; }
  $q=$pdo->query("SELECT * FROM `$t` WHERE `$c` IN $inl"); $n=0;
  while($row=$q->fetch(PDO::FETCH_ASSOC)){
    $cols=array_map(fn($x)=>"`$x`",array_keys($row));
    $vals=array_map(fn($v)=>$v===null?'NULL':$pdo->quote((string)$v),array_values($row));
    fwrite($fh,"INSERT INTO `$t` (".implode(',',$cols).") VALUES (".implode(',',$vals).");\n"); $n++;
  }
  $rep[$t]=$n; $total+=$n;
}
fwrite($fh,"\nSET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);

echo "=== SNAPSHOT OK ===\nFichier : $OUT\nTaille  : ".round(filesize($OUT)/1024,1)." Ko\nINSERT totaux : $total\n\n-- Detail (>0) --\n";
foreach($rep as $t=>$n) if($n>0) printf("%-38s %6d\n",$t,$n);
echo "\n-- Tables vides --\n"; foreach($rep as $t=>$n) if($n===0) echo "  $t\n";
if($skip){ echo "\n-- IGNOREES (a corriger) --\n"; foreach($skip as $s) echo "  $s\n"; }
