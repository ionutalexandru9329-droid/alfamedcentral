<?php
namespace AlfamedModules\Statistics;

use App\Core\Database;
use App\Services\SettingService;
use DateTimeImmutable;
use PDO;

final class StatisticsService {
    private PDO $db;
    private SettingService $settings;

    public function __construct(?PDO $db=null){
        $this->db=$db?:Database::connection();
        $this->settings=new SettingService($this->db);
    }

    public function report(int $days=30,string $channel=''): array {
        $days=max(1,min(365,$days));
        $today=new DateTimeImmutable('today');
        $start=$today->modify('-'.($days-1).' days');
        $endExclusive=$today->modify('+1 day');
        $excludeCancelled=$this->settings->bool('module.statistics.exclude_cancelled',true);
        $daily=$this->emptyDaily($start,$days);

        [$dateWhere,$params]=$this->dateWindow('o',$start,$endExclusive);
        $where=['o.remote_deleted=0',$dateWhere];
        if($channel!==''){$where[]='c.code=?';$params[]=$channel;}

        $sql='SELECT DATE(COALESCE(o.ordered_at,o.created_at)) order_day,o.status,o.currency,c.code channel_code,c.name channel_name,COUNT(*) order_count,SUM(o.total) order_total '
            .'FROM orders o JOIN channels c ON c.id=o.channel_id WHERE '.implode(' AND ',$where)
            .' GROUP BY DATE(COALESCE(o.ordered_at,o.created_at)),o.status,o.currency,c.code,c.name';
        $st=$this->db->prepare($sql);$st->execute($params);

        $revenue=[];$channels=[];$statuses=[];$total=0;$completed=0;$active=0;$cancelled=0;$other=0;
        while($o=$st->fetch()){
            $count=(int)$o['order_count'];
            $sum=(float)$o['order_total'];
            $status=$this->normalizeStatus((string)$o['status']);
            $bucket=$this->statusBucket($status);
            $total+=$count;
            if($bucket==='cancelled')$cancelled+=$count;
            elseif($bucket==='completed')$completed+=$count;
            elseif($bucket==='active')$active+=$count;
            else $other+=$count;

            $statuses[$status]=($statuses[$status]??0)+$count;
            $day=(string)$o['order_day'];
            $code=(string)$o['channel_code'];
            if(!isset($channels[$code]))$channels[$code]=['code'=>$code,'name'=>(string)$o['channel_name'],'orders'=>0,'cancelled'=>0,'revenue'=>[]];
            $channels[$code]['orders']+=$count;
            if($bucket==='cancelled')$channels[$code]['cancelled']+=$count;

            $currency=$this->normalizeCurrency((string)($o['currency']??''));
            $includeRevenue=$bucket!=='cancelled'||!$excludeCancelled;
            if($includeRevenue){
                $revenue[$currency]=($revenue[$currency]??0)+$sum;
                $channels[$code]['revenue'][$currency]=($channels[$code]['revenue'][$currency]??0)+$sum;
            }

            if(isset($daily[$day])){
                $daily[$day]['orders']+=$count;
                $daily[$day][$bucket]+=$count;
                $daily[$day]['channel_orders'][$code]=($daily[$day]['channel_orders'][$code]??0)+$count;
            }
        }

        uasort($channels,static fn($a,$b)=>$b['orders']<=>$a['orders']);
        arsort($statuses);
        $this->addDailyPercent($daily);

        $statusCounts=array_values($statuses?:[1]);
        $maxStatus=max(1,(int)max($statusCounts));
        $statusRows=[];
        foreach($statuses as $status=>$count)$statusRows[]=['status'=>$status?:'necunoscut','count'=>$count,'percent'=>max(4,(int)round(($count/$maxStatus)*100))];

        [$topDateWhere,$topParams]=$this->dateWindow('o',$start,$endExclusive);
        $topWhere=['o.remote_deleted=0',$topDateWhere];
        if($channel!==''){$topWhere[]='c.code=?';$topParams[]=$channel;}
        if($excludeCancelled)$topWhere[]="LOWER(REPLACE(o.status,'wc-','')) NOT IN ('cancelled','canceled','returned','refunded','failed')";
        $top=$this->db->prepare('SELECT COALESCE(NULLIF(oi.sku,\'\'),\'—\') sku,oi.name,SUM(oi.qty) qty,COUNT(DISTINCT oi.order_id) orders FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN channels c ON c.id=o.channel_id WHERE '.implode(' AND ',$topWhere).' GROUP BY oi.sku,oi.name ORDER BY qty DESC LIMIT 10');
        $top->execute($topParams);$topProducts=$top->fetchAll();

        $activityDays=min(8,count($daily));
        $activityDaily=$activityDays>0?array_slice(array_values($daily),-$activityDays):[];
        $statusChartMax=1;$channelChartMax=1;
        foreach($activityDaily as $d){
            $statusChartMax=max($statusChartMax,(int)$d['active'],(int)$d['completed'],(int)$d['cancelled'],(int)$d['other']);
            foreach((array)$d['channel_orders'] as $count)$channelChartMax=max($channelChartMax,(int)$count);
        }
        $chartChannels=[];
        foreach(array_slice(array_values($channels),0,6) as $c)$chartChannels[]=['code'=>$c['code'],'name'=>$c['name']];

        $monthStart=$today->modify('first day of this month');
        $monthEnd=$today->modify('+1 day');
        $previousMonthStart=$monthStart->modify('-1 month');
        $elapsedMonthDays=(int)$monthStart->diff($monthEnd)->format('%a');
        $previousMonthEnd=$previousMonthStart->modify('+'.$elapsedMonthDays.' days');
        if($previousMonthEnd>$monthStart)$previousMonthEnd=$monthStart;

        $yearStart=$today->setDate((int)$today->format('Y'),1,1);
        $yearEnd=$today->modify('+1 day');
        $previousYearStart=$yearStart->modify('-1 year');
        $elapsedYearDays=(int)$yearStart->diff($yearEnd)->format('%a');
        $previousYearEnd=$previousYearStart->modify('+'.$elapsedYearDays.' days');
        if($previousYearEnd>$yearStart)$previousYearEnd=$yearStart;

        $lastSyncAt=$this->lastSyncAt($channel);
        $lastUpdatedAt=$lastSyncAt!==''?'':$this->lastUpdatedAt($channel);

        return [
            'days'=>$days,
            'channel'=>$channel,
            'start'=>$start->format('Y-m-d'),
            'end'=>$today->format('Y-m-d'),
            'total_orders'=>$total,
            'completed'=>$completed,
            'active'=>$active,
            'cancelled'=>$cancelled,
            'other'=>$other,
            'cancellation_rate'=>$total>0?round(($cancelled/$total)*100,1):0.0,
            'revenue'=>$revenue,
            'daily'=>array_values($daily),
            'activity_daily'=>$activityDaily,
            'activity_days'=>$activityDays,
            'status_chart_max'=>$statusChartMax,
            'channel_chart_max'=>$channelChartMax,
            'chart_channels'=>$chartChannels,
            'channels'=>array_values($channels),
            'statuses'=>$statusRows,
            'top_products'=>$topProducts,
            'avg_orders_per_day'=>$days>0?round($total/$days,1):0.0,
            'exclude_cancelled'=>$excludeCancelled,
            'comparison_month'=>$this->comparison($monthStart,$monthEnd,$previousMonthStart,$previousMonthEnd,$channel,$excludeCancelled),
            'comparison_year'=>$this->comparison($yearStart,$yearEnd,$previousYearStart,$previousYearEnd,$channel,$excludeCancelled),
            'last_updated_at'=>$lastUpdatedAt,
            'last_sync_at'=>$lastSyncAt,
        ];
    }

