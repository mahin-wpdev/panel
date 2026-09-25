<?php
/** Isolated mobile tickets; never initiate payment or router operations. */
declare(strict_types=1);
function jm_ticket_actor(PDO $db,array $session): array {
    $actor=jm_app_actor($db,$session);
    if (!in_array($actor['role'],['customer','admin'],true))
        respond(403,['error'=>'FORBIDDEN']);
    if (!jm_app_table($db,'tbl_mobile_support_tickets') ||
        !jm_app_table($db,'tbl_mobile_support_events') ||
        !jm_app_table($db,'tbl_mobile_support_notifications'))
        respond(503,['error'=>'SUPPORT_NOT_CONFIGURED']);
    return $actor;
}
function jm_ticket_get(PDO $db,array $actor,int $id): array {
    if ($id<1) respond(400,['error'=>'INVALID_TICKET']);
    $where=$actor['role']==='customer'?' AND customer_id=?':'';
    $args=$actor['role']==='customer'?[$id,$actor['id']]:[$id];
    $row=jm_mobile_query($db,
        "SELECT id,customer_id,category,subject,description,status,
         assigned_admin_id,created_at,updated_at FROM tbl_mobile_support_tickets
         WHERE id=?".$where." LIMIT 1",$args)->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(404,['error'=>'TICKET_NOT_FOUND']);
    return $row;
}
function jm_ticket_notify(PDO $db,array $ticket,int $eventId,string $title,
                          bool $toAdmin): void {
    if ($toAdmin) {
        jm_mobile_query($db,
            "INSERT IGNORE INTO tbl_mobile_support_notifications
             (ticket_id,event_id,recipient_type,recipient_id,title,created_at)
             SELECT ?,?,'staff',id,?,NOW() FROM tbl_users
             WHERE user_type IN ('Admin','SuperAdmin') AND status='Active'",
            [$ticket['id'],$eventId,$title]);
    } else {
        jm_mobile_query($db,
            "INSERT IGNORE INTO tbl_mobile_support_notifications
             (ticket_id,event_id,recipient_type,recipient_id,title,created_at)
             VALUES (?,?,'customer',?,?,NOW())",
            [$ticket['id'],$eventId,$ticket['customer_id'],$title]);
    }
}
function jm_ticket_text($value,int $max): string {
    $value=trim((string)$value);
    if (mb_strlen($value)<1 || mb_strlen($value)>$max)
        respond(400,['error'=>'INVALID_TICKET_TEXT']);
    return $value;
}
function jm_ticket_list(PDO $db,array $session): array {
    $actor=jm_ticket_actor($db,$session);
    $where=$actor['role']==='customer'?'WHERE t.customer_id=?':'';
    $args=$actor['role']==='customer'?[$actor['id']]:[];
    $items=jm_mobile_query($db,"SELECT t.id,t.customer_id,t.category,
        t.subject,t.status,t.created_at,t.updated_at,c.username
        FROM tbl_mobile_support_tickets t JOIN tbl_customers c
        ON c.id=t.customer_id $where ORDER BY t.updated_at DESC,t.id DESC
        LIMIT 80",$args)->fetchAll(PDO::FETCH_ASSOC);
    return ['available'=>true,'items'=>$items,'role'=>$actor['role']];
}
function jm_ticket_detail(PDO $db,array $session,int $id): array {
    $actor=jm_ticket_actor($db,$session);
    $ticket=jm_ticket_get($db,$actor,$id);
    $events=jm_mobile_query($db,"SELECT id,event_type,actor_type,
        message,status,created_at FROM tbl_mobile_support_events
        WHERE ticket_id=? ORDER BY id ASC LIMIT 150",
        [$id])->fetchAll(PDO::FETCH_ASSOC);
    return ['available'=>true,'ticket'=>$ticket,'events'=>$events,
        'role'=>$actor['role']];
}
function jm_ticket_create(PDO $db,array $session,array $input): array {
    $actor=jm_ticket_actor($db,$session);
    if ($actor['role']!=='customer') respond(403,['error'=>'FORBIDDEN']);
    $category=(string)($input['category']??'');
    if (!in_array($category,['no_internet','slow_speed','los','billing','other'],true))
        respond(400,['error'=>'INVALID_CATEGORY']);
    $subject=jm_ticket_text($input['subject']??'',120);
    $description=jm_ticket_text($input['description']??'',3000);
    $recent=(int)jm_mobile_query($db,"SELECT COUNT(*)
        FROM tbl_mobile_support_tickets WHERE customer_id=?
        AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)",
        [$actor['id']])->fetchColumn();
    if ($recent>=5) respond(429,['error'=>'TICKET_RATE_LIMIT']);
    $db->beginTransaction();
    try {
        jm_mobile_query($db,"INSERT INTO tbl_mobile_support_tickets
          (customer_id,category,subject,description,status,created_at,updated_at)
          VALUES (?,?,?,?,'open',NOW(),NOW())",
          [$actor['id'],$category,$subject,$description]);
        $id=(int)$db->lastInsertId();
        jm_mobile_query($db,"INSERT INTO tbl_mobile_support_events
          (ticket_id,actor_type,actor_id,event_type,message,status,created_at)
          VALUES (?,'customer',?,'created',?,'open',NOW())",
          [$id,$actor['id'],$description]);
        $event=(int)$db->lastInsertId();
        jm_ticket_notify($db,['id'=>$id,'customer_id'=>$actor['id']],
            $event,'New ticket #'.$id.': '.$subject,true);
        $db->commit();
    } catch(Throwable $e) {$db->rollBack();throw $e;}
    if (function_exists('jm_push_send_staff')) {
        jm_push_send_staff($db,'New support ticket','#'.$id.' '.$subject,
          ['type'=>'support_ticket','ticket_id'=>(string)$id,
           'event'=>'created','status'=>'open']);
    }
    return ['id'=>$id,'status'=>'open'];
}
function jm_ticket_update(PDO $db,array $session,array $input): array {
    $actor=jm_ticket_actor($db,$session);
    $id=(int)($input['ticket_id']??0);
    $ticket=jm_ticket_get($db,$actor,$id);
    $action=(string)($input['action']??'reply');
    if (!in_array($action,['reply','status'],true))
       respond(400,['error'=>'INVALID_TICKET_ACTION']);
    $next=$ticket['status'];
    if ($action==='status') {
        if ($actor['role']!=='admin') respond(403,['error'=>'FORBIDDEN']);
        $next=(string)($input['status']??'');
        if (!in_array($next,['open','in_progress','resolved','closed'],true))
            respond(400,['error'=>'INVALID_TICKET_STATUS']);
        $message='Status changed to '.$next;
    } else {
        if ($ticket['status']==='closed')
            respond(409,['error'=>'TICKET_CLOSED']);
        $message=jm_ticket_text($input['message']??'',3000);
        if ($ticket['status']==='resolved' && $actor['role']==='customer')
            $next='open';
    }
    $recent=(int)jm_mobile_query($db,"SELECT COUNT(*)
        FROM tbl_mobile_support_events WHERE actor_type=? AND actor_id=?
        AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)",
        [$actor['role']==='admin'?'staff':'customer',$actor['id']])->fetchColumn();
    if ($recent>=60) respond(429,['error'=>'TICKET_RATE_LIMIT']);
    $db->beginTransaction();
    try {
        jm_mobile_query($db,"UPDATE tbl_mobile_support_tickets
          SET status=?,updated_at=NOW(),assigned_admin_id=
          IF(?='staff',?,assigned_admin_id) WHERE id=?",
          [$next,$actor['role']==='admin'?'staff':'customer',
           $actor['id'],$id]);
        jm_mobile_query($db,"INSERT INTO tbl_mobile_support_events
          (ticket_id,actor_type,actor_id,event_type,message,status,created_at)
          VALUES (?,?,?,?,?,?,NOW())",
          [$id,$actor['role']==='admin'?'staff':'customer',
           $actor['id'],$action,$message,$next]);
        $event=(int)$db->lastInsertId();
        jm_ticket_notify($db,$ticket,$event,
          'Ticket #'.$id.': '.($action==='status'?$next:'new reply'),
          $actor['role']!=='admin');
        $db->commit();
    } catch(Throwable $e) {$db->rollBack();throw $e;}
    if ($actor['role']==='admin' && function_exists('jm_push_send_actor')) {
        jm_push_send_actor($db,'customer',(int)$ticket['customer_id'],
          'Support ticket update','#'.$id.' '.$message,
          ['type'=>'support_ticket','ticket_id'=>(string)$id,
           'event'=>$action,'status'=>$next]);
    } elseif ($actor['role']!=='admin' && function_exists('jm_push_send_staff')) {
        jm_push_send_staff($db,'Customer replied','#'.$id.' '.$message,
          ['type'=>'support_ticket','ticket_id'=>(string)$id,
           'event'=>$action,'status'=>$next]);
    }
    return ['id'=>$id,'status'=>$next];
}
function jm_ticket_notifications(PDO $db,array $session): array {
    $actor=jm_ticket_actor($db,$session);
    $type=$actor['role']==='admin'?'staff':'customer';
    $count=(int)jm_mobile_query($db,
      "SELECT COUNT(*) FROM tbl_mobile_support_notifications
        WHERE recipient_type=? AND recipient_id=? AND read_at IS NULL",
      [$type,$actor['id']])->fetchColumn();
    $rows=jm_mobile_query($db,"SELECT id,ticket_id,title,created_at,read_at
      FROM tbl_mobile_support_notifications WHERE recipient_type=?
      AND recipient_id=? ORDER BY id DESC LIMIT 50",
      [$type,$actor['id']])->fetchAll(PDO::FETCH_ASSOC);
    return ['available'=>true,'unread'=>$count,'items'=>$rows];
}
function jm_ticket_notification_read(PDO $db,array $session,int $id): array {
    $actor=jm_ticket_actor($db,$session);
    if ($id<1) respond(400,['error'=>'INVALID_NOTIFICATION']);
    $type=$actor['role']==='admin'?'staff':'customer';
    jm_mobile_query($db,"UPDATE tbl_mobile_support_notifications
       SET read_at=COALESCE(read_at,NOW()) WHERE id=?
       AND recipient_type=? AND recipient_id=?",
       [$id,$type,$actor['id']]);
    return ['ok'=>true];
}
