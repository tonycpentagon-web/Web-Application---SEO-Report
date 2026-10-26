<?php
namespace App\Services;

use App\Models\ReportingPeriod;

/** Reporting evidence only. Filenames, PDF properties and upload timestamps are never period declarations. */
class SourceReportingPeriod
{
    private const MONTHS = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];

    public function detect(iterable $pieces, ReportingPeriod $p): array
    {
        $candidates=[];$ignored=[];
        $date='(?:[A-Za-z]{3,9}\s*\d{1,2},?\s*20\d{2}|\d{1,2}\s*[A-Za-z]{3,9}\s*20\d{2}|20\d{2}-\d{2}-\d{2})';
        $pattern='/(?<start>'.$date.')\s*(?:to|[-\x{2013}\x{2014}])\s*(?<end>'.$date.')/iu';
        foreach($pieces as $piece){
            if(!in_array($piece->content_type,['text','worksheet_text','paragraph','heading','ocr_text']))continue;
            $text=str_replace("\u{00a0}",' ',$piece->original_text??'');
            $lines=preg_split('/\R/u',$text);
            $method=$piece->content_type==='ocr_text'||$piece->confidence_status==='ocr'?'ocr':'native';
            $reliableNative=$method==='native'&&!in_array($piece->confidence_status,['uncertain','low','inferred','ambiguous']);
            $location=is_string($piece->source_location)?json_decode($piece->source_location,true):($piece->source_location??[]);
            foreach($lines as $index=>$line){
                $previous=$index>0?$lines[$index-1]:'';
                $context=$previous.' '.$line;
                $unrelated=preg_match('/\b(?:created|creation|uploaded|upload date|generated (?:on|at)|printed|downloaded|captured|screenshot (?:date|taken|timestamp)|timestamp|chart(?: date| range| axis)?|x.axis|daily trend|activity date|task date|comparison|previous period|prior period)\b/iu',$context);
                if(preg_match_all($pattern,$line,$matches,PREG_SET_ORDER))foreach($matches as $m){
                    $start=$this->date($m['start']);$end=$this->date($m['end']);
                    if(!$start||!$end||$start>$end)continue;
                    $remaining=preg_replace('/\barrow_(?:drop_down|drop_up)\b/iu','',preg_replace($pattern,'',$line));
                    $standalone=trim($remaining," \t:;,()[]")==='';
                    $labelled=(bool)preg_match('/(?:report(?:ing)?\s*(?:period|date|range)|date range|period covered|coverage|date selector)/iu',$context);
                    $entry=['start'=>$start,'end'=>$end,'text'=>trim($line),'location'=>[...($location??[]),'line'=>$index+1],'content_id'=>$piece->id??null,'method'=>$method,'role'=>'report_period','basis'=>$labelled?'explicit_period_header':'standalone_header_range'];
                    if($unrelated||(!$labelled&&!$standalone)){$entry['role']='unrelated_context';$entry['basis']='Not a reporting-period declaration';$ignored[]=$entry;continue;}
                    $entry['reliable']=$reliableNative&&($labelled||$index<30);
                    $candidates[]=$entry;
                }
                if($unrelated)continue;
                $monthPattern=implode('|',array_map(fn($v)=>implode('\s*',str_split($v)),['JANUARY','JAN','FEBRUARY','FEB','MARCH','MAR','APRIL','APR','MAY','JUNE','JUN','JULY','JUL','AUGUST','AUG','SEPTEMBER','SEPT','SEP','OCTOBER','OCT','NOVEMBER','NOV','DECEMBER','DEC']));
                if(preg_match('/(?:REPORT\s*[-:\x{2013}\x{2014}]?\s*|REPORTING\s+MONTH\s*:\s*)('.$monthPattern.')[\s\x{2019}\x{2018}\x{0027}_-]*(2\s*0\s*\d\s*\d|\d\s*\d)(?!\d)/iu',$line,$m)){
                    $month=self::MONTHS[strtolower(substr(preg_replace('/\s+/','',$m[1]),0,3))];$digits=preg_replace('/\s+/','',$m[2]);$year=(int)(strlen($digits)===2?'20'.$digits:$digits);
                    $start=sprintf('%04d-%02d-01',$year,$month);$end=date('Y-m-t',strtotime($start));
                    $candidates[]=['start'=>$start,'end'=>$end,'text'=>trim($line),'location'=>[...($location??[]),'line'=>$index+1],'content_id'=>$piece->id??null,'method'=>$method,'role'=>'report_period','basis'=>'explicit_month_title','reliable'=>$reliableNative];
                }
            }
        }
        $native=array_values(array_filter($candidates,fn($v)=>$v['reliable']));
        $ranges=array_values(array_filter($native,fn($v)=>$v['basis']!=='explicit_month_title'));
        $selected=$ranges?:$native;
        $start=$p->start_date->format('Y-m-d');$end=$p->end_date->format('Y-m-d');
        $outside=collect($selected)->contains(fn($v)=>$v['start']<$start||$v['end']>$end);
        // A contradictory report title is also material, even when another header has matching dates.
        $outside=$outside||collect($native)->where('basis','explicit_month_title')->contains(fn($v)=>$v['start']<$start||$v['end']>$end);
        $partial=$selected&&!$outside&&collect($selected)->contains(fn($v)=>$v['start']!==$start||$v['end']!==$end);
        $status=$outside?'mismatch':($partial?'partial':($selected?'exact':'uncertain'));
        $observed=$selected?:$candidates;
        $observedRanges=collect($observed)->map(fn($v)=>['start'=>$v['start'],'end'=>$v['end']])->unique(fn($v)=>json_encode($v))->values()->all();
        $missing=[];
        if ($observedRanges) {
            for ($day=$p->start_date->copy();$day->lte($p->end_date);$day->addDay()) {
                $iso=$day->format('Y-m-d');
                if (!collect($observedRanges)->contains(fn($r)=>$r['start']<=$iso&&$r['end']>=$iso)) $missing[]=$iso;
            }
        }
        return ['status'=>$status,'known'=>(bool)$selected,'matches'=>$status==='exact','requires_confirmation'=>in_array($status,['partial','uncertain']),
            'candidate_outside_period'=>!$selected&&collect($candidates)->contains(fn($v)=>$v['start']<$start||$v['end']>$end),
            'basis'=>$selected?($ranges?'report_header_range':'native_month_title'):($candidates?'uncertain_ocr_or_context':'not_reported'),
            'uncovered_dates'=>$missing,'ranges'=>$observedRanges,'evidence'=>$candidates,'ignored_dates'=>$ignored,'selected_period'=>['start'=>$start,'end'=>$end]];
    }

    private function date(string $text): ?string
    {
        if(preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/',trim($text),$m))return checkdate((int)$m[2],(int)$m[3],(int)$m[1])?$m[0]:null;
        if(preg_match('/^([a-z]{3,9})\s*(\d{1,2}),?\s*(20\d{2})$/iu',trim($text),$us)) $text=$us[2].' '.$us[1].' '.$us[3];
        if(!preg_match('/^(\d{1,2})\s*([a-z]{3,9})\s*(20\d{2})$/iu',trim($text),$m))return null;
        $month=self::MONTHS[strtolower(substr($m[2],0,3))]??null;
        return $month&&checkdate($month,(int)$m[1],(int)$m[3])?sprintf('%04d-%02d-%02d',$m[3],$month,$m[1]):null;
    }
}