    /**
     * Lightweight dataset used by Dashboard. It deliberately avoids top products,
     * month/year comparisons, channel breakdowns and sync-log lookups.
     */
    public function dashboardReport(int $days=14): array {
        $days=max(7,min(90,$days));
        $today=new DateTimeImmutable('today');
        $start=$today->modify('-'.($days-1).' days');
        $endExclusive=$today->modify('+1 day');
        $excludeCancelled=$this->settings->bool('module.statistics.exclude_cancelled',true);
        $daily=$this->emptyDaily($start,$days);

        [$dateWhere,$params]=$this->dateWindow('o',$start,$endExclusive);
        $sql='SELECT DATE(COALESCE(o.ordered_at,o.created_at)) order_day,o.status,o.currency,COUNT(*) order_count,SUM(o.total) order_total '
            .'FROM orders o WHERE o.remote_deleted=0 AND '.$dateWhere
            .' GROUP BY DATE(COALESCE(o.ordered_at,o.created_at)),o.status,o.currency';
        $st=$this->db->prepare($sql);$st->execute($params);

        $revenue=[];$total=0;$completed=0;$active=0;$cancelled=0;$other=0;
        while($row=$st->fetch()){
            $count=(int)$row['order_count'];
            $sum=(float)$row['order_total'];
            $bucket=$this->statusBucket($this->normalizeStatus((string)$row['status']));
            $total+=$count;
            if($bucket==='cancelled')$cancelled+=$count;
            elseif($bucket==='completed')$completed+=$count;
            elseif($bucket==='active')$active+=$count;
            else $other+=$count;
            if($bucket!=='cancelled'||!$excludeCancelled){
                $currency=$this->normalizeCurrency((string)($row['currency']??''));
                $revenue[$currency]=($revenue[$currency]??0)+$sum;
            }
            $day=(string)$row['order_day'];
            if(isset($daily[$day])){
                $daily[$day]['orders']+=$count;
                $daily[$day][$bucket]+=$count;
            }
        }
        $this->addDailyPercent($daily);

        return [
            'days'=>$days,
            'total_orders'=>$total,
            'completed'=>$completed,
            'active'=>$active,
            'cancelled'=>$cancelled,
            'other'=>$other,
            'cancellation_rate'=>$total>0?round(($cancelled/$total)*100,1):0.0,
            'revenue'=>$revenue,
            'daily'=>array_values($daily),
            'avg_orders_per_day'=>round($total/$days,1),
        ];
    }

