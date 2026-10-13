<?php
class ResellerDebt {
 public static function outstanding(PDO $db,int $id):float {
  $q=$db->prepare("SELECT COALESCE(SUM(d.amount),0) FROM tbl_reseller_debts d WHERE d.reseller_id=?
   AND (d.entry_type<>'recovery' OR NOT EXISTS(SELECT 1 FROM tbl_transactions t LEFT JOIN tbl_users au ON au.id=t.admin_id
    WHERE t.id=d.recharge_id AND (t.method LIKE 'Admin Manual - %' OR au.user_type IN ('Admin','SuperAdmin'))))");
  $q->execute([$id]);return max(0,round((float)$q->fetchColumn(),2));
 }
 public static function summary(PDO $db,?int $id=null):array {
  $where=' WHERE 1=1';$args=[];if($id!==null){$where.=' AND reseller_id=?';$args[]=$id;}
  $q=$db->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='debt' THEN amount ELSE 0 END),0) added,COALESCE(SUM(CASE WHEN entry_type='recovery' AND NOT EXISTS(SELECT 1 FROM tbl_transactions t LEFT JOIN tbl_users au ON au.id=t.admin_id WHERE t.id=tbl_reseller_debts.recharge_id AND (t.method LIKE 'Admin Manual - %' OR au.user_type IN ('Admin','SuperAdmin'))) THEN -amount ELSE 0 END),0) recovered FROM tbl_reseller_debts".$where);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);
  $q=$db->prepare("SELECT COALESCE(SUM(outstanding),0) FROM (SELECT GREATEST(0,SUM(d.amount)) outstanding FROM tbl_reseller_debts d".$where." AND (d.entry_type<>'recovery' OR NOT EXISTS(SELECT 1 FROM tbl_transactions t LEFT JOIN tbl_users au ON au.id=t.admin_id WHERE t.id=d.recharge_id AND (t.method LIKE 'Admin Manual - %' OR au.user_type IN ('Admin','SuperAdmin')))) GROUP BY d.reseller_id) debt");
  $q->execute($args);
  return ['added'=>round((float)$row['added'],2),'recovered'=>round((float)$row['recovered'],2),'outstanding'=>round((float)$q->fetchColumn(),2)];
 }
 public static function add(PDO $db,int $id,int $actor,string $raw,string $note,string $operationKey=''):void {
  if(!preg_match('/^[0-9]{1,8}([.][0-9]{1,2})?$/',$raw)||(float)$raw<=0||trim($note)===''||strlen($note)>1000)throw new InvalidArgumentException('Enter a positive debt amount and reason.');
  if(!preg_match('/^[a-f0-9]{32}$/D',$operationKey))throw new InvalidArgumentException('Refresh the form and retry.');
  $db->beginTransaction();try{
   $q=$db->prepare('SELECT id FROM tbl_resellers WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Reseller not found.');
   $q=$db->prepare('SELECT id,reseller_id,amount,entry_type,note FROM tbl_reseller_debts WHERE operation_key=?');$q->execute([$operationKey]);$prior=$q->fetch(PDO::FETCH_ASSOC);
   if($prior){if((int)$prior['reseller_id']!==$id||$prior['entry_type']!=='debt'||round((float)$prior['amount'],2)!==round((float)$raw,2)||$prior['note']!==$note)throw new InvalidArgumentException('This request key was already used for another action.');$db->commit();return;}
   $q=$db->prepare("INSERT INTO tbl_reseller_debts (reseller_id,amount,entry_type,note,processed_by,created_at,operation_key) VALUES (?,?,'debt',?,?,NOW(),?)");$q->execute([$id,$raw,$note,$actor,$operationKey]);$db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 // Caller holds the reseller row lock and owns the recharge ledger transaction.
 public static function recover(PDO $db,int $id,int $recharge,float $profit):float {
  $amount=min(self::outstanding($db,$id),max(0,round($profit,2)));if($amount<=0)return 0;
  $debtKey=substr(hash('sha256','reseller-debt-recovery:'.$recharge),0,32);
  $settlementKey=substr(hash('sha256','reseller-debt-settlement:'.$recharge),0,32);
  $q=$db->prepare("INSERT INTO tbl_reseller_debts (reseller_id,amount,entry_type,recharge_id,note,created_at,operation_key) VALUES (?,?,'recovery',?,'Recovered from recharge profit',NOW(),?)");$q->execute([$id,-$amount,$recharge,$debtKey]);
  $q=$db->prepare("INSERT INTO tbl_reseller_settlements (reseller_id,amount,payment_method,reference,note,created_at,entry_type,operation_key) VALUES (?,?,'Debt recovery',?,'Applied reseller profit to ISP debt',NOW(),'debt_repayment',?)");$q->execute([$id,$amount,'Recharge #'.$recharge,$settlementKey]);return $amount;
 }
}
