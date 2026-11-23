from pathlib import Path
p=Path('/mnt/data/pmwork/PMsched.php')
s=p.read_text()
start=s.index('function sendDueEmailNotifications(array &$data, bool $force=false): array {')
end=s.index('\n/** Send a standalone Brevo API test message', start)
new=r'''function sendDueEmailNotifications(array &$data, bool $force=false): array {
    $today=date('Y-m-d');
    $year=(int)($data['year']??date('Y'));
    $completed=[];
    foreach (($data['logs']??[]) as $log) {
        if ((int)($log['year']??0)===$year) $completed[(int)($log['equipment_id']??0).'-'.(int)($log['week']??0)]=true;
    }

    // Three independent reminder stages. Each task/recipient/stage has its own
    // delivery key, so a daily cron retry cannot resend a successful reminder.
    $stages=[7=>'7-DAY ADVANCE REMINDER',2=>'2-DAY ADVANCE REMINDER',0=>'DUE TODAY'];
    $pending=[];
    if (!isset($data['email_delivery']) || !is_array($data['email_delivery'])) $data['email_delivery']=[];

    foreach (($data['equipment']??[]) as $eq) {
        $equipmentId=(int)($eq['id']??0);
        for ($week=1;$week<=52;$week++) {
            $frequency=(string)($eq['schedule'][$week-1]??'');
            $dueDate=(string)($eq['due_dates'][$week-1]??'');
            if (($eq['schedule_mode']??'')!=='enrollment_combined' && ($frequency==='' || $dueDate==='')) {
                $frequency=(string)($eq['planned_frequency']??'');
                $dueDate=dueOccurrenceForWorkWeek((string)($eq['planned_due_date']??''),$frequency,$year,$week)??'';
            }
            if ($frequency==='' || $dueDate==='' || isset($completed[$equipmentId.'-'.$week])) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dueDate)) continue;
            $daysRemaining=(int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($dueDate))->format('%r%a');
            if (!array_key_exists($daysRemaining,$stages)) continue;
            $stage=$daysRemaining;
            $taskKey=$equipmentId.'|'.$dueDate.'|'.$stage;
            $pending[$stage][$taskKey]=[
                'equipment_id'=>$equipmentId,
                'week'=>$week,
                'code'=>(string)($eq['code']??''),
                'name'=>(string)($eq['name']??''),
                'due_date'=>$dueDate,
                'frequency'=>frequencyName($frequency),
            ];
        }
    }

    $sent=0; $failed=0; $dueCount=0;
    foreach ($pending as $stage=>$tasks) $dueCount+=count($tasks);
    if ($force && !$pending) return ['sent'=>0,'failed'=>0,'due'=>0];

    foreach (($data['users']??[]) as $user) {
        $email=(string)($user['email']??'');
        if (($user['status']??'')!=='approved' || empty($user['email_notifications']) || !filter_var($email,FILTER_VALIDATE_EMAIL)) continue;
        foreach ($pending as $stage=>$tasks) {
            $undelivered=[];
            foreach ($tasks as $taskKey=>$task) {
                $deliveryKey=$today.'|'.strtolower($email).'|'.$taskKey;
                if (!isset($data['email_delivery'][$deliveryKey])) $undelivered[$taskKey]=$task;
            }
            if (!$undelivered) continue;
            $stageLabel=$stages[$stage];
            $subject='PM '.$stageLabel.' - '.count($undelivered).' item(s)';
            $lines=[];
            foreach ($undelivered as $task) {
                $lines[]=$task['code'].' | '.$task['name'].' | Due: '.$task['due_date'].' | '.$task['frequency'];
            }
            $message="Preventive Maintenance Reminder\n\nReminder type: ".$stageLabel."\n\n".implode("\n",$lines)."\n\nGenerated: ".date('d/m/Y H:i:s').' IST';
            $result=sendBrevoTransactionalEmail($email,(string)($user['name']??''),$subject,$message);
            if ($result['ok']) {
                $sent++;
                foreach ($undelivered as $taskKey=>$task) {
                    $deliveryKey=$today.'|'.strtolower($email).'|'.$taskKey;
                    $data['email_delivery'][$deliveryKey]=[
                        'timestamp'=>date('c'),'status'=>'sent','stage_days_before'=>(int)$stage,
                        'equipment_id'=>$task['equipment_id'],'week'=>$task['week'],'due_date'=>$task['due_date']
                    ];
                }
            } else {
                $failed++;
                error_log('PM Brevo API delivery failed for '.$email.': '.$result['message']);
            }
        }
    }
    return ['sent'=>$sent,'failed'=>$failed,'due'=>$dueCount];
}
'''
s=s[:start]+new+s[end:]
s=s.replace("// All system timestamps use the configured company timezone (Philippine Time by default).\ndate_default_timezone_set(getenv('PM_TIMEZONE') ?: 'Asia/Manila');", "// All system timestamps and reminder dates use Ahmedabad, India time by default.\ndate_default_timezone_set(getenv('PM_TIMEZONE') ?: 'Asia/Kolkata');")
s=s.replace("' PHT';", "' IST';")
# CLI scheduler mode: functions are now defined, so run reminders without loading the web UI or requiring a login.
needle='\n$data = loadData();\n'
insert='''
// Render runs this file in CLI mode from its daily cron task. It uses the same
// persistent JSON data file and Brevo API configuration as the web service.
if (PHP_SAPI === 'cli' && getenv('PM_RUN_REMINDERS') === '1') {
    $cronData=loadData();
    $cronResult=sendDueEmailNotifications($cronData,false);
    if ($cronResult['sent']>0 || $cronResult['failed']>0) {
        $cronData['audit_logs'][]=['timestamp'=>date('c'),'user_id'=>null,'employee_no'=>'SYSTEM','name'=>'System','action'=>'SCHEDULED_PM_REMINDERS','details'=>'Items: '.$cronResult['due'].', emails accepted: '.$cronResult['sent'].', failed: '.$cronResult['failed'].'.'];
    }
    saveData($cronData);
    echo 'PM reminder run ('.date('c').'): items='.$cronResult['due'].', emails_accepted='.$cronResult['sent'].', failed='.$cronResult['failed'].PHP_EOL;
    exit(($cronResult['failed']??0)>0 ? 1 : 0);
}

$data = loadData();
'''
if needle not in s: raise SystemExit('data marker missing')
s=s.replace(needle,'\n'+insert,1)
# Remove web-page-triggered auto sending; scheduler is now the sole automatic sender.
old="""if ($_SERVER['REQUEST_METHOD']==='GET' && $currentUser) {
    $emailResult=sendDueEmailNotifications($data,false);
    if (($emailResult['sent']+$emailResult['failed'])>0) { auditAction($data,null,'AUTOMATIC_DUE_EMAIL','Due items: '.$emailResult['due'].', sent: '.$emailResult['sent'].', failed: '.$emailResult['failed'].'.'); saveData($data); }
}
"""
s=s.replace(old,'')
p.write_text(s)