    private function comparison(DateTimeImmutable $currentStart,DateTimeImmutable $currentEnd,DateTimeImmutable $previousStart,DateTimeImmutable $previousEnd,string $channel,bool $excludeCancelled): array {
        $current=$this->periodTotals($currentStart,$currentEnd,$channel,$excludeCancelled);
        $previous=$this->periodTotals($previousStart,$previousEnd,$channel,$excludeCancelled);
        return [
            'current'=>$current,
            'previous'=>$previous,
            'change_percent'=>$this->percentChange((int)$current['orders'],(int)$previous['orders']),
            'current_start'=>$currentStart->format('Y-m-d'),
            'current_end'=>$currentEnd->modify('-1 day')->format('Y-m-d'),
            'previous_start'=>$previousStart->format('Y-m-d'),
            'previous_end'=>$previousEnd->modify('-1 day')->format('Y-m-d'),
        ];
    }

    private function periodTotals(DateTimeImmutable $start,DateTimeImmutable $end,string $channel,bool $excludeCancelled): array {
        [$dateWhere,$params]=$this->dateWindow('o',$start,$end);
        $where=['o.remote_deleted=0',$dateWhere];
        if($channel!==''){$where[]='c.code=?';$params[]=$channel;}
        $st=$this->db->prepare('SELECT o.status,o.currency,COUNT(*) order_count,SUM(o.total) order_total FROM orders o JOIN channels c ON c.id=o.channel_id WHERE '.implode(' AND ',$where).' GROUP BY o.status,o.currency');
        $st->execute($params);
        $orders=0;$revenue=[];
        while($row=$st->fetch()){
            $count=(int)$row['order_count'];
            $orders+=$count;
            $bucket=$this->statusBucket($this->normalizeStatus((string)$row['status']));
            if($excludeCancelled&&$bucket==='cancelled')continue;
            $currency=$this->normalizeCurrency((string)($row['currency']??''));
            $revenue[$currency]=($revenue[$currency]??0)+(float)$row['order_total'];
        }
        ksort($revenue);
        return ['orders'=>$orders,'revenue'=>$revenue];
    }

    private function emptyDaily(DateTimeImmutable $start,int $days): array {
        $daily=[];
        for($i=0;$i<$days;$i++){
            $date=$start->modify('+'.$i.' days');$d=$date->format('Y-m-d');
            $daily[$d]=[
                'date'=>$d,
                'label'=>$date->format('d.m'),
                'orders'=>0,
                'active'=>0,
                'completed'=>0,
                'cancelled'=>0,
                'other'=>0,
                'channel_orders'=>[],
            ];
        }
        return $daily;
    }

    private function addDailyPercent(array &$daily): void {
        $counts=[];
        foreach($daily as $row)$counts[]=(int)$row['orders'];
        $maxDaily=max(1,(int)max($counts?:[0]));
        foreach($daily as &$row)$row['percent']=$row['orders']>0?max(6,(int)round(($row['orders']/$maxDaily)*100)):0;
        unset($row);
    }

    private function dateWindow(string $alias,DateTimeImmutable $start,DateTimeImmutable $end): array {
        $from=$start->format('Y-m-d 00:00:00');
        $to=$end->format('Y-m-d 00:00:00');
        $sql="(({$alias}.ordered_at IS NOT NULL AND {$alias}.ordered_at>=? AND {$alias}.ordered_at<?) OR ({$alias}.ordered_at IS NULL AND {$alias}.created_at>=? AND {$alias}.created_at<?))";
        return [$sql,[$from,$to,$from,$to]];
    }

    private function normalizeCurrency(string $currency): string {
        $currency=strtoupper(trim($currency));
        return $currency!==''?$currency:'RON';
    }

    private function lastSyncAt(string $channel): string {
        $actions="('sync','order_sync','auto_order_sync')";
        if($channel===''){
            $value=$this->db->query("SELECT MAX(created_at) FROM sync_logs WHERE level='success' AND action IN ".$actions)->fetchColumn();
            return (string)($value?:'');
        }
        $st=$this->db->prepare("SELECT MAX(l.created_at) FROM sync_logs l JOIN channels c ON c.id=l.channel_id WHERE l.level='success' AND l.action IN ".$actions." AND c.code=?");
        $st->execute([$channel]);
        return (string)($st->fetchColumn()?:'');
    }

    private function lastUpdatedAt(string $channel): string {
        if($channel===''){
            $value=$this->db->query('SELECT MAX(updated_at) FROM orders WHERE remote_deleted=0')->fetchColumn();
            return (string)($value?:'');
        }
        $st=$this->db->prepare('SELECT MAX(o.updated_at) FROM orders o JOIN channels c ON c.id=o.channel_id WHERE o.remote_deleted=0 AND c.code=?');
        $st->execute([$channel]);
        return (string)($st->fetchColumn()?:'');
    }

    private function percentChange(int $current,int $previous): ?float {
        if($previous===0)return $current===0?0.0:null;
        return round((($current-$previous)/$previous)*100,1);
    }

    private function normalizeStatus(string $status): string {
        $status=strtolower(trim($status));
        if(str_starts_with($status,'wc-'))$status=substr($status,3);
        return $status!==''?$status:'necunoscut';
    }

    private function statusBucket(string $status): string {
        static $cancelled=['cancelled'=>true,'canceled'=>true,'returned'=>true,'refunded'=>true,'failed'=>true];
        static $completed=['completed'=>true,'delivered'=>true,'finalized'=>true,'finished'=>true];
        static $active=['new'=>true,'pending'=>true,'pending-payment'=>true,'processing'=>true,'on-hold'=>true,'prepared'=>true,'checkout-draft'=>true];
        if(isset($cancelled[$status]))return 'cancelled';
        if(isset($completed[$status]))return 'completed';
        if(isset($active[$status]))return 'active';
        return 'other';
    }
}
